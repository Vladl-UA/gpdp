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

require_once 'config.php';
require_once 'db.php';
require_once 'core.php';
require_once 'helpers.php';
require_once 'render.php';


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

    // --- kind === 'view_filtered': словарь-представление (2026-07-17,
    // STATE.md «Сейчас», гибридные словари, второй шаг после links_) —
    // умышленно узкий v1: источник — существующая таблица с id+data_name
    // (то же требование, что voc_simple предъявляет к себе самой),
    // фильтр — ОДНА существующая bul_-колонка источника, `= 1` (не
    // произвольный WHERE-текст — закрытый перечислимый выбор из уже
    // известных структурных элементов, тот же принцип §12, что держит
    // весь остальной конфигуратор; расширение вида фильтра — отдельное
    // решение с отдельным прогоном §15, не молчаливое обрастание этой
    // формы). Легаси-прецедент — `mlt_contragent`: контрагент, роль
    // фильтром по булеву флагу; здесь то же самое, честно, через
    // Postgres VIEW вместо ручного SQL в каждом запросе.
    if ($kind === 'view_filtered') {
        $name_part = trim((string) ($input['dict_name'] ?? ''));
        $view      = configurator_identifier_valid($name_part) ? 'voc_' . $name_part : '';

        if (!configurator_identifier_valid($name_part)) {
            $errors[] = 'Имя словаря: только строчные латинские буквы, цифры, "_", начинается с буквы (без префикса — voc_ подставится сам).';
        } elseif (isset($live_structure['tables'][$view])) {
            $errors[] = "Словарь \"$view\" уже существует.";
        }

        $source = trim((string) ($input['view_source'] ?? ''));
        $source_fields = $live_structure['tables'][$source]['fields'] ?? null;
        if ($source_fields === null) {
            $errors[] = 'Исходная таблица не выбрана или не существует.';
        } elseif (!isset($source_fields['data_name'])) {
            $errors[] = "таблица \"$source\" существует, но без поля data_name — не может быть источником словаря.";
        }

        $filter_column = trim((string) ($input['view_filter_column'] ?? ''));
        if ($source_fields !== null
            && (($source_fields[$filter_column]['kind'] ?? '') !== 'entity_field'
                || ($source_fields[$filter_column]['entity'] ?? '') !== 'bul')) {
            $errors[] = "поле фильтра \"$filter_column\" не существует в \"$source\" или не булево (bul_).";
        }

        $table_short = trim((string) ($input['table_short'] ?? ''));
        $table_full  = trim((string) ($input['table_full'] ?? ''));
        if ($table_short === '' || $table_full === '') {
            $errors[] = 'Подпись словаря (короткая и полная) обязательна.';
        }

        return [
            'ok'             => $errors === [],
            'errors'         => $errors,
            'view'           => $view,
            'source'         => $source,
            'filter_column'  => $filter_column,
            'table_short'    => $table_short,
            'table_full'     => $table_full,
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
 *
 * $table_fields — уже существующие entity-поля таблицы-владельца (для
 * calc_: whitelist переменных формулы, тот же критерий, что
 * snapshot_build_formulas()). null — контекста нет (создание новой
 * таблицы, полей которой ещё не существует) — calc_ там осмысленно
 * недоступен: переменных для формулы взять неоткуда раньше, чем поля
 * реально появятся (шаг 3 архивного плана, решение 2026-07-15).
 */
function configurator_parse_field(
    array $raw_field,
    array $known_entities,
    array $live_structure,
    ?array $table_fields = null
): array {
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

    // link_/links_: имя поля свободное (как обычное), а не выбор из
    // списка — в отличие от voc_, где имя = имя целевой таблицы. Цель
    // указывается ОТДЕЛЬНО (журнал 2026-07-12: имя поля и адрес цели —
    // разные вещи, ровно затем и вводили link_). links_ (2026-07-17) —
    // тот же механизм адресации, та же проверка; список литералов
    // здесь короче, чем проверка db.kind==='column_with_table' по
    // каждому known_entities на этом шаге — не общий случай, других
    // сущностей этого вида пока нет.
    $link_target = null;
    if ($entity_choice === 'link' || $entity_choice === 'links') {
        $link_target = trim((string) ($raw_field['link_target'] ?? ''));
        if ($link_target === '' || !isset($live_structure['tables'][$link_target])) {
            return ['ok' => false, 'errors' => ['цель ссылки не выбрана или не существует.'], 'field' => null];
        }
    }

    // calc_: формула — отдельное поле формы, не «именная часть» (та же
    // причина разделения, что у link_target). Whitelist переменных —
    // ЗДЕСЬ, до записи, тем же правилом, что snapshot_build_formulas()
    // применит при сборке снапшота: поле своей же таблицы, иначе — вовсе
    // не предлагать (§14.2 архивного плана: невозможный выбор не ошибка
    // постфактум, а недоступный вариант).
    $formula = null;
    if ($entity_choice === 'calc') {
        if ($table_fields === null) {
            return ['ok' => false, 'errors' => [
                'вычисляемое поле добавляется только к существующей таблице, после остальных полей — переменных для формулы ещё нет.',
            ], 'field' => null];
        }
        $formula = trim((string) ($raw_field['formula'] ?? ''));
        $plan = formula_parse($formula);
        if ($plan === null) {
            return ['ok' => false, 'errors' => ['формула: синтаксис не распознан (ожидается {поле} оператор {поле} ...).'], 'field' => null];
        }
        foreach ($plan as $step) {
            if ($step['type'] === 'field'
                && ($table_fields[$step['name']]['kind'] ?? '') !== 'entity_field') {
                return ['ok' => false, 'errors' => ["формула: переменная \"{$step['name']}\" — не поле этой таблицы."], 'field' => null];
            }
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
        'formula'     => $formula,     // null для всех, кроме calc_
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
function configurator_register_link(PgSql\Connection $db_connection, string $column, string $target): array
{
    return db_execute(
        $db_connection,
        'INSERT INTO model_links (data_element, data_target_table) VALUES (?, ?)',
        'ss',
        [$column, $target]
    );
}

/**
 * Записать формулу calc_-поля в model_formulas (шаг 3 архивного плана,
 * решение 2026-07-15). Синтаксис и whitelist уже проверены в
 * configurator_parse_field() ДО этого вызова — здесь только запись,
 * тонкая труба, повторно не перепроверяет (§7).
 */
function configurator_register_formula(PgSql\Connection $db_connection, int $registry_id, string $formula): array
{
    return db_execute(
        $db_connection,
        'INSERT INTO model_formulas (dep_model_registry, data_formula) VALUES (?, ?)',
        'is',
        [$registry_id, $formula]
    );
}

// ============================================================================
// Общие кирпичи (вынесены 2026-07-16, рефакторинг перед
// configurator_create_view — STATE.md «Сейчас», аудит модульности по
// прямому запросу Влада). Восемь функций ниже дословно повторяли три
// куска — вынесены без изменения поведения, только снятие дублирования.
// ============================================================================

/**
 * Каркас «захватить структурный лок → выполнить $body → снять лок в
 * finally». Было продублировано дословно в восьми функциях (create_table,
 * delete_table, adopt_field, adopt_table, drop_registry_row, drop_column,
 * add_field, drop_field), различаясь только причиной лока и телом try.
 * Текст отказа при занятом локе унифицирован на более частый вариант
 * («Схема заблокирована другой операцией.» — было в 6 из 8 мест; 2 места
 * — create_table/delete_table — говорили чуть иначе, «уже заблокирована
 * другой структурной операцией»; смысл тот же, различие не несло сведений
 * пользователю).
 */
/**
 * Каркас структурной операции. 2026-07-19: к файловому локу добавлена
 * настоящая транзакция БД и наведён порядок публикации снапшота.
 *
 * §17 объявляет цепочку `lock → DDL → реестр → rebuild → validate →
 * unlock` ЕДИНОЙ структурной операцией, §8 держит инвариант «факт не
 * живёт только в снапшоте». До этой правки атомарности не было вовсе:
 * файловый лок разводит писателей во времени, но не откатывает
 * наполовину сделанное. Отказ реестра после прошедшего DDL оставлял
 * таблицу, о которой система не знает, — состояние, которое сам код
 * честно называл «требуется ручной разбор».
 *
 * Порядок, и почему именно такой:
 *
 *   BEGIN
 *     тело (DDL + реестр)
 *     snapshot_build()      — интроспекция ВНУТРИ транзакции
 *   любой отказ → ROLLBACK, файл снапшота не тронут вовсе
 *   COMMIT
 *   snapshot_save()         — публикация файла только после COMMIT
 *
 * Ключевое свойство, на котором держится порядок: Postgres выполняет
 * DDL в транзакции, и information_schema/pg_index внутри той же
 * транзакции видят собственные незакоммиченные CREATE TABLE/VIEW
 * (проверено живьём, см. докблок db_transaction_begin). Поэтому
 * снапшот можно собрать ДО коммита — и не публиковать его, если
 * коммит не состоится.
 *
 * Файл снапшота транзакцией БД не откатывается — отсюда и требование
 * писать его последним. Остаточное состояние после правки ровно одно:
 * «БД согласована, файл старый». Оно безопасно и штатно — снапшот
 * восстановим удалением (§8), ручного разбора не требует.
 *
 * Пересборка снапшота переехала СЮДА из тел операций (раньше девять
 * копий: три дословных хвоста в create_table/create_view/delete_table
 * плюс шесть вызовов configurator_refresh) — не ради красоты, а
 * потому что правильный порядок «собрать до коммита, записать после»
 * невыразим, пока тело владеет и сборкой, и публикацией. Тело теперь
 * отвечает только за DDL и реестр.
 */
function configurator_with_lock(
    PgSql\Connection $db_connection,
    string $reason,
    callable $body,
    array $application
): array {
    if (!snapshot_lock_acquire('configurator', $reason)) {
        return ['ok' => false, 'errors' => ['Схема заблокирована другой операцией.']];
    }
    try {
        $begin = db_transaction_begin($db_connection);
        if (!$begin['ok']) {
            return ['ok' => false, 'errors' => ['Не удалось открыть транзакцию: ' . $begin['error']]];
        }

        $result = $body();
        if (!($result['ok'] ?? false)) {
            // Причина отказа уже в $result — своё сообщение об откате
            // не добавляем: первая причина важнее вторичной.
            db_transaction_rollback($db_connection);
            return $result;
        }

        $snapshot = snapshot_build($db_connection, $application);
        if ($snapshot === null) {
            db_transaction_rollback($db_connection);
            return ['ok' => false, 'errors' => [
                'Изменение отменено целиком: snapshot не собрался — ' . (snapshot_last_error() ?? '?'),
            ]];
        }

        $commit = db_transaction_commit($db_connection);
        if (!$commit['ok']) {
            return ['ok' => false, 'errors' => ['Изменение отменено: ' . $commit['error']]];
        }

        if (!snapshot_save($snapshot)) {
            return ['ok' => false, 'errors' => [
                'Изменение применено к БД, но файл snapshot не записан (права на state/?). '
                . 'Данные согласованы; достаточно удалить state/snapshot.php — он пересоберётся сам (§8).',
            ]];
        }

        return $result;
    } finally {
        snapshot_lock_release('configurator');
    }
}

/**
 * Зарегистрировать элемент (таблицу или поле) в model_registry +
 * подписать в model_labels. $kind — 'table' | 'field'; $owner — NULL
 * для таблицы, имя владеющей таблицы для поля. Возвращает id новой
 * строки реестра (RETURNING id, см. db.php). Было продублировано
 * дословно четыре раза (create_table — дважды: таблица и поле;
 * adopt_field; adopt_table).
 */
/**
 * 2026-07-18 (ревизия Chat, подтверждено): раньше возвращала голый
 * `int`, не проверяя результат НИ ОДНОЙ из двух вставок — при отказе
 * первой (`model_registry`) вторая (`model_labels`) писала бы
 * `dep_model_registry = 0`, и функция всё равно вернула бы «успешный»
 * id. Тот же принцип честного результата, что уже был у
 * `configurator_register_link` в этом же файле — просто не был сюда
 * распространён вовремя.
 */
function configurator_register_element(
    PgSql\Connection $db_connection,
    string $kind,
    ?string $owner,
    string $element,
    string $short,
    string $full
): array {
    $reg = db_execute(
        $db_connection,
        'INSERT INTO ' . MODEL_REGISTRY_TABLE
        . ' (data_kind, data_owner, data_element, active) VALUES (?, ?, ?, 1) RETURNING id',
        'sss',
        [$kind, $owner, $element]
    );
    if (!$reg['ok']) {
        return ['ok' => false, 'id' => null, 'errors' => ['реестр: ' . $reg['error']]];
    }

    $label = db_execute(
        $db_connection,
        'INSERT INTO ' . MODEL_LABELS_TABLE . ' (dep_model_registry, data_short, data_full) VALUES (?, ?, ?)',
        'iss',
        [$reg['id'], $short, $full]
    );
    if (!$label['ok']) {
        return ['ok' => false, 'id' => (int) $reg['id'], 'errors' => ['подпись: ' . $label['error']]];
    }

    return ['ok' => true, 'id' => (int) $reg['id'], 'errors' => []];
}

/**
 * Уже под управлением реестра (активная строка, data_kind/owner/element
 * совпадают)? Было продублировано дословно дважды (adopt_field,
 * drop_column). `IS NOT DISTINCT FROM` вместо `=` — корректно сравнивает
 * NULL-владельца (таблица), не только непустого (поле), хотя оба текущих
 * вызывающих места передают только непустой $owner.
 */
function configurator_is_managed(PgSql\Connection $db_connection, string $kind, ?string $owner, string $element): bool
{
    $rows = db_select(
        $db_connection,
        'SELECT id FROM ' . MODEL_REGISTRY_TABLE
        . ' WHERE data_kind = ? AND data_owner IS NOT DISTINCT FROM ? AND data_element = ? AND active = 1',
        'sss',
        [$kind, $owner, $element]
    );
    return $rows !== [];
}

// ============================================================================
// Исполнение — единственное место, где спецификация становится DDL.
// Порядок буквально твой «стоп-файл»: lock → транзакция → пересборка →
// unlock. lock — уже существующий schema.lock, ничего нового не заведено.
// ============================================================================

function configurator_create_table(PgSql\Connection $db_connection, array $spec, array $application): array
{
    return configurator_with_lock($db_connection, 'Создание таблицы: ' . $spec['table'], function () use ($db_connection, $spec): array {
        $columns_sql = [];
        if ($spec['has_id']) {
            // 2026-07-16: Postgres — GENERATED ALWAYS AS IDENTITY вместо
            // AUTO_INCREMENT; UNSIGNED не существует как модификатор
            // (STATE.md «Сейчас» п.9, шаг 1 — тип самой колонки id не
            // относится к ревизии типов сущностей из entities.php,
            // это структурный элемент ядра, не паспорт).
            $columns_sql[] = 'id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY';
        }
        // Структурные колонки (dep_<parent>, rel_main) — тип детерминирован
        // (FK на суррогатный id), NULL сознательно: обязательность родителя
        // и FK-constraint — отложенная защита связей (журнал 07-06), не
        // протаскивается впрок через DDL. В реестр НЕ регистрируются —
        // структурные поля живут без записей (§17), как id.
        foreach ($spec['structural_columns'] ?? [] as $structural_column) {
            $columns_sql[] = $structural_column . ' INTEGER NULL';
        }
        foreach ($spec['fields'] as $field) {
            // $field['db_type'] — из паспорта сущности (entities.php),
            // ещё в исходном MySQL-виде (может содержать UNSIGNED/
            // TINYINT(1)/MEDIUMINT) — ревизия типов сущностей ждёт
            // своей очереди отдельным шагом (STATE.md «Сейчас» п.9,
            // шаг 2), не смешивается со структурной DDL-механикой здесь.
            $columns_sql[] = $field['column'] . ' ' . $field['db_type'] . ' NULL';
        }

        $table = $spec['table'];
        // ENGINE=/CHARSET=/COLLATE= — не табличный уровень в Postgres
        // (кодировка БД задана при createdb, см. журнал 07-16); секция
        // убрана целиком, не заменена эквивалентом.
        $sql = "CREATE TABLE $table (" . implode(', ', $columns_sql) . ")";

        $create = db_execute($db_connection, $sql);
        if (!$create['ok']) {
            return ['ok' => false, 'errors' => ['DDL: ' . $create['error']]];
        }

        // Регистрация таблицы в реестре + подпись.
        $reg = configurator_register_element($db_connection, 'table', null, $table, $spec['table_short'], $spec['table_full']);
        if (!$reg['ok']) {
            return ['ok' => false, 'errors' => [
                "Таблица $table создана в БД, но не зарегистрирована: " . implode('; ', $reg['errors']),
            ]];
        }

        // Регистрация каждого предметного поля (id — структурный, без записи).
        foreach ($spec['fields'] as $field) {
            $field_reg = configurator_register_element(
                $db_connection, 'field', $table, $field['column'], $field['short'], $field['full']
            );
            if (!$field_reg['ok']) {
                return ['ok' => false, 'errors' => [
                    "Таблица $table создана, но поле {$field['column']} не зарегистрировано: "
                    . implode('; ', $field_reg['errors']),
                ]];
            }

            if (($field['link_target'] ?? null) !== null) {
                $link = configurator_register_link($db_connection, $field['column'], $field['link_target']);
                if (!$link['ok']) {
                    return ['ok' => false, 'errors' => [
                        "Поле {$field['column']} создано, но адрес link_ не записан: " . $link['error'],
                    ]];
                }
            }
        }

        return ['ok' => true, 'errors' => []];
    }, $application);
}

/**
 * Создать словарь-представление (2026-07-17, узкий v1 — см. докблок
 * configurator_validate_spec branch 'view_filtered'): CREATE VIEW,
 * фильтр по одной bul_-колонке источника (`= 1`, не boolean — тот
 * же класс осторожности, что у model_registry.active, шаг 1
 * переезда: php-pgsql отдаёт boolean строками t/f, smallint — нет).
 * Регистрация и пересборка — теми же кирпичами, что у обычной
 * таблицы (configurator_with_lock/configurator_register_element,
 * рефакторинг 07-16) — вьюха не шестая копия каркаса, а третий
 * потребитель уже готового.
 */
function configurator_create_view(PgSql\Connection $db_connection, array $spec, array $application): array
{
    return configurator_with_lock($db_connection, 'Создание представления: ' . $spec['view'], function () use ($db_connection, $spec): array {
        $view          = $spec['view'];
        $source        = $spec['source'];
        $filter_column = $spec['filter_column'];

        $sql = "CREATE VIEW $view AS SELECT id, data_name FROM $source WHERE $filter_column = 1";

        $create = db_execute($db_connection, $sql);
        if (!$create['ok']) {
            return ['ok' => false, 'errors' => ['DDL: ' . $create['error']]];
        }

        $reg = configurator_register_element($db_connection, 'table', null, $view, $spec['table_short'], $spec['table_full']);
        if (!$reg['ok']) {
            return ['ok' => false, 'errors' => [
                "Представление $view создано в БД, но не зарегистрировано: " . implode('; ', $reg['errors']),
            ]];
        }
        // data_name — та же самая регистрация, что делает voc_simple для
        // синтетического поля своего словаря (короткая/полная подпись
        // "Имя"/"Наименование"). Без этого шага поле оставалось сиротой
        // (найдено живьём диагностикой сразу после первого создания).
        $data_name_reg = configurator_register_element($db_connection, 'field', $view, 'data_name', 'Имя', 'Наименование');
        if (!$data_name_reg['ok']) {
            return ['ok' => false, 'errors' => [
                "Представление $view зарегистрировано, но поле data_name — нет: "
                . implode('; ', $data_name_reg['errors']),
            ]];
        }

        return ['ok' => true, 'errors' => []];
    }, $application);
}

function configurator_delete_table(PgSql\Connection $db_connection, string $table, array $application): array
{
    return configurator_with_lock($db_connection, 'Удаление таблицы: ' . $table, function () use ($db_connection, $table): array {
        // model_labels чистится каскадом FK (ON DELETE CASCADE, §17).
        db_execute(
            $db_connection,
            'DELETE FROM ' . MODEL_REGISTRY_TABLE . " WHERE (data_kind='table' AND data_element=?) "
            . "OR (data_kind='field' AND data_owner=?)",
            'ss',
            [$table, $table]
        );

        // 2026-07-17: DROP TABLE vs DROP VIEW — задел object_type
        // (шаг 0 гибридных словарей, добавлен в snapshot_build_structure
        // именно ради этой развилки) наконец пригодился первому
        // потребителю: словарю-представлению.
        $object_type = snapshot_build_structure($db_connection)['tables'][$table]['object_type'] ?? 'table';
        $drop_verb   = $object_type === 'view' ? 'DROP VIEW ' : 'DROP TABLE ';

        // DDL — имя таблицы не параметризуется (ограничение SQL, не
        // db_execute): $types='' — прямой запрос без подготовки.
        $drop = db_execute($db_connection, $drop_verb . $table . '');
        if (!$drop['ok']) {
            return ['ok' => false, 'errors' => ['DDL: ' . $drop['error']]];
        }

        return ['ok' => true, 'errors' => []];
    }, $application);
}

// ============================================================================
// Починка структуры (инструмент ремонта, журнал 2026-07-11).
// Каждая — под локом, пишет реестр на языке модели, пересобирает снапшот.
// Адреса перепроверяются по ЖИВОЙ структуре: расхождение могло измениться
// с момента показа (человек/другая сессия успели починить) — чиним факт,
// не снимок экрана.
// ============================================================================

/** Взять поле-сироту под управление: строка реестра + дефолтная подпись. */
function configurator_adopt_field(PgSql\Connection $db_connection, string $table, string $field, array $application): array
{
    return configurator_with_lock($db_connection, "Регистрация поля: $table.$field", function () use ($db_connection, $table, $field): array {
        $live = snapshot_build_structure($db_connection);
        $fschema = $live['tables'][$table]['fields'][$field] ?? null;
        if ($fschema === null || ($fschema['kind'] ?? '') !== 'entity_field') {
            return ['ok' => false, 'errors' => ["Поле $table.$field не существует или структурное."]];
        }
        // Уже зарегистрировано? (могли починить между показом и нажатием)
        if (configurator_is_managed($db_connection, 'field', $table, $field)) {
            return ['ok' => false, 'errors' => ["Поле $table.$field уже под управлением."]];
        }

        // Дефолтная подпись = имя поля; осмысленную человек правит в labels.
        $reg = configurator_register_element($db_connection, 'field', $table, $field, $field, $field);
        if (!$reg['ok']) {
            return ['ok' => false, 'errors' => ["Поле $table.$field не зарегистрировано: " . implode('; ', $reg['errors'])]];
        }

        return ['ok' => true, 'errors' => []];
    }, $application);
}

/** Взять таблицу-сироту под управление: строка реестра + дефолтная подпись. */
function configurator_adopt_table(PgSql\Connection $db_connection, string $table, array $application): array
{
    return configurator_with_lock($db_connection, "Регистрация таблицы: $table", function () use ($db_connection, $table): array {
        $live = snapshot_build_structure($db_connection);
        if (!isset($live['tables'][$table])) {
            return ['ok' => false, 'errors' => ["Таблица $table не существует."]];
        }
        $reg = configurator_register_element($db_connection, 'table', null, $table, $table, $table);
        if (!$reg['ok']) {
            return ['ok' => false, 'errors' => ["Таблица $table не зарегистрирована: " . implode('; ', $reg['errors'])]];
        }

        return ['ok' => true, 'errors' => []];
    }, $application);
}

/** Убрать строку реестра по id (призрак или лишний дубль). Подпись —
 *  каскадом FK (ON DELETE CASCADE, §17). */
function configurator_drop_registry_row(PgSql\Connection $db_connection, int $id, array $application): array
{
    return configurator_with_lock($db_connection, "Удаление записи реестра: #$id", function () use ($db_connection, $id): array {
        $outcome = db_execute($db_connection, 'DELETE FROM ' . MODEL_REGISTRY_TABLE . ' WHERE id = ?', 'i', [$id]);
        if ($outcome['affected_rows'] === 0) {
            return ['ok' => false, 'errors' => ["Записи реестра #$id нет (уже убрана?)."]];
        }
        return ['ok' => true, 'errors' => []];
    }, $application);
}

/** Удалить поле-сироту ИЗ БД (единственное, что трогает данные — с
 *  подтверждением на стороне UI). Только entity-поле, только если оно
 *  действительно вне реестра (иначе это управляемое поле — не сюда). */
function configurator_drop_column(PgSql\Connection $db_connection, string $table, string $field, array $application): array
{
    return configurator_with_lock($db_connection, "Удаление колонки: $table.$field", function () use ($db_connection, $table, $field): array {
        $live = snapshot_build_structure($db_connection);
        $fschema = $live['tables'][$table]['fields'][$field] ?? null;
        if ($fschema === null || ($fschema['kind'] ?? '') !== 'entity_field') {
            return ['ok' => false, 'errors' => ["Поле $table.$field не существует или структурное — не удаляю."]];
        }
        // Защита: удаляем только сироту. Под управлением — не наш случай.
        if (configurator_is_managed($db_connection, 'field', $table, $field)) {
            return ['ok' => false, 'errors' => ["Поле $table.$field под управлением — удаление колонки под управлением здесь не делается."]];
        }
        $drop = db_execute($db_connection, 'ALTER TABLE ' . $table . ' DROP COLUMN ' . $field . '');
        if (!$drop['ok']) {
            return ['ok' => false, 'errors' => ['DDL: ' . $drop['error']]];
        }
        return ['ok' => true, 'errors' => []];
    }, $application);
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
function configurator_add_field(PgSql\Connection $db_connection, string $table, array $field_input, array $application): array
{
    return configurator_with_lock($db_connection, "Добавление поля в $table", function () use ($db_connection, $table, $field_input): array {
        $live = snapshot_build_structure($db_connection);
        if (!isset($live['tables'][$table])) {
            return ['ok' => false, 'errors' => ["Таблица $table не существует."]];
        }

        // Разбор поля тем же кирпичом, что валидатор создания (общая
        // configurator_parse_field — не копия проверок). Поля ТЕКУЩЕЙ
        // таблицы передаются как whitelist для calc_ (существующая
        // таблица — единственный контекст, где calc_ вообще доступен).
        $parsed = configurator_parse_field(
            $field_input,
            entities(),
            $live,
            $live['tables'][$table]['fields']
        );
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

        $sql = 'ALTER TABLE ' . $table . ' ADD COLUMN ' . $field['column'] . ' '
             . $field['db_type'] . ' NULL';
        $alter = db_execute($db_connection, $sql);
        if (!$alter['ok']) {
            return ['ok' => false, 'errors' => ['DDL: ' . $alter['error']]];
        }

        $field_reg = configurator_register_element(
            $db_connection, 'field', $table, $field['column'], $field['short'], $field['full']
        );
        if (!$field_reg['ok']) {
            return ['ok' => false, 'errors' => [
                "Поле {$field['column']} добавлено в БД, но не зарегистрировано: "
                . implode('; ', $field_reg['errors']),
            ]];
        }
        $field_id = $field_reg['id'];

        if (($field['link_target'] ?? null) !== null) {
            $link = configurator_register_link($db_connection, $field['column'], $field['link_target']);
            if (!$link['ok']) {
                return ['ok' => false, 'errors' => [
                    "Поле {$field['column']} добавлено, но адрес link_ не записан: " . $link['error'],
                ]];
            }
        }

        if (($field['formula'] ?? null) !== null) {
            $formula_row = configurator_register_formula($db_connection, $field_id, $field['formula']);
            if (!$formula_row['ok']) {
                return ['ok' => false, 'errors' => [
                    "Поле {$field['column']} добавлено, но формула не записана: " . $formula_row['error'],
                ]];
            }
        }

        return ['ok' => true, 'errors' => []];
    }, $application);
}

/**
 * Удалить поле из таблицы ФИЗИЧЕСКИ (DROP COLUMN) + снять регистрацию.
 * Защита данных: если в колонке есть непустые значения, требуется явный
 * флаг $force (подтверждение инженера «удаляю с данными»). Пустая
 * колонка удаляется без флага. Структурные поля (id/dep_/rel_main) не
 * удаляются. Подпись уходит каскадом FK (§17).
 */
function configurator_drop_field(PgSql\Connection $db_connection, string $table, string $field, bool $force, array $application): array
{
    return configurator_with_lock($db_connection, "Удаление поля $table.$field", function () use ($db_connection, $table, $field, $force): array {
        $live = snapshot_build_structure($db_connection);
        $fschema = $live['tables'][$table]['fields'][$field] ?? null;
        if ($fschema === null) {
            return ['ok' => false, 'errors' => ["Поля $table.$field нет."]];
        }
        if (($fschema['kind'] ?? '') !== 'entity_field') {
            return ['ok' => false, 'errors' => ["$table.$field — структурное поле, не удаляется."]];
        }

        // Проверка данных: есть ли непустые значения в колонке.
        $cnt_sql = 'SELECT COUNT(*) AS c FROM ' . $table . ' WHERE ' . $field . ' IS NOT NULL';
        $cnt_rows = db_select($db_connection, $cnt_sql);
        $with_data = (int) ($cnt_rows[0]['c'] ?? 0);
        if ($with_data > 0 && !$force) {
            return ['ok' => false, 'errors' => [
                "В поле $table.$field есть данные ($with_data значений). "
                . "Удаление сотрёт их — подтвердите удаление с данными."
            ]];
        }

        $drop = db_execute($db_connection, 'ALTER TABLE ' . $table . ' DROP COLUMN ' . $field . '');
        if (!$drop['ok']) {
            return ['ok' => false, 'errors' => ['DDL: ' . $drop['error']]];
        }

        // Снять регистрацию (подпись — каскадом FK).
        db_execute($db_connection, 'DELETE FROM ' . MODEL_REGISTRY_TABLE
            . " WHERE data_kind='field' AND data_owner=? AND data_element=?", 'ss', [$table, $field]);

        return ['ok' => true, 'errors' => []];
    }, $application);
}

// ============================================================================
// Разбор запроса
// ============================================================================

/**
 * Диспетчер конфигуратора — вся структурная логика v0 одним входом.
 * 2026-07-17 (STATE.md «Позже», дорожная карта единого входа,
 * стадия 2): вынесено из верхнего уровня файла в функцию, чтобы
 * файл мог служить и самостоятельным скриптом (см. guard в конце
 * файла — «если запущен напрямую»), и библиотекой, вызываемой из
 * index.php по _context (стадия 5, ещё не начата). $application
 * не параметр — берётся из config() внутри, тот же источник,
 * что был раньше, поведение не меняется.
 */
function configurator_dispatch(PgSql\Connection $db_connection): void
{
$caction = (string) ($_POST['_action'] ?? $_GET['_action'] ?? 'list');
$application = config()['application'];

if ($caction === 'create_table' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $live = snapshot_build_structure($db_connection);
    $spec = configurator_validate_spec($_POST, $live);

    if (!$spec['ok']) {
        $msg = implode(' | ', $spec['errors']);
        header('Location: ?_context=configurator&_action=new_table&_msg=' . rawurlencode($msg) . '&_ok=0');
        exit;
    }

    // 2026-07-17: спецификация вьюхи отличима по форме (ключ 'view',
    // не 'table') — configurator_validate_spec уже развела их веткой
    // 'view_filtered' против 'plain'/'dependent'/'voc_simple'.
    if (isset($spec['view'])) {
        $outcome = configurator_create_view($db_connection, $spec, $application);
        $msg     = $outcome['ok'] ? "Представление \"{$spec['view']}\" создано." : implode(' | ', $outcome['errors']);
    } else {
        $outcome = configurator_create_table($db_connection, $spec, $application);
        $msg     = $outcome['ok'] ? "Таблица \"{$spec['table']}\" создана." : implode(' | ', $outcome['errors']);
    }
    header('Location: ?_context=configurator&_action=list&_msg=' . rawurlencode($msg) . '&_ok=' . ($outcome['ok'] ? '1' : '0'));
    exit;
}

if ($caction === 'delete_table' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $table   = (string) ($_POST['table'] ?? '');
    $outcome = configurator_delete_table($db_connection, $table, $application);
    $msg     = $outcome['ok'] ? "Таблица \"$table\" удалена." : implode(' | ', $outcome['errors']);
    header('Location: ?_context=configurator&_action=list&_msg=' . rawurlencode($msg) . '&_ok=' . ($outcome['ok'] ? '1' : '0'));
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
    header('Location: ?_context=configurator&_action=diagnose&_msg=' . rawurlencode($msg) . '&_ok=' . ($outcome['ok'] ? '1' : '0'));
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
    header('Location: ?_context=configurator&_action=edit&table=' . rawurlencode($table)
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
    header('Location: ?_context=configurator&_action=edit&table=' . rawurlencode($table)
         . '&_msg=' . rawurlencode($msg) . '&_ok=' . ($outcome['ok'] ? '1' : '0'));
    exit;
}

// --- отрисовка -----------------------------------------------------------

$flash    = isset($_GET['_msg']) ? render_escape((string) $_GET['_msg']) : null;
$flash_ok = ($_GET['_ok'] ?? '1') === '1';

echo render_admin_page_open('Конфигуратор GPDP', 'configurator');

// Диагностика структуры (журнал 07-11): при заходе считаем расхождения
// БД↔реестр. Плашка-уведомление, если есть — не молча. Раздел «Состояние
// модели» показывает подробно и чинит.
$diag = model_diagnose($db_connection);
render_configurator_page_open($flash, $flash_ok, $diag, $caction);

if ($caction === 'diagnose') {

    render_configurator_diagnose($diag);

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

    // 2026-07-17: словарь-представление — источник (любая таблица с
    // data_name, тот же критерий, что уже у $link_target_options — не
    // дублирую список отдельно, переиспользую) + карта bul_-полей
    // каждой такой таблицы (для JS: при выборе источника показать
    // только ЕЁ булевы колонки — закрытый перечислимый выбор, не
    // текстовое условие, §12).
    $view_source_bul_fields = [];
    foreach ($live_structure['tables'] as $t_name => $t_schema) {
        if (!isset($t_schema['fields']['data_name'])) {
            continue;
        }
        $bul_fields = [];
        foreach ($t_schema['fields'] as $f_name => $f_schema) {
            if (($f_schema['kind'] ?? '') === 'entity_field' && ($f_schema['entity'] ?? '') === 'bul') {
                $f_labels = $live_presentation['labels']['field'][$t_name][$f_name] ?? [];
                $bul_fields[] = ['value' => $f_name, 'label' => (string) ($f_labels['data_full'] ?? $f_name)];
            }
        }
        $view_source_bul_fields[$t_name] = $bul_fields;
    }
    $view_source_bul_fields_json = json_encode($view_source_bul_fields, JSON_UNESCAPED_UNICODE);

    render_configurator_new_table(
        $type_options, $parent_options, $link_target_options,
        $dict_options, $dict_labels_json, $view_source_bul_fields_json
    );

} elseif ($caction === 'edit') {

    // --- правка полей существующей таблицы (ALTER, Волна 2) ---------------
    $table = (string) ($_GET['table'] ?? '');
    $live_structure    = snapshot_build_structure($db_connection);
    $live_presentation = snapshot_build_presentation($db_connection);
    $t_schema = $live_structure['tables'][$table] ?? null;

    if ($t_schema === null || str_starts_with($table, SYSTEM_TABLE_PREFIX)) {
        render_configurator_table_not_found();
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
                $parts[] = 'COUNT(' . $f . ') AS ' . $f;
            }
            $rows = db_select($db_connection, 'SELECT ' . implode(', ', $parts) . ' FROM ' . $table . '');
            $data_counts = $rows[0] ?? [];
        }

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

        // calc_: подсказка переменных — ТОЛЬКО entity-поля ЭТОЙ таблицы
        // (тот же whitelist, что проверит configurator_parse_field()
        // при сохранении, §14.2: недостижимое не предлагается вообще,
        // не отклоняется постфактум).
        $formula_fields = [];
        foreach ($t_schema['fields'] as $f_name => $f_schema) {
            if (($f_schema['kind'] ?? '') === 'entity_field') {
                $fl = $live_presentation['labels']['field'][$table][$f_name] ?? [];
                $formula_fields[$f_name] = (string) ($fl['data_short'] ?? $f_name);
            }
        }
        $formula_fields_json = json_encode($formula_fields, JSON_UNESCAPED_UNICODE);

        render_configurator_edit_table(
            $table, $t_full, $t_schema, $live_presentation, $data_counts,
            $type_options, $dict_options, $link_target_options,
            $dict_labels_json, $formula_fields_json
        );
    }

} elseif ($caction === 'delete_confirm') {

    render_configurator_delete_confirm((string) ($_GET['table'] ?? ''));

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

    render_configurator_directory($snapshot, $referenced_as_dict);
}

render_admin_page_close();
}

// 2026-07-17 (обновлено): теперь чистая библиотека — никакой логики
// диспетчера при прямом обращении, только редирект на верный адрес
// (Влад: «должны были быть просто библиотеками», переходная
// подстраховка сняла себя за ненадобностью — стадии 5-6 подтвердили,
// что index.php справляется сам). Старая закладка/прямой URL получает
// осмысленный redirect, не тихую пустую страницу.
if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    header('Location: index.php?_context=configurator');
    exit;
}
