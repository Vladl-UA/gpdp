<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

// sync: 2026-07-12, конфигуратор: инструмент ремонта + ALTER полей (добавление/удаление с защитой данных)

/**
 * GPDP / RNA — конфигуратор БД (v0).
 *
 * Отдельный вход, не часть пользовательского конвейера index.php:
 * другой периметр прав (сегодня — никакого; авторизация — известный,
 * явно названный будущий контур, см. STATE.md), другое назначение —
 * структурные операции, а не CRUD предметных записей.
 *
 * Живой слепок (ARCHITECTURE.md §8, «админ-режим» — третье легальное
 * исключение из запрета на интроспекцию в рантайме, наравне с bootstrap
 * и явной пересборкой): список читает БД напрямую при каждом заходе,
 * без кэш-файла — конструирование не должно зависеть от ручного
 * `rm state/snapshot.php`. Обычный посетитель сайта (index.php) этого
 * не касается — кэш для него живёт как жил.
 *
 * v0 умеет: список таблиц с бейджем «используется как словарь»,
 * создание новой таблицы с первым набором полей одним атомарным actом,
 * удаление таблицы с трёхступенчатым подтверждением.
 * v0 НЕ умеет (сознательно вне охвата): ALTER (добавление полей к уже
 * существующей таблице), reparent, паспорт-словари (§16 уровень 2),
 * составные сущности (date и подобные) — появятся отдельными решениями.
 */

require 'config.php';
require 'db.php';
require 'core.php';
require 'helpers.php';
require 'render.php';

$db_connection = admin_db_connect();

// --- (auth: контур не утверждён — см. STATE.md; серьёзный периметр --------
//      прав по таблицам заявлен как обязательное будущее требование) ------

// ============================================================================
// Валидация спецификации — до единого DDL-запроса (ARCHITECTURE.md §9:
// подсказки из request не факты, каждая сверяется прежде, чем что-то
// сделать; здесь сверка — с уже известной моделью И со здравым видом имени).
// ============================================================================

/** Именная часть: то, что admin реально печатает — без префикса сущности. */
function configurator_identifier_valid(string $s): bool
{
    return (bool) preg_match('/^[a-z][a-z0-9_]{0,58}$/', $s);
}

/**
 * Разбирает и проверяет спецификацию новой таблицы. Два пути создания —
 * не хранимый "тип таблицы" (реестр остаётся без такой колонки), а
 * гарантия контракта уровня 0 (§16) в момент создания, а не понадеявшись
 * на память администратора:
 *
 *   'plain'      — обычная таблица; имя свободное (кроме резерва
 *                  model_/voc_), поля выбираются как обычно.
 *   'voc_simple' — простой словарь уровня 0: admin вводит ТОЛЬКО именную
 *                  часть (без "voc_" — префикс подставляет код, тем же
 *                  приёмом, что уже работает для полей), поля сервер
 *                  строит сам: ровно id + data_name, ничего больше —
 *                  это и есть определение уровня 0, не место для доп.
 *                  атрибутов (появится реальная нужда — это уже полноценная
 *                  сущность-таблица типа `well`, путь 'plain', не словарь).
 *
 * Возврат: ['ok'=>bool, 'errors'=>string[], 'table'=>string,
 *           'table_labels'=>[...], 'fields'=>[ ['column'=>.., 'entity'=>..,
 *           'db_type'=>.., 'labels'=>[...]], ... ], 'has_id'=>bool].
 * Ничего не пишет в БД — чистая проверка.
 */
function configurator_validate_spec(array $input, array $live_structure): array
{
    $errors = [];
    $kind   = (string) ($input['table_kind'] ?? 'plain');

    if ($kind === 'voc_simple') {
        $name_part = trim((string) ($input['dict_name'] ?? ''));
        $table     = configurator_identifier_valid($name_part) ? 'voc_' . $name_part : '';

        if (!configurator_identifier_valid($name_part)) {
            $errors[] = 'Имя словаря: только строчные латинские буквы, цифры, "_", начинается с буквы (без префикса — voc_ подставится сам).';
        } elseif (isset($live_structure['tables'][$table])) {
            $errors[] = "Словарь \"$table\" уже существует.";
        }

        $table_short = trim((string) ($input['table_short'] ?? ''));
        $table_full  = trim((string) ($input['table_full'] ?? ''));
        if ($table_short === '' || $table_full === '') {
            $errors[] = 'Подпись словаря (короткая и полная) обязательна.';
        }

        // Уровень 0 определяется РОВНО так — id + data_name, сервер решает
        // сам, форма это поле вообще не спрашивает и не может подменить.
        $fields = [[
            'column'  => 'data_name',
            'entity'  => 'data',
            'db_type' => entities()['data']['db']['default_type'] ?? 'varchar(255)',
            'short'   => 'Имя',
            'full'    => 'Наименование',
        ]];

        return [
            'ok'          => $errors === [],
            'errors'      => $errors,
            'table'       => $table,
            'table_short' => $table_short,
            'table_full'  => $table_full,
            'fields'      => $fields,
            'has_id'      => true,
        ];
    }

    // --- kind === 'dependent': родитель выбирается из ЖИВОГО списка -------
    // (не печатается — тот же приём, что voc_pick: правильный ответ всегда
    // один из уже известных), dep_<parent> генерирует сервер. rel_main —
    // явный флажок (журнал 07-08: связь принадлежности досье — семантическое
    // решение админа, не механическое следствие выбора родителя).
    // Дальше ветка сливается с 'plain': имя, подписи, поля — общие.
    $structural_columns = [];
    if ($kind === 'dependent') {
        $parent = trim((string) ($input['parent_table'] ?? ''));
        if ($parent === '' || !isset($live_structure['tables'][$parent])) {
            $errors[] = 'Родительская таблица не выбрана или не существует.';
        } else {
            $dep_column = 'dep_' . $parent;
            if (strlen($dep_column) > 64) {
                $errors[] = "Колонка \"$dep_column\" длиннее 64 символов (лимит MySQL).";
            }
            $structural_columns[] = $dep_column;
        }
        if (!empty($input['add_rel_main'])) {
            $structural_columns[] = 'rel_main';
        }
    }

    // --- kind === 'plain' | 'dependent' (общая часть) ---------------------
    $table = trim((string) ($input['table_name'] ?? ''));

    if ($kind === 'dependent' && $table !== '' && $table === ($input['parent_table'] ?? '')) {
        $errors[] = 'Таблица не может быть родителем самой себе.';
    }

    if (!configurator_identifier_valid($table)) {
        $errors[] = 'Имя таблицы: только строчные латинские буквы, цифры, "_", начинается с буквы.';
    } elseif (str_starts_with($table, SYSTEM_TABLE_PREFIX)) {
        $errors[] = "Префикс \"" . SYSTEM_TABLE_PREFIX . "\" зарезервирован ядром (служебные таблицы модели).";
    } elseif (str_starts_with($table, 'voc_')) {
        $errors[] = 'Префикс "voc_" зарезервирован для словарей — создайте через режим "Словарь" выше, '
                  . 'это гарантирует обязательное поле data_name (§16, уровень 0).';
    } elseif (isset($live_structure['tables'][$table])) {
        $errors[] = "Таблица \"$table\" уже существует.";
    }

    $table_short = trim((string) ($input['table_short'] ?? ''));
    $table_full  = trim((string) ($input['table_full'] ?? ''));
    if ($table_short === '' || $table_full === '') {
        $errors[] = 'Подпись таблицы (короткая и полная) обязательна.';
    }

    $known_entities = entities();
    $fields         = [];
    // id — не опция формы, а гарантия сервера для ЛЮБОГО режима: без него
    // весь конвейер (record_fetch/record_save, WHERE id = ?) сломан.
    // 'plain' раньше мог родить таблицу без id, если админ не отметил
    // строку в списке полей — та же дыра, что вчера чинили для
    // 'dependent', закрыта единообразно (журнал 07-09).
    $has_id = true;
    $seen_columns   = [];

    $raw_fields = $input['fields'] ?? [];
    if (!is_array($raw_fields) || $raw_fields === []) {
        $errors[] = 'Нужно хотя бы одно поле.';
    }

    foreach ($raw_fields as $i => $raw_field) {
        if (($raw_field['entity'] ?? '') === 'id') {
            continue; // id гарантирован сервером; повтор игнорируем
        }
        $parsed = configurator_parse_field($raw_field, $known_entities, $live_structure);
        if (!$parsed['ok']) {
            foreach ($parsed['errors'] as $e) {
                $errors[] = "Поле #" . ($i + 1) . ": " . $e;
            }
            continue;
        }
        $column = $parsed['field']['column'];
        if (isset($seen_columns[$column])) {
            $errors[] = "Поле #" . ($i + 1) . ": колонка \"$column\" повторяется в спецификации.";
            continue;
        }
        $seen_columns[$column] = true;
        $fields[] = $parsed['field'];
    }

    return [
        'ok'                 => $errors === [],
        'errors'             => $errors,
        'table'              => $table,
        'table_short'        => $table_short,
        'table_full'         => $table_full,
        'fields'             => $fields,
        'has_id'             => $has_id,
        'structural_columns' => $structural_columns,
    ];
}

