<?php
declare(strict_types=1);

/**
 * Оптовая загрузка данных из JSON (журнал 2026-07-15, шаг 3/5 —
 * автоматизация заливки). Формат: {"table_name": [{...}, ...]} —
 * человеческие названия в voc_/link_-полях (не id), вложенные массивы
 * под именем дочерней таблицы — дерево dep_ вместо системы ссылок
 * (родителя физически ещё не существует до вставки, вложенность файла
 * решает это без выдуманных «условных id»).
 *
 * Запуск: php tools_bulk_import.php <путь к JSON> [--dry-run]
 *
 * --dry-run: та же логика записи, в реальной транзакции с ROLLBACK в
 * конце — НЕ отдельный, параллельный путь валидации (§15.8, не плодить
 * абстракцию/путь там, где хватает существующего конвейера — record_save
 * вызывается один в один, просто ничего не остаётся в базе).
 *
 * ВНИМАНИЕ, не скрываем: auto_increment у InnoDB не откатывается
 * транзакцией даже при ROLLBACK — dry-run не портит данные, но
 * «сжигает» несколько номеров id у следующей настоящей вставки.
 * Не критично функционально, но нечестно было бы молчать об этом.
 */

require 'config.php';
require 'db.php';
require 'core.php';
require 'helpers.php';

$path    = $argv[1] ?? null;
$dry_run = in_array('--dry-run', $argv, true);

if ($path === null || !is_file($path)) {
    fwrite(STDERR, "Использование: php tools_bulk_import.php <файл.json> [--dry-run]\n");
    exit(1);
}

$data = json_decode((string) file_get_contents($path), true);
if (!is_array($data)) {
    fwrite(STDERR, 'Не удалось разобрать JSON: ' . json_last_error_msg() . "\n");
    exit(1);
}

$cfg = config()['db'];
$db_connection = mysqli_connect($cfg['host'], $cfg['user'], $cfg['password'], $cfg['name']);
if ($db_connection === false) {
    fwrite(STDERR, "Нет соединения с БД\n");
    exit(1);
}
mysqli_set_charset($db_connection, 'utf8mb4');

$snapshot = snapshot_init($db_connection, config()['application']);
if ($snapshot === null) {
    fwrite(STDERR, 'Снапшот недоступен: ' . (snapshot_last_error() ?? '?') . "\n");
    exit(1);
}

/**
 * Найти имя структурного поля-родителя (dep_<parent>) в таблице.
 * Одна таблица — максимум один родитель в этом дереве (§16.1: dep_
 * строго одно дерево, без множественных родителей) — первое найденное
 * и есть единственное.
 */
function bulk_import_dep_field(array $snapshot, string $table): ?string
{
    foreach ($snapshot['structure']['tables'][$table]['fields'] ?? [] as $field_name => $schema) {
        if (($schema['kind'] ?? '') === 'structural' && str_starts_with($field_name, 'dep_')) {
            return $field_name;
        }
    }
    return null;
}

/**
 * Разобрать один объект записи на entity-поля (для $input) и дочерние
 * таблицы (вложенные массивы) — по факту наличия такой таблицы в
 * структуре, не по префиксу имени ключа (§1 — не гадать по имени).
 * Неизвестный ключ — не молчим, помечаем для явного отказа выше.
 */
function bulk_import_split_record(array $snapshot, string $table, array $record): array
{
    $fields   = [];
    $children = [];
    $unknown  = [];
    $known    = $snapshot['structure']['tables'][$table]['fields'] ?? [];

    foreach ($record as $key => $value) {
        if (isset($known[$key]) && ($known[$key]['kind'] ?? '') === 'entity_field') {
            $fields[$key] = $value;
            continue;
        }
        if (isset($snapshot['structure']['tables'][$key]) && is_array($value)) {
            $children[$key] = $value;
            continue;
        }
        $unknown[] = (string) $key;
    }

    return [$fields, $children, $unknown];
}

/**
 * Человеческие значения voc_/link_-полей → id, через lookup_id_by_label
 * (журнал 07-15, шаг 1) — та же карта model.dictionaries, что и вся
 * остальная система. Прочие поля — как есть, validate внутри
 * record_save разберётся сам по паспорту сущности.
 */
function bulk_import_resolve_fields(
    mysqli $db_connection,
    array $snapshot,
    array $fields,
    array &$errors
): array {
    $resolved = [];
    foreach ($fields as $field_name => $value) {
        $dict = $snapshot['model']['dictionaries'][$field_name] ?? null;
        if ($dict !== null && is_string($value)) {
            $found = lookup_id_by_label($db_connection, $dict, $value);
            if (!$found['ok']) {
                $errors[] = "$field_name: " . $found['error'];
                continue;
            }
            $resolved[$field_name] = (string) $found['id'];
            continue;
        }
        $resolved[$field_name] = $value;
    }

    return $resolved;
}

/**
 * Рекурсивная вставка одной записи + её детей. $parent_id — null на
 * корне дерева. Отказ на любом уровне — дети этой ветки не трогаются:
 * родителя нет, вставлять в никуда нечестно, даже если бы формально
 * получилось (§9 — доверенный канал несёт факт, не догадку).
 */
function bulk_import_insert(
    mysqli $db_connection,
    array $snapshot,
    string $table,
    array $record,
    ?int $parent_id,
    array &$stats,
    string $path
): void {
    [$fields, $children, $unknown] = bulk_import_split_record($snapshot, $table, $record);

    $errors = [];
    foreach ($unknown as $key) {
        $errors[] = "неизвестное поле/связь: '$key'";
    }

    $resolved = bulk_import_resolve_fields($db_connection, $snapshot, $fields, $errors);

    $structural = [];
    if ($parent_id !== null) {
        $dep_field = bulk_import_dep_field($snapshot, $table);
        if ($dep_field === null) {
            $errors[] = "нет структурного поля-родителя (dep_*) у таблицы '$table'";
        } else {
            $structural[$dep_field] = $parent_id;
        }
    }

    if ($errors !== []) {
        echo "ОТКАЗ  $path: " . implode('; ', $errors) . "\n";
        $stats['fail']++;
        return;
    }

    $result = record_save($db_connection, $snapshot, $table, $resolved, null, $structural);
    if (!$result['ok']) {
        echo "ОТКАЗ  $path: " . implode('; ', $result['errors']) . "\n";
        $stats['fail']++;
        return;
    }

    echo "OK     $path -> id=" . $result['id'] . "\n";
    $stats['ok']++;

    foreach ($children as $child_table => $child_records) {
        foreach ($child_records as $i => $child_record) {
            bulk_import_insert(
                $db_connection, $snapshot, $child_table, $child_record,
                (int) $result['id'], $stats, "$path.$child_table[$i]"
            );
        }
    }
}

$stats = ['ok' => 0, 'fail' => 0];

if ($dry_run) {
    mysqli_begin_transaction($db_connection);
}

foreach ($data as $table => $records) {
    if (!isset($snapshot['structure']['tables'][$table])) {
        echo "ОТКАЗ  $table: неизвестная таблица\n";
        $stats['fail'] += count($records);
        continue;
    }
    foreach ($records as $i => $record) {
        bulk_import_insert($db_connection, $snapshot, $table, $record, null, $stats, "$table" . "[$i]");
    }
}

if ($dry_run) {
    mysqli_rollback($db_connection);
    echo "\n--- DRY RUN: ничего не записано, откат выполнен ---\n";
} else {
    echo "\n--- Готово ---\n";
}

echo "OK: {$stats['ok']}, ОТКАЗ: {$stats['fail']}\n";
exit($stats['fail'] > 0 ? 1 : 0);
