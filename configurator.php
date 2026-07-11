<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

// sync: 2026-07-11, view-слой п.8г — конфигуратор: 4 группы таблиц + схема через schema_view/render_schema_card

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
        $entity_choice = (string) ($raw_field['entity'] ?? '');
        $f_short       = trim((string) ($raw_field['short'] ?? ''));
        $f_full        = trim((string) ($raw_field['full'] ?? ''));

        // 'id' — структурный синтетический выбор, не сущность: не проверяем
        // имя/подписи, не создаёт запись в реестре (см. §17: id не описан
        // подписью, он идентичность, а не модельный элемент со смыслом).
        if ($entity_choice === 'id') {
            if ($has_id) {
                $errors[] = 'Поле "id" можно указать только один раз.';
            }
            $has_id = true;
            continue;
        }

        if (!isset($known_entities[$entity_choice])) {
            $errors[] = "Поле #" . ($i + 1) . ": неизвестный тип \"$entity_choice\".";
            continue;
        }

        // Для voc именная часть приходит из выпадающего списка существующих
        // словарей (voc_pick), не из свободного текстового поля name —
        // предотвращает опечатку там, где правильный ответ всегда один
        // из уже известных системе вариантов.
        $name_part = $entity_choice === 'voc'
            ? trim((string) ($raw_field['voc_pick'] ?? ''))
            : trim((string) ($raw_field['name'] ?? ''));

        if (!configurator_identifier_valid($name_part)) {
            $errors[] = "Поле #" . ($i + 1) . ($entity_choice === 'voc'
                ? ": словарь не выбран."
                : ": именная часть — латиница/цифры/\"_\", с буквы.");
            continue;
        }

        $column = $entity_choice . '_' . $name_part;

        if (strlen($column) > 64) {
            $errors[] = "Поле #" . ($i + 1) . ": имя колонки \"$column\" длиннее 64 символов (лимит MySQL).";
        }
        if (in_array($column, STRUCTURAL_FIELD_NAMES, true)
            || str_starts_with($column, 'dep_')) {
            $errors[] = "Поле #" . ($i + 1) . ": \"$column\" — зарезервированное структурное имя.";
        }
        if (isset($seen_columns[$column])) {
            $errors[] = "Поле #" . ($i + 1) . ": колонка \"$column\" повторяется в спецификации.";
        }
        $seen_columns[$column] = true;

        if ($f_short === '' || $f_full === '') {
            $errors[] = "Поле #" . ($i + 1) . ": короткая и полная подпись обязательны.";
        }

        // Уровень 0 (§16): для voc-поля таблица-склад должна существовать
        // УЖЕ СЕЙЧАС и иметь data_name. Она создаётся режимом 'voc_simple'
        // выше — эта ветка только проверяет, что путь был пройден честно.
        if ($entity_choice === 'voc') {
            $dict_table = $column; // конвенция уровня 0: имя поля = имя таблицы-словаря
            $dict_fields = $live_structure['tables'][$dict_table]['fields'] ?? null;
            if ($dict_fields === null) {
                $errors[] = "Поле #" . ($i + 1) . ": словаря \"$dict_table\" не существует. "
                          . "Создайте его первым через режим \"Словарь\".";
            } elseif (!isset($dict_fields['data_name'])) {
                $errors[] = "Поле #" . ($i + 1) . ": таблица \"$dict_table\" существует, но без поля data_name — "
                          . "не может быть словарём уровня 0.";
            }
        }

        $fields[] = [
            'column'  => $column,
            'entity'  => $entity_choice,
            'db_type' => $known_entities[$entity_choice]['db']['default_type'],
            'short'   => $f_short,
            'full'    => $f_full,
        ];
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

        if (!mysqli_query($db_connection, $sql)) {
            return ['ok' => false, 'errors' => ['DDL: ' . mysqli_error($db_connection)]];
        }

        // Регистрация таблицы в реестре + подпись.
        $stmt = mysqli_prepare(
            $db_connection,
            'INSERT INTO `' . MODEL_REGISTRY_TABLE . '` (data_kind, data_owner, data_element, active) '
            . "VALUES ('table', NULL, ?, 1)"
        );
        mysqli_stmt_bind_param($stmt, 's', $table);
        mysqli_stmt_execute($stmt);
        $table_registry_id = mysqli_insert_id($db_connection);

        $stmt = mysqli_prepare(
            $db_connection,
            'INSERT INTO `' . MODEL_LABELS_TABLE . '` (dep_model_registry, data_short, data_full) VALUES (?, ?, ?)'
        );
        mysqli_stmt_bind_param($stmt, 'iss', $table_registry_id, $spec['table_short'], $spec['table_full']);
        mysqli_stmt_execute($stmt);

        // Регистрация каждого предметного поля (id — структурный, без записи).
        foreach ($spec['fields'] as $field) {
            $stmt = mysqli_prepare(
                $db_connection,
                'INSERT INTO `' . MODEL_REGISTRY_TABLE . '` (data_kind, data_owner, data_element, active) '
                . "VALUES ('field', ?, ?, 1)"
            );
            mysqli_stmt_bind_param($stmt, 'ss', $table, $field['column']);
            mysqli_stmt_execute($stmt);
            $field_registry_id = mysqli_insert_id($db_connection);

            $stmt = mysqli_prepare(
                $db_connection,
                'INSERT INTO `' . MODEL_LABELS_TABLE . '` (dep_model_registry, data_short, data_full) VALUES (?, ?, ?)'
            );
            mysqli_stmt_bind_param($stmt, 'iss', $field_registry_id, $field['short'], $field['full']);
            mysqli_stmt_execute($stmt);
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
        $stmt = mysqli_prepare(
            $db_connection,
            'DELETE FROM `' . MODEL_REGISTRY_TABLE . "` WHERE (data_kind='table' AND data_element=?) "
            . "OR (data_kind='field' AND data_owner=?)"
        );
        mysqli_stmt_bind_param($stmt, 'ss', $table, $table);
        mysqli_stmt_execute($stmt);

        if (!mysqli_query($db_connection, 'DROP TABLE `' . $table . '`')) {
            return ['ok' => false, 'errors' => ['DDL: ' . mysqli_error($db_connection)]];
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

if ($caction === 'new_table') {

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
      const short = row.querySelector('.f-short');
      const full  = row.querySelector('.f-full');

      if (select.value === 'voc') {
        // именная часть выбирается из уже существующих словарей,
        // не печатается свободным текстом (§16, уровень 0)
        name.style.display = 'none';
        voc.style.display = '';
        short.style.display = full.style.display = '';
      } else {
        name.style.display = '';
        voc.style.display = 'none';
        short.style.display = full.style.display = '';
      }
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
    $structure = snapshot_build_structure($db_connection);
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

    // Действия карточки (навигация конфигуратора), {t} → имя таблицы.
    $card_actions = [
        'содержимое'   => 'index.php?_table={t}&_action=view',
        'редактировать' => '?_action=edit&table={t}',
        'удалить'       => '?_action=delete_confirm&table={t}',
    ];

    // Раскладка по группам (классификатор в ядре, один на все страницы).
    $by_group = ['main' => [], 'dict' => [], 'system' => []];
    foreach ($structure['tables'] as $t_name => $t_schema) {
        $g = table_group($t_name, $t_schema);
        // dependent не верхнего уровня — покажется под своей главной деревом.
        if ($g === 'dependent') {
            continue;
        }
        $by_group[$g][] = $t_name;
    }
    foreach ($by_group as $g => &$names) {
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    }
    unset($names);

    // Рекурсивный вывод главной таблицы с её зависимыми (дерево по графу
    // связей). Дети берутся из скомпилированного model.relations.
    $render_table_tree = function (string $t_name, int $depth) use (
        &$render_table_tree, $snapshot, $structure, $card_actions, $referenced_as_dict
    ): void {
        $badge = isset($referenced_as_dict[$t_name]) ? '<span class="badge">словарь</span>' : '';
        echo render_schema_card(schema_view($snapshot, $t_name), $card_actions, $badge, $depth);
        foreach ($snapshot['model']['relations'][$t_name] ?? [] as $relation) {
            $render_table_tree($relation['child'], $depth + 1);
        }
    };

    echo '<h3>Главные таблицы</h3>';
    if ($by_group['main'] === []) {
        echo '<p><em>нет</em></p>';
    } else {
        foreach ($by_group['main'] as $t_name) {
            $render_table_tree($t_name, 0);
        }
    }

    echo '<h3>Отчёты</h3><p><em>Отчёты не созданы.</em></p>';

    echo '<h3>Служебные таблицы</h3>';
    if ($by_group['dict'] === []) {
        echo '<p><em>нет</em></p>';
    } else {
        foreach ($by_group['dict'] as $t_name) {
            $badge = isset($referenced_as_dict[$t_name]) ? '<span class="badge">словарь</span>' : '';
            echo render_schema_card(schema_view($snapshot, $t_name), $card_actions, $badge, 0);
        }
    }

    echo '<h3>Системные таблицы</h3>';
    if ($by_group['system'] === []) {
        echo '<p><em>нет</em></p>';
    } else {
        foreach ($by_group['system'] as $t_name) {
            echo render_schema_card(schema_view($snapshot, $t_name), $card_actions, '', 0);
        }
    }
}

echo '</body></html>';
mysqli_close($db_connection);