/**
 * Разбор и проверка ОДНОГО поля из формы (общий для создания таблицы и
 * добавления поля через ALTER). Возвращает ['ok', 'errors', 'field'].
 * field при ok: ['column','entity','db_type','short','full'].
 * 'id' — синтетический структурный выбор: ok=true, field=null (в реестр
 * не пишется).
 */
function configurator_parse_field(array $raw_field, array $known_entities, array $live_structure): array
{
    $errors        = [];
    $entity_choice = (string) ($raw_field['entity'] ?? '');
    $f_short       = trim((string) ($raw_field['short'] ?? ''));
    $f_full        = trim((string) ($raw_field['full'] ?? ''));

    if ($entity_choice === 'id') {
        return ['ok' => true, 'errors' => [], 'field' => null]; // структурный, без записи
    }
    if (!isset($known_entities[$entity_choice])) {
        return ['ok' => false, 'errors' => ["неизвестный тип \"$entity_choice\"."], 'field' => null];
    }

    $name_part = $entity_choice === 'voc'
        ? trim((string) ($raw_field['voc_pick'] ?? ''))
        : trim((string) ($raw_field['name'] ?? ''));

    if (!configurator_identifier_valid($name_part)) {
        return ['ok' => false, 'errors' => [$entity_choice === 'voc'
            ? 'словарь не выбран.'
            : 'именная часть — латиница/цифры/"_", с буквы.'], 'field' => null];
    }

    // link_: имя поля свободное (как обычное), а не выбор из списка —
    // в отличие от voc_, где имя = имя целевой таблицы. Цель для link_
    // указывается ОТДЕЛЬНО (журнал 2026-07-12: имя поля и адрес цели —
    // разные вещи, ровно затем и вводили link_).
    $link_target = null;
    if ($entity_choice === 'link') {
        $link_target = trim((string) ($raw_field['link_target'] ?? ''));
        if ($link_target === '' || !isset($live_structure['tables'][$link_target])) {
            return ['ok' => false, 'errors' => ['цель ссылки не выбрана или не существует.'], 'field' => null];
        }
    }

    $column = $entity_choice . '_' . $name_part;

    if (strlen($column) > 64) {
        $errors[] = "имя колонки \"$column\" длиннее 64 символов (лимит MySQL).";
    }
    if (in_array($column, STRUCTURAL_FIELD_NAMES, true) || str_starts_with($column, 'dep_')) {
        $errors[] = "\"$column\" — зарезервированное структурное имя.";
    }
    if ($f_short === '' || $f_full === '') {
        $errors[] = "короткая и полная подпись обязательны.";
    }
    if ($entity_choice === 'voc') {
        $dict_table  = $column;
        $dict_fields = $live_structure['tables'][$dict_table]['fields'] ?? null;
        if ($dict_fields === null) {
            $errors[] = "словаря \"$dict_table\" не существует. Создайте его первым через режим \"Словарь\".";
        } elseif (!isset($dict_fields['data_name'])) {
            $errors[] = "таблица \"$dict_table\" существует, но без поля data_name — не может быть словарём уровня 0.";
        }
    }

    if ($errors !== []) {
        return ['ok' => false, 'errors' => $errors, 'field' => null];
    }
    return ['ok' => true, 'errors' => [], 'field' => [
        'column'      => $column,
        'entity'      => $entity_choice,
        'db_type'     => $known_entities[$entity_choice]['db']['default_type'],
        'short'       => $f_short,
        'full'        => $f_full,
        'link_target' => $link_target, // null для всех, кроме link_
    ]];
}

/**
 * Записать адрес link_-поля в model_links (журнал 2026-07-12). Глобально
 * по имени поля (§16.1, «одно имя = один смысл») — простой INSERT, не
 * ON DUPLICATE KEY: повторная регистрация того же имени с ДРУГОЙ целью
 * обязана упасть громко (первичный ключ = data_element), а не молча
 * переписать адрес — «не переопределяется после создания» из решения.
 *
 * Возврат — точный результат db_execute (журнал 07-14): раньше был
 * голый bool, вызывающий код брал mysqli_error($db_connection) при
 * провале — после переноса на db_execute() эта ошибка уже спрятана
 * внутри, снаружи текст был бы неточным (или вовсе не тем запросом).
 */
function configurator_register_link(mysqli $db_connection, string $column, string $target): array
{
    return db_execute(
        $db_connection,
        'INSERT INTO `model_links` (data_element, data_target_table) VALUES (?, ?)',
        'ss',
        [$column, $target]
    );
}

// ============================================================================
// Исполнение — единственное место, где спецификация становится DDL.
// Порядок буквально твой «стоп-файл»: lock → транзакция → пересборка →
// unlock. lock — уже существующий schema.lock, ничего нового не заведено.
// ============================================================================

function configurator_create_table(mysqli $db_connection, array $spec, array $application): array
{
    if (!snapshot_lock_acquire('configurator', 'Создание таблицы: ' . $spec['table'])) {
        return ['ok' => false, 'errors' => ['Схема уже заблокирована другой структурной операцией.']];
    }

    try {
        $columns_sql = [];
        if ($spec['has_id']) {
            $columns_sql[] = '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        }
        // Структурные колонки (dep_<parent>, rel_main) — тип детерминирован
        // (FK на суррогатный id), NULL сознательно: обязательность родителя
        // и FK-constraint — отложенная защита связей (журнал 07-06), не
        // протаскивается впрок через DDL. В реестр НЕ регистрируются —
        // структурные поля живут без записей (§17), как id.
        foreach ($spec['structural_columns'] ?? [] as $structural_column) {
            $columns_sql[] = '`' . $structural_column . '` INT UNSIGNED NULL';
        }
        foreach ($spec['fields'] as $field) {
            $columns_sql[] = '`' . $field['column'] . '` ' . $field['db_type'] . ' NULL';
        }

        $table = $spec['table'];
        $sql   = "CREATE TABLE `$table` (" . implode(', ', $columns_sql) . ") "
               . "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        $create = db_execute($db_connection, $sql);
        if (!$create['ok']) {
            return ['ok' => false, 'errors' => ['DDL: ' . $create['error']]];
        }

        // Регистрация таблицы в реестре + подпись.
        $table_reg = db_execute($db_connection,
            'INSERT INTO `' . MODEL_REGISTRY_TABLE . '` (data_kind, data_owner, data_element, active) '
            . "VALUES ('table', NULL, ?, 1)", 's', [$table]);

        db_execute($db_connection,
            'INSERT INTO `' . MODEL_LABELS_TABLE . '` (dep_model_registry, data_short, data_full) VALUES (?, ?, ?)',
            'iss', [$table_reg['id'], $spec['table_short'], $spec['table_full']]);

        // Регистрация каждого предметного поля (id — структурный, без записи).
        foreach ($spec['fields'] as $field) {
            $field_reg = db_execute($db_connection,
                'INSERT INTO `' . MODEL_REGISTRY_TABLE . '` (data_kind, data_owner, data_element, active) '
                . "VALUES ('field', ?, ?, 1)", 'ss', [$table, $field['column']]);

            db_execute($db_connection,
                'INSERT INTO `' . MODEL_LABELS_TABLE . '` (dep_model_registry, data_short, data_full) VALUES (?, ?, ?)',
                'iss', [$field_reg['id'], $field['short'], $field['full']]);

            if (($field['link_target'] ?? null) !== null) {
                $link = configurator_register_link($db_connection, $field['column'], $field['link_target']);
                if (!$link['ok']) {
                    return ['ok' => false, 'errors' => [
                        "Поле {$field['column']} создано, но адрес link_ не записан: " . $link['error'],
                    ]];
                }
            }
        }

        // Пересборка тем же примитивом, что использует холодный старт,
        // но БЕЗ повторного захвата лока (мы уже внутри него) —
        // snapshot_build() сам по себе лок не трогает.
        $snapshot = snapshot_build($db_connection, $application);
        if ($snapshot === null) {
            return ['ok' => false, 'errors' => [
                'Таблица создана, но snapshot не собрался: ' . (snapshot_last_error() ?? '?')
                . '. Структура БД и реестр в рассинхроне — требуется ручной разбор.',
            ]];
        }
        if (!snapshot_save($snapshot)) {
            return ['ok' => false, 'errors' => ['Snapshot собран, но не сохранён (см. права на state/).']];
        }

        return ['ok' => true, 'errors' => []];
    } finally {
        snapshot_lock_release('configurator');
    }
}

function configurator_delete_table(mysqli $db_connection, string $table, array $application): array
{
    if (!snapshot_lock_acquire('configurator', 'Удаление таблицы: ' . $table)) {
        return ['ok' => false, 'errors' => ['Схема уже заблокирована другой структурной операцией.']];
    }

    try {
        // model_labels чистится каскадом FK (ON DELETE CASCADE, §17).
        db_execute(
            $db_connection,
            'DELETE FROM `' . MODEL_REGISTRY_TABLE . "` WHERE (data_kind='table' AND data_element=?) "
            . "OR (data_kind='field' AND data_owner=?)",
            'ss',
            [$table, $table]
        );

        // DDL — имя таблицы не параметризуется (ограничение SQL, не
        // db_execute): $types='' — прямой запрос без подготовки.
        $drop = db_execute($db_connection, 'DROP TABLE `' . $table . '`');
        if (!$drop['ok']) {
            return ['ok' => false, 'errors' => ['DDL: ' . $drop['error']]];
        }

        $snapshot = snapshot_build($db_connection, $application);
        if ($snapshot === null) {
            return ['ok' => false, 'errors' => ['Таблица удалена, но snapshot не собрался: ' . (snapshot_last_error() ?? '?')]];
        }
        if (!snapshot_save($snapshot)) {
            return ['ok' => false, 'errors' => ['Snapshot собран, но не сохранён.']];
        }

        return ['ok' => true, 'errors' => []];
    } finally {
        snapshot_lock_release('configurator');
    }
}

// ============================================================================
// Починка структуры (инструмент ремонта, журнал 2026-07-11).
// Каждая — под локом, пишет реестр на языке модели, пересобирает снапшот.
// Адреса перепроверяются по ЖИВОЙ структуре: расхождение могло измениться
// с момента показа (человек/другая сессия успели починить) — чиним факт,
// не снимок экрана.
// ============================================================================

/** Общий хвост: пересобрать снапшот после правки реестра. */
function configurator_refresh(mysqli $db_connection, array $application): array
{
    $snapshot = snapshot_build($db_connection, $application);
    if ($snapshot === null) {
        return ['ok' => false, 'errors' => ['Реестр изменён, но snapshot не собрался: ' . (snapshot_last_error() ?? '?')]];
    }
    if (!snapshot_save($snapshot)) {
        return ['ok' => false, 'errors' => ['Snapshot собран, но не сохранён.']];
    }
    return ['ok' => true, 'errors' => []];
}

/** Взять поле-сироту под управление: строка реестра + дефолтная подпись. */
function configurator_adopt_field(mysqli $db_connection, string $table, string $field, array $application): array
{
    if (!snapshot_lock_acquire('configurator', "Регистрация поля: $table.$field")) {
        return ['ok' => false, 'errors' => ['Схема заблокирована другой операцией.']];
    }
    try {
        $live = snapshot_build_structure($db_connection);
        $fschema = $live['tables'][$table]['fields'][$field] ?? null;
        if ($fschema === null || ($fschema['kind'] ?? '') !== 'entity_field') {
            return ['ok' => false, 'errors' => ["Поле $table.$field не существует или структурное."]];
        }
        // Уже зарегистрировано? (могли починить между показом и нажатием)
        $chk = db_select($db_connection, 'SELECT id FROM `' . MODEL_REGISTRY_TABLE
            . "` WHERE data_kind='field' AND data_owner=? AND data_element=? AND active=1", 'ss', [$table, $field]);
        if ($chk !== []) {
            return ['ok' => false, 'errors' => ["Поле $table.$field уже под управлением."]];
        }

        $reg = db_execute($db_connection, 'INSERT INTO `' . MODEL_REGISTRY_TABLE
            . "` (data_kind, data_owner, data_element, active) VALUES ('field', ?, ?, 1)", 'ss', [$table, $field]);

        // Дефолтная подпись = имя поля; осмысленную человек правит в labels.
        db_execute($db_connection, 'INSERT INTO `' . MODEL_LABELS_TABLE
            . '` (dep_model_registry, data_short, data_full) VALUES (?, ?, ?)', 'iss', [$reg['id'], $field, $field]);

        return configurator_refresh($db_connection, $application);
    } finally {
        snapshot_lock_release('configurator');
    }
}

/** Взять таблицу-сироту под управление: строка реестра + дефолтная подпись. */
function configurator_adopt_table(mysqli $db_connection, string $table, array $application): array
{
    if (!snapshot_lock_acquire('configurator', "Регистрация таблицы: $table")) {
        return ['ok' => false, 'errors' => ['Схема заблокирована другой операцией.']];
    }
    try {
        $live = snapshot_build_structure($db_connection);
        if (!isset($live['tables'][$table])) {
            return ['ok' => false, 'errors' => ["Таблица $table не существует."]];
        }
        $reg = db_execute($db_connection, 'INSERT INTO `' . MODEL_REGISTRY_TABLE
            . "` (data_kind, data_owner, data_element, active) VALUES ('table', NULL, ?, 1)", 's', [$table]);

        db_execute($db_connection, 'INSERT INTO `' . MODEL_LABELS_TABLE
            . '` (dep_model_registry, data_short, data_full) VALUES (?, ?, ?)', 'iss', [$reg['id'], $table, $table]);

        return configurator_refresh($db_connection, $application);
    } finally {
        snapshot_lock_release('configurator');
    }
}

/** Убрать строку реестра по id (призрак или лишний дубль). Подпись —
 *  каскадом FK (ON DELETE CASCADE, §17). */
function configurator_drop_registry_row(mysqli $db_connection, int $id, array $application): array
{
    if (!snapshot_lock_acquire('configurator', "Удаление записи реестра: #$id")) {
        return ['ok' => false, 'errors' => ['Схема заблокирована другой операцией.']];
    }
    try {
        $outcome = db_execute($db_connection, 'DELETE FROM `' . MODEL_REGISTRY_TABLE . '` WHERE id = ?', 'i', [$id]);
        if ($outcome['affected_rows'] === 0) {
            return ['ok' => false, 'errors' => ["Записи реестра #$id нет (уже убрана?)."]];
        }
        return configurator_refresh($db_connection, $application);
    } finally {
        snapshot_lock_release('configurator');
    }
}

/** Удалить поле-сироту ИЗ БД (единственное, что трогает данные — с
 *  подтверждением на стороне UI). Только entity-поле, только если оно
 *  действительно вне реестра (иначе это управляемое поле — не сюда). */
function configurator_drop_column(mysqli $db_connection, string $table, string $field, array $application): array
{
    if (!snapshot_lock_acquire('configurator', "Удаление колонки: $table.$field")) {
        return ['ok' => false, 'errors' => ['Схема заблокирована другой операцией.']];
    }
    try {
        $live = snapshot_build_structure($db_connection);
        $fschema = $live['tables'][$table]['fields'][$field] ?? null;
        if ($fschema === null || ($fschema['kind'] ?? '') !== 'entity_field') {
            return ['ok' => false, 'errors' => ["Поле $table.$field не существует или структурное — не удаляю."]];
        }
        // Защита: удаляем только сироту. Под управлением — не наш случай.
        $chk = db_select($db_connection, 'SELECT id FROM `' . MODEL_REGISTRY_TABLE
            . "` WHERE data_kind='field' AND data_owner=? AND data_element=? AND active=1", 'ss', [$table, $field]);
        if ($chk !== []) {
            return ['ok' => false, 'errors' => ["Поле $table.$field под управлением — удаление колонки под управлением здесь не делается."]];
        }
        $drop = db_execute($db_connection, 'ALTER TABLE `' . $table . '` DROP COLUMN `' . $field . '`');
        if (!$drop['ok']) {
            return ['ok' => false, 'errors' => ['DDL: ' . $drop['error']]];
        }
        return configurator_refresh($db_connection, $application);
    } finally {
        snapshot_lock_release('configurator');
    }
}

// ============================================================================
// Правка полей таблицы (ALTER, Волна 2, 2026-07-11). Добавление и
// удаление колонки живой таблицы. Под локом схемы, регистрация в реестре
// разом с DDL (иначе — сирота, которую ловит диагностика). Смена типа —
// сознательно вне охвата (журнал: только внутри группы, только усложнение
// без потери данных — позже).
// ============================================================================

/**
 * Добавить поле в существующую таблицу: ALTER ADD COLUMN + регистрация +
 * подпись. Тип/имя разбираются тем же валидатором, что при создании
 * (configurator_validate_spec на спеке из одного поля) — те же проверки
 * (префикс, длина, резерв, для voc — существование словаря).
 * $field_input — как одно поле формы создания: entity, name/voc_pick,
 * short, full.
 */
function configurator_add_field(mysqli $db_connection, string $table, array $field_input, array $application): array
{
    if (!snapshot_lock_acquire('configurator', "Добавление поля в $table")) {
        return ['ok' => false, 'errors' => ['Схема заблокирована другой операцией.']];
    }
    try {
        $live = snapshot_build_structure($db_connection);
        if (!isset($live['tables'][$table])) {
            return ['ok' => false, 'errors' => ["Таблица $table не существует."]];
        }

        // Разбор поля тем же кирпичом, что валидатор создания (общая
        // configurator_parse_field — не копия проверок).
        $parsed = configurator_parse_field($field_input, entities(), $live);
        if (!$parsed['ok']) {
            return ['ok' => false, 'errors' => $parsed['errors']];
        }
        $field = $parsed['field'];
        if ($field === null) {
            return ['ok' => false, 'errors' => ['Поле не распозналось (id нельзя добавить как поле).']];
        }

        if (isset($live['tables'][$table]['fields'][$field['column']])) {
            return ['ok' => false, 'errors' => ["Поле {$field['column']} в таблице $table уже есть."]];
        }

        $sql = 'ALTER TABLE `' . $table . '` ADD COLUMN `' . $field['column'] . '` '
             . $field['db_type'] . ' NULL';
        $alter = db_execute($db_connection, $sql);
        if (!$alter['ok']) {
            return ['ok' => false, 'errors' => ['DDL: ' . $alter['error']]];
        }

        $reg = db_execute($db_connection, 'INSERT INTO `' . MODEL_REGISTRY_TABLE
            . "` (data_kind, data_owner, data_element, active) VALUES ('field', ?, ?, 1)", 'ss', [$table, $field['column']]);

        db_execute($db_connection, 'INSERT INTO `' . MODEL_LABELS_TABLE
            . '` (dep_model_registry, data_short, data_full) VALUES (?, ?, ?)', 'iss', [$reg['id'], $field['short'], $field['full']]);

        if (($field['link_target'] ?? null) !== null) {
            $link = configurator_register_link($db_connection, $field['column'], $field['link_target']);
            if (!$link['ok']) {
                return ['ok' => false, 'errors' => [
                    "Поле {$field['column']} добавлено, но адрес link_ не записан: " . $link['error'],
                ]];
            }
        }

        return configurator_refresh($db_connection, $application);
    } finally {
        snapshot_lock_release('configurator');
    }
}

/**
 * Удалить поле из таблицы ФИЗИЧЕСКИ (DROP COLUMN) + снять регистрацию.
 * Защита данных: если в колонке есть непустые значения, требуется явный
 * флаг $force (подтверждение инженера «удаляю с данными»). Пустая
 * колонка удаляется без флага. Структурные поля (id/dep_/rel_main) не
 * удаляются. Подпись уходит каскадом FK (§17).
 */
function configurator_drop_field(mysqli $db_connection, string $table, string $field, bool $force, array $application): array
{
    if (!snapshot_lock_acquire('configurator', "Удаление поля $table.$field")) {
        return ['ok' => false, 'errors' => ['Схема заблокирована другой операцией.']];
    }
    try {
        $live = snapshot_build_structure($db_connection);
        $fschema = $live['tables'][$table]['fields'][$field] ?? null;
        if ($fschema === null) {
            return ['ok' => false, 'errors' => ["Поля $table.$field нет."]];
        }
        if (($fschema['kind'] ?? '') !== 'entity_field') {
            return ['ok' => false, 'errors' => ["$table.$field — структурное поле, не удаляется."]];
        }

        // Проверка данных: есть ли непустые значения в колонке.
        $cnt_sql = 'SELECT COUNT(*) AS c FROM `' . $table . '` WHERE `' . $field . '` IS NOT NULL';
        $cnt_rows = db_select($db_connection, $cnt_sql);
        $with_data = (int) ($cnt_rows[0]['c'] ?? 0);
        if ($with_data > 0 && !$force) {
            return ['ok' => false, 'errors' => [
                "В поле $table.$field есть данные ($with_data значений). "
                . "Удаление сотрёт их — подтвердите удаление с данными."
            ]];
        }

        $drop = db_execute($db_connection, 'ALTER TABLE `' . $table . '` DROP COLUMN `' . $field . '`');
        if (!$drop['ok']) {
            return ['ok' => false, 'errors' => ['DDL: ' . $drop['error']]];
        }

        // Снять регистрацию (подпись — каскадом FK).
        db_execute($db_connection, 'DELETE FROM `' . MODEL_REGISTRY_TABLE
            . "` WHERE data_kind='field' AND data_owner=? AND data_element=?", 'ss', [$table, $field]);

        return configurator_refresh($db_connection, $application);
    } finally {
        snapshot_lock_release('configurator');
    }
}

// ============================================================================
// Разбор запроса
// ============================================================================

$caction = (string) ($_POST['_action'] ?? $_GET['_action'] ?? 'list');
$application = config()['application'];

if ($caction === 'create_table' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $live = snapshot_build_structure($db_connection);
    $spec = configurator_validate_spec($_POST, $live);

    if (!$spec['ok']) {
        $msg = implode(' | ', $spec['errors']);
        header('Location: ?_action=new_table&_msg=' . rawurlencode($msg) . '&_ok=0');
        exit;
    }

    $outcome = configurator_create_table($db_connection, $spec, $application);
    $msg     = $outcome['ok'] ? "Таблица \"{$spec['table']}\" создана." : implode(' | ', $outcome['errors']);
    header('Location: ?_action=list&_msg=' . rawurlencode($msg) . '&_ok=' . ($outcome['ok'] ? '1' : '0'));
    exit;
}

if ($caction === 'delete_table' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $table   = (string) ($_POST['table'] ?? '');
    $outcome = configurator_delete_table($db_connection, $table, $application);
    $msg     = $outcome['ok'] ? "Таблица \"$table\" удалена." : implode(' | ', $outcome['errors']);
    header('Location: ?_action=list&_msg=' . rawurlencode($msg) . '&_ok=' . ($outcome['ok'] ? '1' : '0'));
    exit;
}

// Починка структуры (кнопки раздела «Состояние модели»). Все → redirect
// обратно в diagnose (PRG), чтобы человек сразу видел обновлённое
// состояние (расхождение ушло или осталось с причиной).
$repair_actions = ['reg_adopt_field', 'reg_adopt_table', 'reg_drop_ghost', 'reg_drop_dup', 'reg_drop_column'];
if (in_array($caction, $repair_actions, true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $table = (string) ($_POST['table'] ?? '');
    $field = (string) ($_POST['field'] ?? '');
    $id    = (int) ($_POST['id'] ?? 0);

    $outcome = match ($caction) {
        'reg_adopt_field' => configurator_adopt_field($db_connection, $table, $field, $application),
        'reg_adopt_table' => configurator_adopt_table($db_connection, $table, $application),
        'reg_drop_ghost',
        'reg_drop_dup'    => configurator_drop_registry_row($db_connection, $id, $application),
        'reg_drop_column' => configurator_drop_column($db_connection, $table, $field, $application),
    };

    $ok_msg = match ($caction) {
        'reg_adopt_field' => "Поле $table.$field взято под управление (подпись — имя поля, поправьте в «Подписи и словари»).",
        'reg_adopt_table' => "Таблица $table взята под управление.",
        'reg_drop_ghost'  => "Запись реестра #$id убрана.",
        'reg_drop_dup'    => "Лишняя запись реестра #$id убрана.",
        'reg_drop_column' => "Поле $table.$field удалено из базы.",
    };
    $msg = $outcome['ok'] ? $ok_msg : implode(' | ', $outcome['errors']);
    header('Location: ?_action=diagnose&_msg=' . rawurlencode($msg) . '&_ok=' . ($outcome['ok'] ? '1' : '0'));
    exit;
}

// Правка полей таблицы (ALTER, Волна 2). PRG назад в форму правки той же
// таблицы — человек сразу видит обновлённый состав полей.
if ($caction === 'alter_add_field' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $table   = (string) ($_POST['table'] ?? '');
    $outcome = configurator_add_field($db_connection, $table, (array) ($_POST['field'] ?? []), $application);
    $msg = $outcome['ok']
        ? 'Поле добавлено и взято под управление.'
        : implode(' | ', $outcome['errors']);
    header('Location: ?_action=edit&table=' . rawurlencode($table)
         . '&_msg=' . rawurlencode($msg) . '&_ok=' . ($outcome['ok'] ? '1' : '0'));
    exit;
}
if ($caction === 'alter_drop_field' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $table   = (string) ($_POST['table'] ?? '');
    $field   = (string) ($_POST['field'] ?? '');
    $force   = ($_POST['force'] ?? '') === '1';
    $outcome = configurator_drop_field($db_connection, $table, $field, $force, $application);
    $msg = $outcome['ok']
        ? "Поле $field удалено из таблицы."
        : implode(' | ', $outcome['errors']);
    header('Location: ?_action=edit&table=' . rawurlencode($table)
         . '&_msg=' . rawurlencode($msg) . '&_ok=' . ($outcome['ok'] ? '1' : '0'));
    exit;
}

// --- отрисовка -----------------------------------------------------------

$flash    = isset($_GET['_msg']) ? render_escape((string) $_GET['_msg']) : null;
$flash_ok = ($_GET['_ok'] ?? '1') === '1';

echo render_admin_page_open(
    'Конфигуратор GPDP',
    '<a class="home-link" href="index.php">← Домой</a> · '
    . '<a class="home-link" href="labels.php">Подписи и словари</a>'
);

echo '<h1>Конфигуратор БД (v0)</h1>';
echo render_admin_flash($flash, $flash_ok);

// Диагностика структуры (журнал 07-11): при заходе считаем расхождения
// БД↔реестр. Плашка-уведомление, если есть — не молча. Раздел «Состояние
// модели» показывает подробно и чинит.
$diag = model_diagnose($db_connection);
if (!($diag['clean'] ?? false) && $caction !== 'diagnose') {
    $n = count($diag['orphan_fields']) + count($diag['orphan_tables'])
       + count($diag['ghost_registry']) + count($diag['duplicates']);
    echo '<div class="flash flash-err">В структуре модели расхождений: ' . $n
       . '. <a href="?_action=diagnose">Состояние модели →</a></div>';
}

if ($caction === 'diagnose') {

    echo '<h2>Состояние модели</h2>';
    echo '<p><a href="?_action=list">← К таблицам</a></p>';
    echo render_model_diagnosis($diag);

} elseif ($caction === 'new_table') {

    $type_options = '';
    foreach (entities() as $entity_id => $passport) {
        $label = render_escape((string) ($passport['label'] ?? $entity_id));
        $type_options .= '<option value="' . render_escape($entity_id) . '">'
                       . render_escape($entity_id) . ' — ' . $label . '</option>';
    }

    // Существующие словари уровня 0: таблицы voc_* с полем data_name.
    // Живое чтение — та же дисциплина, что у списка (§8, админ-режим),
    // не кэш: только что созданный словарь обязан появиться сразу.
    $live_structure    = snapshot_build_structure($db_connection);
    $live_presentation = snapshot_build_presentation($db_connection);
    $available_dicts   = [];
    $dict_options      = '<option value="">— выберите словарь —</option>';

    foreach ($live_structure['tables'] as $t_name => $t_schema) {
        if (!str_starts_with($t_name, 'voc_') || !isset($t_schema['fields']['data_name'])) {
            continue;
        }
        $name_part = substr($t_name, strlen('voc_'));
        $t_labels  = $live_presentation['labels']['table'][$t_name] ?? [];
        $short     = (string) ($t_labels['data_short'] ?? $t_name);
        $full      = (string) ($t_labels['data_full'] ?? $t_name);

        $available_dicts[$name_part] = ['short' => $short, 'full' => $full];
        $dict_options .= '<option value="' . render_escape($name_part) . '">'
                       . render_escape($full) . ' (' . render_escape($t_name) . ')</option>';
    }
    $dict_labels_json = json_encode($available_dicts, JSON_UNESCAPED_UNICODE);

    // Цели для link_ (журнал 2026-07-12): ЛЮБАЯ таблица с data_name —
    // не только voc_*, в этом и смысл link_ (Идея А). Системные (model_)
    // уже исключены структурным сканером. Более широкие цели (только с
    // шаблоном подписи, без data_name) движок понимает, но этот список —
    // сознательно суженный v0-фильтр для удобного выбора, не полный
    // охват движка.
    $link_target_options = '<option value="">— выберите цель —</option>';
    foreach ($live_structure['tables'] as $t_name => $t_schema) {
        if (!isset($t_schema['fields']['data_name'])) {
            continue;
        }
        $t_labels = $live_presentation['labels']['table'][$t_name] ?? [];
        $t_full   = (string) ($t_labels['data_full'] ?? $t_name);
        $link_target_options .= '<option value="' . render_escape($t_name) . '">'
                              . render_escape($t_full) . ' (' . render_escape($t_name) . ')</option>';
    }

    // Родители для режима "Зависимая таблица" — ВСЕ живые таблицы
    // (включая словари: контекстные таблицы могут подчиняться и им).
    // model_-таблицы структурный сканер уже исключил.
    $parent_options = '<option value="">— выберите родителя —</option>';
    foreach (array_keys($live_structure['tables']) as $t_name) {
        $parent_options .= '<option value="' . render_escape($t_name) . '">'
                         . render_escape($t_name) . '</option>';
    }

    echo <<<HTML
    <h2>Новая таблица</h2>
    <form method="post" action="?_action=create_table" id="create-form">

      <p>
        <label><input type="radio" name="table_kind" value="plain" checked onchange="onKindChange()">
          Обычная таблица</label>
        &nbsp;&nbsp;
        <label><input type="radio" name="table_kind" value="voc_simple" onchange="onKindChange()">
          Словарь (простой, уровень 0)</label>
        &nbsp;&nbsp;
        <label><input type="radio" name="table_kind" value="dependent" onchange="onKindChange()">
          Зависимая таблица</label>
      </p>

      <div id="dependent-parent" style="display:none">
        <p>Родитель: <select name="parent_table">$parent_options</select>
           <small>(колонка <code>dep_&lt;родитель&gt;</code> создастся сама)</small></p>
        <p><label><input type="checkbox" name="add_rel_main" value="1" checked>
           привязать к корневому досье (<code>rel_main</code>)</label>
           <small>— принадлежность центральной записи, не то же, что родитель</small></p>
      </div>

      <div id="plain-name">
        <p>Имя таблицы: <input type="text" name="table_name" pattern="[a-z][a-z0-9_]*"></p>
      </div>
      <div id="dict-name" style="display:none">
        <p>Имя словаря: <code>voc_</code><input type="text" name="dict_name" pattern="[a-z][a-z0-9_]*">
           <small>(префикс voc_ подставится сам)</small></p>
      </div>

      <p>Полная подпись: <input type="text" name="table_full" required>
         Короткая подпись: <input type="text" name="table_short" required></p>

      <div id="fields-section">
        <h3>Поля</h3>
        <div id="fields"></div>
        <button type="button" onclick="addField()">+ добавить поле</button>
      </div>
      <div id="dict-fields-note" style="display:none">
        <p><em>Поля словаря создаются автоматически: id + data_name. Больше ничего не нужно —
        как только словарю потребуются доп. атрибуты, это уже не словарь, а полноценная таблица
        (создавайте режимом "Обычная таблица", словарь будет адресовать её отдельным решением).</em></p>
      </div>

      <p><input type="submit" value="Создать"></p>
    </form>
    <p><a href="?_action=list">Отмена</a></p>

    <template id="field-template">
      <div class="field-row">
        <select name="fields[__I__][entity]" onchange="onFieldTypeChange(this)">
          <option value="" selected disabled>— тип поля —</option>$type_options</select>

        <input type="text" class="f-name" name="fields[__I__][name]" placeholder="именная часть (без префикса)">
        <select class="f-voc-pick" name="fields[__I__][voc_pick]" style="display:none" onchange="onVocPick(this)">$dict_options</select>
        <select class="f-link-target" name="fields[__I__][link_target]" style="display:none">$link_target_options</select>

        <input type="text" class="f-full"  name="fields[__I__][full]"  placeholder="полная подпись">
        <input type="text" class="f-short" name="fields[__I__][short]" placeholder="короткая подпись">
        <button type="button" onclick="this.closest('.field-row').remove()" title="убрать поле">×</button>
      </div>
    </template>

    <script>
    const dictLabels = $dict_labels_json;
    let fieldCount = 0;

    function addField() {
      const tpl = document.getElementById('field-template').innerHTML.replaceAll('__I__', fieldCount++);
      const div = document.createElement('div');
      div.innerHTML = tpl;
      const row = div.firstElementChild;
      document.getElementById('fields').appendChild(row);
      // Сразу предлагаем список типов, не второй клик отдельно на select.
      const select = row.querySelector('select[name*="[entity]"]');
      select.focus();
      try { select.showPicker?.(); } catch { /* браузер не поддерживает — focus() уже сделан */ }
    }

    function onFieldTypeChange(select) {
      const row  = select.closest('.field-row');
      const name = row.querySelector('.f-name');
      const voc  = row.querySelector('.f-voc-pick');
      const link = row.querySelector('.f-link-target');
      const short = row.querySelector('.f-short');
      const full  = row.querySelector('.f-full');

      // voc_: имя выбирается из существующих словарей, не печатается
      // (§16, уровень 0). link_: имя СВОБОДНОЕ (как обычное поле) — а
      // цель выбирается отдельно (журнал 07-12: имя и адрес — разные
      // вещи, обе видны сразу, никакого авто-заполнения подписи от
      // цели — семантика поля («любимый цвет») не совпадает с
      // подписью цели («Цвет»)).
      voc.style.display  = select.value === 'voc'  ? '' : 'none';
      link.style.display = select.value === 'link' ? '' : 'none';
      name.style.display = select.value === 'voc'  ? 'none' : '';
      short.style.display = full.style.display = '';
    }

    function onVocPick(select) {
      const row = select.closest('.field-row');
      const info = dictLabels[select.value];
      if (info) {
        row.querySelector('.f-short').value = info.short;
        row.querySelector('.f-full').value  = info.full;
      }
    }

    function onKindChange() {
      const kind   = document.querySelector('input[name=table_kind]:checked').value;
      const isDict = kind === 'voc_simple';
      document.getElementById('plain-name').style.display       = isDict ? 'none' : 'block';
      document.getElementById('dict-name').style.display        = isDict ? 'block' : 'none';
      document.getElementById('fields-section').style.display   = isDict ? 'none' : 'block';
      document.getElementById('dict-fields-note').style.display  = isDict ? 'block' : 'none';
      document.getElementById('dependent-parent').style.display = kind === 'dependent' ? 'block' : 'none';
    }

    addField(); // первое поле сразу видно (для режима "обычная таблица")
    </script>
    HTML;

} elseif ($caction === 'edit') {

    // --- правка полей существующей таблицы (ALTER, Волна 2) ---------------
    $table = (string) ($_GET['table'] ?? '');
    $live_structure    = snapshot_build_structure($db_connection);
    $live_presentation = snapshot_build_presentation($db_connection);
    $t_schema = $live_structure['tables'][$table] ?? null;

    if ($t_schema === null || str_starts_with($table, SYSTEM_TABLE_PREFIX)) {
        echo '<p>Таблица не найдена или системная.</p><p><a href="?_action=list">← К таблицам</a></p>';
    } else {
        $t_labels = $live_presentation['labels']['table'][$table] ?? [];
        $t_full   = (string) ($t_labels['data_full'] ?? $table);

        // Число непустых значений по каждому entity-полю — одним запросом
        // (COUNT(col) считает не-NULL). Инженер видит цену удаления ДО клика.
        $entity_fields = [];
        foreach ($t_schema['fields'] as $f_name => $f_schema) {
            if (($f_schema['kind'] ?? '') === 'entity_field') {
                $entity_fields[] = $f_name;
            }
        }
        $data_counts = [];
        if ($entity_fields !== []) {
            $parts = [];
            foreach ($entity_fields as $f) {
                $parts[] = 'COUNT(`' . $f . '`) AS `' . $f . '`';
            }
            $rows = db_select($db_connection, 'SELECT ' . implode(', ', $parts) . ' FROM `' . $table . '`');
            $data_counts = $rows[0] ?? [];
        }

        $tbl_esc = render_escape($table);
        echo '<h2>Поля таблицы: ' . render_escape($t_full)
           . ' <span class="badge">' . $tbl_esc . '</span></h2>';
        echo '<p><a href="?_action=list">← К таблицам</a></p>';

        // Текущие поля: структурные — серым без действий, entity — с удалением.
        echo '<table class="data-list"><tr><th>поле</th><th>тип</th><th>подпись</th><th>данных</th><th></th></tr>';
        foreach ($t_schema['fields'] as $f_name => $f_schema) {
            $f_esc = render_escape($f_name);
            if (($f_schema['kind'] ?? '') !== 'entity_field') {
                echo '<tr><td><code style="color:#999">' . $f_esc . '</code></td>'
                   . '<td colspan="4" style="color:#999">структурное — не редактируется</td></tr>';
                continue;
            }
            $f_labels = $live_presentation['labels']['field'][$table][$f_name] ?? [];
            $f_full   = render_escape((string) ($f_labels['data_full'] ?? ''));
            $entity   = render_escape((string) ($f_schema['entity'] ?? ''));
            $cnt      = (int) ($data_counts[$f_name] ?? 0);

            // Подтверждение зависит от данных: пустое поле — простое,
            // с данными — предупреждение + force. Сервер перепроверит.
            if ($cnt > 0) {
                $confirm = "В поле $f_name данные: $cnt знач. Удалить ВМЕСТЕ С ДАННЫМИ? Это необратимо.";
                $force   = '<input type="hidden" name="force" value="1">';
            } else {
                $confirm = "Удалить пустое поле $f_name?";
                $force   = '';
            }
            echo '<tr><td><code>' . $f_esc . '</code></td>'
               . '<td>' . $entity . '</td><td>' . $f_full . '</td>'
               . '<td>' . ($cnt > 0 ? $cnt : '—') . '</td>'
               . '<td><form method="post" action="?_action=alter_drop_field" style="margin:0" '
               . 'onsubmit="return confirm(\'' . render_escape($confirm) . '\')">'
               . '<input type="hidden" name="table" value="' . $tbl_esc . '">'
               . '<input type="hidden" name="field" value="' . $f_esc . '">' . $force
               . '<button type="submit" class="act act-danger" title="удалить поле">×</button>'
               . '</form></td></tr>';
        }
        echo '</table>';

        // --- добавить поле: одна строка, та же механика, что при создании ---
        $type_options = '';
        foreach (entities() as $entity_id => $passport) {
            $label = render_escape((string) ($passport['label'] ?? $entity_id));
            $type_options .= '<option value="' . render_escape($entity_id) . '">'
                           . render_escape($entity_id) . ' — ' . $label . '</option>';
        }
        $available_dicts = [];
        $dict_options    = '<option value="">— выберите словарь —</option>';
        foreach ($live_structure['tables'] as $t_name => $ts) {
            if (!str_starts_with($t_name, 'voc_') || !isset($ts['fields']['data_name'])) {
                continue;
            }
            $name_part = substr($t_name, strlen('voc_'));
            $tl = $live_presentation['labels']['table'][$t_name] ?? [];
            $available_dicts[$name_part] = [
                'short' => (string) ($tl['data_short'] ?? $t_name),
                'full'  => (string) ($tl['data_full'] ?? $t_name),
            ];
            $dict_options .= '<option value="' . render_escape($name_part) . '">'
                           . render_escape($available_dicts[$name_part]['full'])
                           . ' (' . render_escape($t_name) . ')</option>';
        }
        $dict_labels_json = json_encode($available_dicts, JSON_UNESCAPED_UNICODE);

        // Цели для link_ — тот же критерий, что при создании таблицы
        // (любая таблица с data_name, не только voc_*).
        $link_target_options = '<option value="">— выберите цель —</option>';
        foreach ($live_structure['tables'] as $t_name => $ts) {
            if (!isset($ts['fields']['data_name'])) {
                continue;
            }
            $tl = $live_presentation['labels']['table'][$t_name] ?? [];
            $link_target_options .= '<option value="' . render_escape($t_name) . '">'
                                   . render_escape((string) ($tl['data_full'] ?? $t_name))
                                   . ' (' . render_escape($t_name) . ')</option>';
        }

        echo <<<HTML
        <h3>Добавить поле</h3>
        <form method="post" action="?_action=alter_add_field">
          <input type="hidden" name="table" value="$tbl_esc">
          <div class="field-row">
            <select name="field[entity]" onchange="onFieldTypeChange(this)">
              <option value="" selected disabled>— тип поля —</option>$type_options</select>
            <input type="text" class="f-name" name="field[name]" placeholder="именная часть (без префикса)">
            <select class="f-voc-pick" name="field[voc_pick]" style="display:none" onchange="onVocPick(this)">$dict_options</select>
            <select class="f-link-target" name="field[link_target]" style="display:none">$link_target_options</select>
            <input type="text" class="f-full"  name="field[full]"  placeholder="полная подпись">
            <input type="text" class="f-short" name="field[short]" placeholder="короткая подпись">
            <button type="submit">+ добавить</button>
          </div>
        </form>
        <script>
        const dictLabels = $dict_labels_json;
        function onFieldTypeChange(select) {
          const row  = select.closest('.field-row');
          const name = row.querySelector('.f-name');
          const voc  = row.querySelector('.f-voc-pick');
          const link = row.querySelector('.f-link-target');
          voc.style.display  = select.value === 'voc'  ? '' : 'none';
          link.style.display = select.value === 'link' ? '' : 'none';
          name.style.display = select.value === 'voc'  ? 'none' : '';
        }
        function onVocPick(select) {
          const row = select.closest('.field-row');
          const info = dictLabels[select.value];
          if (info) {
            row.querySelector('.f-short').value = info.short;
            row.querySelector('.f-full').value  = info.full;
          }
        }
        </script>
        HTML;
    }

} elseif ($caction === 'delete_confirm') {

    $table = render_escape((string) ($_GET['table'] ?? ''));
    echo <<<HTML
    <h2>Удаление таблицы "$table"</h2>
    <p>Действие необратимо: физическая таблица и все её записи будут уничтожены.</p>
    <form method="post" action="?_action=delete_table">
      <input type="hidden" name="table" value="$table">
      <div id="stage1">
        <button type="button" onclick="reveal('stage2')">Я понимаю, что это необратимо</button>
      </div>
      <div id="stage2" style="display:none">
        <button type="button" onclick="reveal('stage3')">Да, удалить таблицу "$table"</button>
      </div>
      <div id="stage3" style="display:none">
        <button type="submit" style="color:red;font-weight:bold">ПОДТВЕРДИТЬ УДАЛЕНИЕ ОКОНЧАТЕЛЬНО</button>
      </div>
    </form>
    <p><a href="?_action=list">Отмена</a></p>
    <script>
    function reveal(id) { document.getElementById(id).style.display = 'block'; }
    </script>
    HTML;

} else {
    // --- список: живой слепок, без кэша ------------------------------------
    // Снапшот-для-показа собирается из готовых кусков (не snapshot_build:
    // тот fail-fast падает на кривой схеме, а список должен показываться
    // именно чтобы схему чинить). Нужны: структура (поля), презентация
    // (человеческие подписи для schema_view), связи (дерево зависимых).
    $structure    = snapshot_build_structure($db_connection);
    $presentation = snapshot_build_presentation($db_connection);
    $relations    = snapshot_build_relations(['tables' => $structure['tables']]);
    $snapshot = [
        'structure'    => $structure,
        'presentation' => $presentation,
        'model'        => ['relations' => $relations['map']],
    ];
    // Бейдж «используется как словарь» (уровень 0, §16): множество имён
    // полей вида voc_* среди ВСЕХ таблиц — по конвенции это и есть имена
    // таблиц-словарей, на которые кто-то ссылается.
    $referenced_as_dict = [];
    foreach ($structure['tables'] as $t) {
        foreach ($t['fields'] as $fname => $fschema) {
            if (($fschema['kind'] ?? '') === 'entity_field' && ($fschema['entity'] ?? '') === 'voc') {
                $referenced_as_dict[$fname] = true;
            }
        }
    }

    echo '<h2>Таблицы</h2><p><a href="?_action=new_table">+ новая таблица</a></p>';

    // Каталог таблиц — общая раскладка (та же, что на labels; render.php).
    // Под капотом различаются только действия карточек.
    render_table_directory($snapshot, [
        'содержимое'    => 'index.php?_table={t}&_action=view',
        'редактировать' => '?_action=edit&table={t}',
        'удалить'       => '?_action=delete_confirm&table={t}',
    ], ['referenced' => $referenced_as_dict]);
}

echo '</body></html>';
mysqli_close($db_connection);
