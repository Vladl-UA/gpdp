<?php
declare(strict_types=1);

/**
 * GPDP / RNA — ядро. То, без чего GPDP не существует.
 *
 * Пространство имён функций (формализовано, см. ARCHITECTURE.md):
 * закрытый словарь семейств ядра + одно открытое множество сущностей.
 *
 *   entity_*    реестр сущностей
 *   field_*     разбор имени, пакет данных, точка исполнения
 *   snapshot_*  модель: сборка, хранение, lock, два пути обновления
 *   action_*    карта «действие пользователя → внутренний режим»
 *   record_*    универсальная запись
 *   lookup_*    (helpers.php) инструменты сущностей
 *   render_*    (render.php) вывод
 *   ent_<id>    (entities.php) паспорта
 *   <id>_*      (entities.php) функции сущности; префикс = префикс поля БД
 *
 * Направление вызовов: index → core → entities → helpers. Не наоборот.
 *
 * Принцип тонкой трубы: все проверки живут на границе подготовки,
 * ровно один раз. Внутрь конвейера недоверенные данные не проходят
 * по построению, поэтому конвейер ничего не перепроверяет.
 *
 * Формула системы:
 *     $result = ($passport['handlers'][$mode])($data, $mode);
 */

// --- Классы функций и структурные элементы -----------------------------------
const ENTITY_FUNCTION_PREFIX = 'ent_';

// Зарезервированные идентификаторы: словарь системы. Сущность не может
// занять имя семейства ядра/слоя — иначе её функции <id>_* въехали бы
// в чужое пространство. Проверяется при сборке реестра (не в горячем пути).
const ENTITY_RESERVED_IDS = [
    'ent', 'sys', 'rel', 'adm', 'chk', 'fmt',          // классы функций
    'entity', 'field', 'snapshot', 'action', 'record',  // семейства ядра
    'lookup', 'render', 'config', 'core', 'index',      // слои и файлы
    'configurator', 'labels', 'context',                // 2026-07-17: единый вход
];

// Структурные элементы — идентичность, иерархия, связи. Не сущности,
// интерпретируются ядром напрямую, НИКОГДА не пишутся обычным save.
// Первичный ключ — id; index остался в археологии.
const STRUCTURAL_PREFIXES    = ['dep_'];
const STRUCTURAL_FIELD_NAMES = ['id', 'rel_main', 'active'];
// 'active' — статус элемента модели (ARCHITECTURE.md §17), допустим в
// служебных таблицах (model_registry и далее); в предметных — ошибка
// модели по доктрине, парсер это не проверяет (не его роль), проверка —
// за конфигуратором/ревью при регистрации предметной таблицы.

/**
 * Служебные таблицы ядра (ARCHITECTURE.md §17: «семейство model_*»).
 * Не предметные данные — не проходят классификацию полей структурного
 * сканера вообще (не entity, не forms, не generic CRUD через ?_table=).
 * Читаются ТОЛЬКО именованными функциями (snapshot_build_registry,
 * snapshot_build_presentation), которые знают точные имена колонок
 * напрямую. Расширяется префиксом: любая будущая model_* таблица
 * автоматически защищена без правки этой константы.
 */
const SYSTEM_TABLE_PREFIX = 'model_';

/**
 * Второй зарезервированный префикс, решение 2026-07-21 (слой
 * представления): паспорт `select_`/`report_` — метаинформация о
 * презентационном слое, не о базовой модели (ДНК/РНК-разделение —
 * model_ обслуживает базовый уровень, не презентационный). Без
 * собственного префикса паспорт `presentation_selections` был бы
 * виден обычному CRUD-контуру как предметная таблица — тот же способ
 * защиты, что уже работает для model_.
 */
const PRESENTATION_TABLE_PREFIX = 'presentation_';

/**
 * Единая проверка «эта таблица зарезервирована ядром, невидима
 * обычному CRUD-контуру» — одна точка правды вместо пяти мест,
 * каждое из которых раньше проверяло str_starts_with($t, SYSTEM_
 * TABLE_PREFIX) по отдельности (найдено при добавлении второго
 * префикса — до этого момента совпадение с одним префиксом сходило
 * за проверку «системная ли таблица», теперь это два раздельных
 * факта, которые пять мест обязаны знать одинаково, не каждое по-своему).
 */
function is_reserved_table(string $table): bool
{
    return str_starts_with($table, SYSTEM_TABLE_PREFIX)
        || str_starts_with($table, PRESENTATION_TABLE_PREFIX);
}

// Служебная таблица подписей — знание платформы, не предметной модели.
const MODEL_REGISTRY_TABLE = 'model_registry';
const MODEL_LABELS_TABLE   = 'model_labels';

// --- Карта действий -----------------------------------------------------------
// Единственная дверь между пользователем и режимами сущностей.
// Пользователь выбирает действие, карта выбирает режим, паспорт — функцию.
// Действия записи (save_new / save_edit / delete) — операции конвейера,
// а не режимы сущностей: index разводит их до этой карты.
const ACTION_MODES = [
    'view' => 'read',
    'new'  => 'new',
    'edit' => 'edit',
];

function action_mode(string $request_action): ?string
{
    return ACTION_MODES[$request_action] ?? null;
}

// ============================================================================
// entity_* — реестр сущностей
// ============================================================================

/**
 * Легальный id сущности: коротко, латиница в нижнем регистре,
 * начинается с буквы, не входит в словарь зарезервированных.
 */
function entity_id_valid(string $entity_id): bool
{
    if (in_array($entity_id, ENTITY_RESERVED_IDS, true)) {
        return false;
    }

    return (bool) preg_match('/^[a-z][a-z0-9]{1,5}$/', $entity_id);
}

/**
 * Сканирует entities.php диффом get_defined_functions().
 * Кандидат — функция ent_*; Reflection страхует от вызова функции,
 * требующей аргументов (паспорт всегда без аргументов).
 */
function entity_registry_load(string $entities_file): array
{
    if (!is_file($entities_file)) {
        return [];
    }

    $before = get_defined_functions()['user'];
    require $entities_file;
    $after = get_defined_functions()['user'];

    $entities = [];

    foreach (array_diff($after, $before) as $function_name) {
        if (!str_starts_with($function_name, ENTITY_FUNCTION_PREFIX)) {
            continue;
        }

        $entity_id = substr($function_name, strlen(ENTITY_FUNCTION_PREFIX));
        if (!entity_id_valid($entity_id)) {
            continue;
        }

        $reflection = new ReflectionFunction($function_name);
        if ($reflection->getNumberOfRequiredParameters() > 0) {
            continue;
        }

        $passport = $function_name();
        if (!is_array($passport) || ($passport['id'] ?? null) !== $entity_id) {
            continue;
        }

        $entities[$entity_id] = $passport;
    }

    return $entities;
}

/** Реестр на текущий запрос: строится один раз, дальше чтение. */
function entities(): array
{
    static $entities = null;

    if ($entities === null) {
        $entities = entity_registry_load(config()['paths']['entities']);
    }

    return $entities;
}

// ============================================================================
// field_* — разбор имени, пакет данных, точка исполнения
// ============================================================================

/**
 * Полный разбор имени поля.
 * kind: 'entity_field' | 'structural' | 'unknown'.
 * unknown — ошибка конфигурации; роняет сборку snapshot (fail-fast
 * на границе), до конвейера такое поле не доживает.
 */
/**
 * Стадия 1 дорожной карты единого входа (STATE.md «Позже», 2026-07-17):
 * закрытый список контекстов запроса — data (рабочий конвейер) /
 * configurator (структура) / labels (подписи и словари). Whitelist,
 * не динамическая интерпретация (`'dispatch_' . $_GET['_context']`) —
 * тот же принцип §12, что уже держит весь конфигуратор: закрытый
 * перечислимый выбор, не текст из запроса напрямую.
 *
 * 2026-07-18 (меню, Влад: «брать пункты меню из контекста — на то он
 * и контекст»): та же константа — источник и для whitelist, и для
 * системного меню (render_context_menu()). Не путать с будущим меню
 * УРОВНЯ ПРЕДСТАВЛЕНИЯ (отчёты/представления) — тот список растёт из
 * записей модели, не хардкода, и не имеет отношения к REQUEST_CONTEXTS
 * вообще: контур запроса ('data'/'configurator'/'labels') и пункт
 * презентационного меню — разные по природе вещи, разные источники.
 */
const REQUEST_CONTEXTS = [
    'data'         => ['icon' => '🏠', 'label' => 'Главная',           'href' => 'index.php'],
    'configurator' => ['icon' => '⚙',  'label' => 'Конфигуратор',      'href' => 'index.php?_context=configurator'],
    'labels'       => ['icon' => '🏷', 'label' => 'Подписи и словари', 'href' => 'index.php?_context=labels'],
];

/**
 * Контекст запроса — определяется РАНЬШЕ snapshot_init() (находка
 * Chat, 2026-07-17, критическая: контур configurator обязан работать,
 * когда снапшот сломан или схема заблокирована, строгий snapshot_init
 * — только для data). Отсутствие параметра = 'data' (старые ссылки
 * без _context не ломаются). Незнакомое значение — null, не тихий
 * фолбэк на 'data': вызывающий код решает, что делать (сейчас —
 * 400, см. index.php).
 */
function request_context(array $get, array $post): ?string
{
    $raw = (string) ($post['_context'] ?? $get['_context'] ?? 'data');
    return isset(REQUEST_CONTEXTS[$raw]) ? $raw : null;
}

function field_parse(string $field_name): array
{
    $parsed = [
        'raw'    => $field_name,
        'kind'   => 'unknown',
        'entity' => null,
        'name'   => null,
    ];

    if (in_array($field_name, STRUCTURAL_FIELD_NAMES, true)) {
        $parsed['kind'] = 'structural';
        $parsed['name'] = $field_name;
        return $parsed;
    }

    foreach (STRUCTURAL_PREFIXES as $prefix) {
        if (str_starts_with($field_name, $prefix)) {
            $parsed['kind'] = 'structural';
            $parsed['name'] = substr($field_name, strlen($prefix));
            return $parsed;
        }
    }

    foreach (array_keys(entities()) as $entity_id) {
        if (str_starts_with($field_name, $entity_id . '_')) {
            $parsed['kind']   = 'entity_field';
            $parsed['entity'] = $entity_id;
            $parsed['name']   = substr($field_name, strlen($entity_id) + 1);
            return $parsed;
        }
    }

    return $parsed;
}

/**
 * Разбор формулы `calc_` в план вычисления — НЕ `template_parse` (§15.8,
 * решение 07-14 по итогам прохода `calc_volume_deviation`): токенайзер
 * похож (тот же синтаксис `{поле}`), но результат — не строка, а
 * арифметика, и первая версия сознательно ПРОЩЕ `template_parse` тоже:
 * строго слева направо, БЕЗ приоритета операторов и БЕЗ скобок — ровно
 * то, что покрывает «план минус факт». Приоритет/скобки — когда придёт
 * формула, которой они реально нужны, не заранее.
 *
 * null — синтаксис нарушен (не строгое чередование `{поле}`/оператор).
 */
function formula_parse(string $formula): ?array
{
    $formula = trim($formula);
    if ($formula === '') {
        return null;
    }

    $pattern = '/^\{([a-z0-9_]+)\}(?:\s*[+\-*\/]\s*\{[a-z0-9_]+\})*$/i';
    if (!preg_match($pattern, $formula)) {
        return null;
    }

    preg_match_all('/\{([a-z0-9_]+)\}|([+\-*\/])/i', $formula, $matches, PREG_SET_ORDER);

    $plan = [];
    foreach ($matches as $match) {
        $plan[] = $match[1] !== ''
            ? ['type' => 'field', 'name' => $match[1]]
            : ['type' => 'op', 'value' => $match[2]];
    }

    return $plan;
}

/**
 * Формулы `calc_` (STATE.md «Позже», согласовано 2026-07-14): читает
 * model_formulas JOIN model_registry (владеющая таблица/поле — по
 * `dep_model_registry`), раскладывает по ТАБЛИЦЕ, не глобально по имени
 * поля, как словари (§15 п.2 — одна и та же формула на разных таблицах
 * имеет разные операнды, глобальный адрес по имени здесь не годится).
 *
 * Whitelist переменных — поля СВОЕЙ ЖЕ владеющей таблицы (§15 п.2/8:
 * первый шаг — не родительская цепочка); ссылка вовне или на
 * несуществующее поле — fail-fast с именем формулы, тот же принцип,
 * что у `dep_` в никуда и цикла словарных шаблонов (§16).
 */
function snapshot_build_formulas(PgSql\Connection $db_connection, array $structure): array
{
    $map        = [];
    $unresolved = [];

    $rows = db_select($db_connection, '
        SELECT f.data_formula, r.data_owner, r.data_element
        FROM model_formulas f
        JOIN model_registry r ON r.id = f.dep_model_registry
    ');

    foreach ($rows as $row) {
        $table   = (string) $row['data_owner'];
        $field   = (string) $row['data_element'];
        $formula = (string) $row['data_formula'];

        $plan = formula_parse($formula);
        if ($plan === null) {
            $unresolved[] = "$table.$field: синтаксис формулы «$formula»";
            continue;
        }

        $table_fields = $structure['tables'][$table]['fields'] ?? null;
        if ($table_fields === null) {
            $unresolved[] = "$table.$field: таблица '$table' не существует";
            continue;
        }

        $bad_token = null;
        foreach ($plan as $step) {
            if ($step['type'] === 'field'
                && ($table_fields[$step['name']]['kind'] ?? '') !== 'entity_field') {
                $bad_token = $step['name'];
                break;
            }
        }
        if ($bad_token !== null) {
            $unresolved[] = "$table.$field: переменная '$bad_token' — не поле таблицы '$table'";
            continue;
        }

        $map[$table][$field] = ['formula' => $formula, 'plan' => $plan];
    }

    return ['map' => $map, 'unresolved' => $unresolved];
}

/**
 * Паспорт select_ (presentation_selections, слой представления,
 * решение 2026-07-21): проверяет, что источник, отобранные поля и
 * поле фильтра существуют и являются entity-полями ИМЕННО источника
 * (§15 п.2 формул — тот же принцип: whitelist из полей своей же
 * таблицы, не чужой цепочки). null — паспорт не разрешился, причину
 * называет вызывающий (snapshot_build_selections/
 * configurator_create_selection), не эта функция — она чистый
 * структурный тест, к БД не обращается вовсе.
 *
 * $filter_field, если задан, СОЗНАТЕЛЬНО ограничен словарным полем
 * (voc_/links_) — v1 умеет только «= конкретное значение словаря»,
 * как в первом примере (voc_status = 'В работе'). Фильтр по сырому
 * значению обычного поля (data_/int_/dec_/date_) — не забыт, а прямо
 * отложен: там своя типовая развилка приведения значения, которую
 * рано решать до первого работающего случая (WORKLOG.md 2026-07-21,
 * шаг 1 из шести).
 */
function selection_parse(array $structure, string $source_table, string $columns, ?string $filter_field): ?array
{
    $table_fields = $structure['tables'][$source_table]['fields'] ?? null;
    if ($table_fields === null) {
        return null;
    }

    $column_list = array_values(array_filter(
        array_map('trim', explode(',', $columns)),
        fn(string $c): bool => $c !== ''
    ));
    if ($column_list === []) {
        return null;
    }
    foreach ($column_list as $col) {
        if (($table_fields[$col]['kind'] ?? '') !== 'entity_field') {
            return null;
        }
    }

    if ($filter_field !== null) {
        if (($table_fields[$filter_field]['kind'] ?? '') !== 'entity_field') {
            return null;
        }
        $prefix = field_prefix($filter_field);
        // Фильтруемо: voc_ (склад/адрес/проекция) и link_ (единственное,
        // скаляр int, voc_handler). НЕ links_ (множественное) — хранится
        // как integer[] (entities.php, ent_links), простое «= значение»
        // для массива синтаксически не то, что нужно (нужен ANY()/@> —
        // другая операция, не эта форма фильтра). Ошибка поймана до
        // коммита при сверке физического хранения links_, не после.
        if ($prefix !== 'voc' && $prefix !== 'link') {
            return null;
        }
    }

    return [
        'source_table' => $source_table,
        'columns'      => $column_list,
        'filter_field' => $filter_field,
    ];
}

/**
 * Компилирует паспорта select_ в план — тот же приём, что
 * snapshot_build_formulas(): читает presentation_selections JOIN
 * model_registry (имя select_-представления — не source_table),
 * сверяет каждый паспорт с $structure через selection_parse().
 * НЕ резолвит filter_value в id (для этого нужна БД и живой словарь,
 * не только структура) — тот шаг делает
 * configurator_create_selection() при создании/пересборке; здесь
 * только структурная валидность, тот же принцип разделения, что у
 * formula_parse (чистый разбор) / snapshot_build_formulas (разбор +
 * проверка по модели).
 *
 * НЕ вызывается из snapshot_init() пока — select_-представления после
 * создания читаются как обычные зарегистрированные таблицы, обычным
 * контуром (record_list и т.п.), этот план сегодня нужен только
 * инструментам контроля (диагностика, пересборка после дрейфа
 * модели — см. разговор 2026-07-21), которых ещё нет. Заводить лишний
 * вызов в горячем пути без потребителя — §12/§15 п.8.
 */
function snapshot_build_selections(PgSql\Connection $db_connection, array $structure): array
{
    $map        = [];
    $unresolved = [];

    $rows = db_select($db_connection, '
        SELECT r.data_element AS select_table, s.source_table, s.columns, s.filter_field, s.filter_value
        FROM presentation_selections s
        JOIN model_registry r ON r.id = s.dep_model_registry
    ');

    foreach ($rows as $row) {
        $select_table = (string) $row['select_table'];
        $filter_field = $row['filter_field'] !== null ? (string) $row['filter_field'] : null;

        $plan = selection_parse($structure, (string) $row['source_table'], (string) $row['columns'], $filter_field);
        if ($plan === null) {
            $unresolved[] = "$select_table: паспорт не разрешился (источник/поля/фильтр разошлись со структурой)";
            continue;
        }

        $plan['filter_value']  = $row['filter_value'] !== null ? (string) $row['filter_value'] : null;
        $map[$select_table] = $plan;
    }

    return ['map' => $map, 'unresolved' => $unresolved];
}

/** Совместимая обёртка: только идентификатор сущности/структурного элемента. */
function field_prefix(string $field_name): ?string
{
    $parsed = field_parse($field_name);

    return match ($parsed['kind']) {
        'entity_field' => $parsed['entity'],
        'structural'   => $parsed['raw'],
        default        => null,
    };
}

/**
 * Сборка доверенного пакета $data — часть подготовки задания.
 * Здесь проходит граница: имя поля сверяется со snapshot, подпись
 * (model_labels, §17) кладётся КАК ЕСТЬ (колонку выбирает потребитель).
 * null — подсказка из request не подтвердилась моделью.
 */
function field_data(
    array $snapshot,
    PgSql\Connection $db_connection,
    string $table,
    string $field_name,
    mixed $value = null,
    ?array $row = null
): ?array {
    $field_schema = $snapshot['structure']['tables'][$table]['fields'][$field_name] ?? null;
    if ($field_schema === null || $field_schema['kind'] !== 'entity_field') {
        return null;
    }

    return [
        'table' => $table,
        'field' => [
            'raw'    => $field_name,
            'entity' => $field_schema['entity'],
            'name'   => substr($field_name, strlen($field_schema['entity']) + 1),
            'subscr' => $snapshot['presentation']['labels']['field'][$table][$field_name] ?? [],
            // Скомпилированный словарный адрес (§16.1): разрешён при
            // сборке снапшота, рантайм из имени НЕ выводит.
            // null — поле словарь не адресует.
            'source' => $snapshot['model']['dictionaries'][$field_name]['source_table'] ?? null,
            // Полная скомпилированная запись словаря (§16.1): для
            // склада/адреса — источник, для проекции — ещё и план
            // сборки подписи. Хендлер самодостаточен, карта целиком
            // ему не нужна.
            'dict'   => $snapshot['model']['dictionaries'][$field_name] ?? null,
            // Скомпилированная формула calc_ (§15, решение 07-14):
            // по ТАБЛИЦЕ, не по имени поля глобально — см. docblock
            // snapshot_build_formulas(). null — формула не задана.
            'formula' => $snapshot['model']['formulas'][$table][$field_name] ?? null,
            'schema' => [
                'db_type'  => $field_schema['db_type'],
                'nullable' => $field_schema['nullable'],
                'key'      => $field_schema['key'],
            ],
        ],
        'value' => $value,
        'row'   => $row,
        'db'    => $db_connection, // для helper'ов (lookup_*); сырой SQL из request запрещён
    ];
}

/**
 * Точка исполнения — тонкая труба. Вся GPDP в пределе:
 *
 *     $handler($data, $mode)
 *
 * Никаких проверок внутри: пакет собран field_data() (граница пройдена),
 * режим рождён action_mode() или литералом конвейера записи — путей,
 * которыми сюда попали бы недоверенные данные, не существует.
 * Callable — только из паспорта.
 *
 * null — сущность не заявила handler для режима; что это значит,
 * решает вызывающий код.
 */
function field_exec(array $data, string $mode): ?array
{
    $handler = entities()[$data['field']['entity']]['handlers'][$mode] ?? null;

    return $handler === null ? null : $handler($data, $mode);
}

// ============================================================================
// snapshot_* — модель: сборка, хранение, lock, два пути обновления
// ============================================================================


/** Минимальная валидность: единая точка для построенного и прочитанного. */
function snapshot_validate(array $snapshot): bool
{
    if (!isset($snapshot['structure']['tables']) || !is_array($snapshot['structure']['tables'])) {
        return false;
    }
    if (!isset($snapshot['presentation']) || !is_array($snapshot['presentation'])) {
        return false;
    }
    // Файл до-словарной эпохи (нет model.dictionaries) невалиден:
    // load вернёт null → bootstrap пересоберёт. Иначе voc-поля
    // получили бы source=null и упали бы SQL-ошибкой в глубине.
    if (!isset($snapshot['model']['dictionaries']) || !is_array($snapshot['model']['dictionaries'])) {
        return false;
    }
    // Аналогично: файл до-графовой эпохи (нет model.relations) →
    // пересборка на bootstrap. snapshot_validate растёт вместе
    // с форматом (урок журнала 07-08 про ископаемый снапшот).
    if (!isset($snapshot['model']['relations']) || !is_array($snapshot['model']['relations'])) {
        return false;
    }
    // Файл до-формульной эпохи (нет model.formulas, 07-14) — иначе
    // calc_-поля получили бы formula=null молча вместо пересборки.
    if (!isset($snapshot['model']['formulas']) || !is_array($snapshot['model']['formulas'])) {
        return false;
    }

    return count($snapshot['structure']['tables']) > 0;
}

/**
 * Structural-слой: живое чтение структуры БД. Допустимо только при
 * bootstrap, явной пересборке и в контуре конфигуратора.
 */
function snapshot_build_structure(PgSql\Connection $db_connection): array
{
    $tables         = [];
    $unknown_fields = [];

    // 2026-07-16: Postgres, information_schema вместо SHOW TABLES/COLUMNS
    // (журнал STATE.md «Сейчас» п.9).
    //
    // 2026-07-20: переведено на db_select(). Здесь долго стояло
    // исключение «единственное место вне db.php — интроспекция схемы,
    // другой формат возврата». Под MySQL это была правда: SHOW TABLES /
    // SHOW COLUMNS возвращали не то, что обычный SELECT. После переезда
    // на Postgres это обычные SELECT из information_schema, и db_select
    // отдаёт ровно тот assoc-массив, который нужен циклам. Обоснование
    // исключения истекло при миграции, а само исключение пережило её,
    // потому что никто не перечитал причину. Отказ запроса теперь даёт
    // пустой список вместо громкой ошибки — но fail-fast сохранён на
    // прежнем месте: snapshot_validate() отвергает структуру с нулём
    // таблиц, то есть пустая интроспекция валит сборку, а не проходит
    // молча.
    //
    // 2026-07-16 (продолжение): table_type IN ('BASE TABLE','VIEW') —
    // раньше pg_catalog.pg_tables видел только настоящие таблицы,
    // представления (VIEW) в структуре не появлялись вовсе. Резолвер
    // словарей (snapshot_build_dictionaries) сам по себе VIEW-агностичен
    // — ему достаточно видеть id+data_name, таблица это или
    // представление, ему всё равно (§16.1, а1) — единственным
    // недостающим звеном была именно эта интроспекция. object_type
    // сохраняется в структуре для будущего использования там, где
    // разница всё же важна (DROP TABLE vs DROP VIEW при удалении).
    $table_rows = db_select(
        $db_connection,
        "SELECT table_name, table_type FROM information_schema.tables "
        . "WHERE table_schema = 'public' AND table_type IN ('BASE TABLE', 'VIEW') "
        . "ORDER BY table_name"
    );
    foreach ($table_rows as $table_row) {
        $table_name  = $table_row['table_name'];
        $object_type = $table_row['table_type'] === 'VIEW' ? 'view' : 'table';

        // Служебные таблицы ядра (model_*) — не предметные данные,
        // классификации полей не подлежат вообще (см. константу выше).
        if (is_reserved_table($table_name)) {
            continue;
        }

        $fields = [];

        // Первичный ключ таблицы — отдельным запросом (information_schema
        // не даёт "Key"-колонку одной строкой, как SHOW COLUMNS у MySQL).
        $pk_rows = db_select(
            $db_connection,
            'SELECT a.attname FROM pg_index i '
            . 'JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey) '
            . 'WHERE i.indrelid = ?::regclass AND i.indisprimary',
            's',
            [$table_name]
        );
        $pk_columns = [];
        foreach ($pk_rows as $pk_row) {
            $pk_columns[$pk_row['attname']] = true;
        }

        $column_rows = db_select(
            $db_connection,
            'SELECT column_name, data_type, is_nullable FROM information_schema.columns '
            . 'WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
            'ss',
            ['public', $table_name]
        );
        foreach ($column_rows as $column_row) {
            $field_name = $column_row['column_name'];
            $parsed     = field_parse($field_name);

            if ($parsed['kind'] === 'unknown') {
                $unknown_fields[] = "$table_name.$field_name";
            }

            $fields[$field_name] = [
                'name'     => $field_name,
                'kind'     => $parsed['kind'],
                'entity'   => $parsed['entity'],
                'db_type'  => $column_row['data_type'],
                'nullable' => $column_row['is_nullable'] === 'YES',
                'key'      => isset($pk_columns[$field_name]) ? 'PRI' : '',
            ];
        }

        $tables[$table_name] = [
            'name'        => $table_name,
            'object_type' => $object_type,
            'fields'      => $fields,
        ];
    }

    return ['tables' => $tables, 'unknown_fields' => $unknown_fields];
}

/**
 * Разбор строки-шаблона (§16.3): '{voc_area} №{data_number}' →
 * последовательность literal|token. Чистая функция СИНТАКСИСА:
 * что означает токен — решает потребитель. Сегодня потребитель один —
 * компилятор словарных проекций; названное будущее (журнал 07-08,
 * не заготовка) — паспорта формул math_: сестринское семейство
 * разделяет синтаксис ссылки {поле}, но не семантику.
 *
 * Непарная скобка — ошибка синтаксиса, не литерал: опечатка ручного
 * SQL падает громко на границе сборки, а не тихо кривой подписью.
 */
function template_parse(string $template): array
{
    $parts = preg_split('/\{([a-z0-9_]+)\}/i', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
    $items = [];

    foreach ($parts as $i => $part) {
        if ($i % 2 === 1) { // нечётные — имена токенов из capture-группы
            $items[] = ['kind' => 'token', 'name' => $part];
            continue;
        }
        if ($part === '') {
            continue;
        }
        if (str_contains($part, '{') || str_contains($part, '}')) {
            return ['items' => [], 'error' => "непарная скобка около «{$part}»"];
        }
        $items[] = ['kind' => 'literal', 'value' => $part];
    }

    return ['items' => $items, 'error' => null];
}

function snapshot_build_dictionaries(array $structure, array $templates = [], array $links = []): array
{
    $map        = [];
    $unresolved = [];

    // Общая часть резолвера (журнал 2026-07-12, вынесена ради link_):
    // источник уже определён — по лестнице §16.1 для voc_, по явной
    // записи model_links для link_ — дальше путь один: маяк шаблона
    // (проекция) сильнее конвенции data_name (склад/адрес/link).
    //
    // Унификация (решение 2026-07-11, исполнено 2026-07-13): data_name —
    // частный случай шаблона из ОДНОГО куска, не особое правило. Нет
    // явного маяка, но есть data_name → синтезируем шаблон-по-умолчанию
    // `{data_name}` и идём ТЕМ ЖЕ путём проекции, что и составные подписи.
    // Была вторая машинка (`subtype`='warehouse'/'address'/'link' без
    // плана, читал lookup_options жёстким SELECT) — убрана: одна
    // форма записи в карте на все словари, `$subtype` больше не влияет
    // на форму, только на текст диагностики при отказе.
    $resolve = function (string $field_name, string $source, string $subtype) use (
        &$map, &$unresolved, &$templates, $structure
    ): void {
        // Маяк (§16.1 п.2): непустой шаблон на источнике — проекция.
        // Явный маяк СИЛЬНЕЕ конвенции data_name: проставленный
        // человеком шаблон не может молча игнорироваться.
        if (!isset($templates[$source])) {
            if (!isset($structure['tables'][$source]['fields']['data_name'])) {
                $unresolved[] = $subtype === 'warehouse'
                    ? "$field_name: таблица-склад `$source` повреждена (нет data_name, нет шаблона)"
                    : "$field_name: таблице `$source` нужен паспорт словаря (нет data_name, нет шаблона)";
                return;
            }
            // Синтетический маяк — не читается ниоткуда, не сохраняется;
            // только на время этой сборки снапшота. Кладём в общий
            // $templates (по ссылке) — проход 2 (компиляция плана)
            // подхватит его как обычный, не отличая от настоящего.
            $templates[$source] = '{data_name}';
        }

        $map[$field_name] = [
            'source_table' => $source,
            'label_column' => null,
            'subtype'      => 'projection',
            'template'     => $templates[$source],
        ];
    };

    // ---- Проход 1: voc_ — источник по лестнице §16.1 ----
    foreach ($structure['tables'] as $table) {
        foreach ($table['fields'] as $field_name => $field) {
            if (($field['kind'] ?? '') !== 'entity_field' || ($field['entity'] ?? '') !== 'voc') {
                continue;
            }
            if (isset($map[$field_name])) {
                continue; // одно имя = один смысл: уже разрешено у другого владельца
            }

            $bare = substr($field_name, strlen($field['entity']) + 1); // voc_area → area

            // Кандидат-источник: склад (voc_x) прежде адреса (x) —
            // порядок ступеней (а1) не меняется.
            if (isset($structure['tables'][$field_name])) {
                $resolve($field_name, $field_name, 'warehouse');
            } elseif (isset($structure['tables'][$bare])) {
                $resolve($field_name, $bare, 'address');
            } else {
                $unresolved[] = "$field_name: неизвестный адрес словаря (нет ни `$field_name`, ни `$bare`)";
            }
        }
    }

    // ---- Проход 1б: link_/links_ — источник ЯВНО из model_links (журнал
    // 07-12, расширено 07-17 для links_) ----
    // Тот же резолвер (маяк/data_name) — другой способ узнать $source:
    // не вычисляется из имени поля, читается из явной записи. Нужно,
    // потому что двум полям на одной таблице требуется один и тот же
    // адрес под разными именами и ролями (Идея А — «любимый цвет» /
    // «нелюбимый цвет» → оба voc_color); имя может адресовать только
    // одно место, значит адрес для link_ не в имени, а в записи.
    // links_ (2026-07-17) переиспользует ровно тот же адресный
    // механизм (configurator_register_link/model_links) — отличие
    // только в кратности хранения (integer[] вместо int), не в
    // способе узнать источник, поэтому здесь один и тот же проход,
    // не отдельная копия.
    // Глобально по имени поля — та же конвенция «одно имя = один
    // смысл» (§16.1), что у словарей.
    foreach ($structure['tables'] as $table) {
        foreach ($table['fields'] as $field_name => $field) {
            $field_entity = $field['entity'] ?? '';
            if (($field['kind'] ?? '') !== 'entity_field' || ($field_entity !== 'link' && $field_entity !== 'links')) {
                continue;
            }
            if (isset($map[$field_name])) {
                continue;
            }

            $source = $links[$field_name] ?? null;
            if ($source === null) {
                $unresolved[] = "$field_name: адрес не задан (нет записи в model_links)";
                continue;
            }
            if (!isset($structure['tables'][$source])) {
                $unresolved[] = "$field_name: целевая таблица `$source` не существует";
                continue;
            }

            $resolve($field_name, $source, 'link');
        }
    }

    // ---- Проход 2: компиляция планов проекций + циклодетект §16.5 ----
    // План — последовательность [literal|field|dict]. Токен {имя_поля}
    // ссылается ТОЛЬКО на поле той же строки источника (граница объёма
    // (а2), журнал 07-08); токены сверяются с whitelist структуры —
    // fail-fast на несуществующее поле, та же дисциплина, что
    // «нужен паспорт». Цикл (шаблон А → словарь Б → шаблон Б → А) —
    // fail-fast сборки с перечнем цепочки.
    $plans     = [];
    $visiting  = [];
    $attempted = []; // источники, на которых compile() уже завершился
                      // (успехом ИЛИ провалом) — без этого дособорка
                      // '@table' повторно вызывает compile() на уже
                      // проваленном источнике и дублирует ошибку

    $compile = function (string $source) use (&$compile, &$plans, &$visiting, &$attempted, &$unresolved, &$templates, $structure, $map): bool {
        if (isset($plans[$source])) {
            return true;
        }
        if (isset($visiting[$source])) {
            $unresolved[] = 'цикл шаблонов: ' . implode(' → ', array_keys($visiting)) . " → $source";
            return false;
        }
        if (isset($attempted[$source])) {
            return false; // завершённая РАНЕЕ (не текущая) попытка провалилась —
                           // ошибка уже в unresolved, не дублируем. Проверка идёт
                           // ПОСЛЕ visiting: иначе циклический повторный вызов
                           // (source ещё в работе, attempted уже true) гасится
                           // здесь молча, не долетая до детекта цикла выше.
        }
        $attempted[$source] = true;
        $visiting[$source]  = true;

        $plan   = [];
        $clean  = true;
        $parsed = template_parse($templates[$source]);

        if ($parsed['error'] !== null) {
            $unresolved[] = "шаблон `$source`: {$parsed['error']}";
            unset($visiting[$source]);
            return false;
        }

        foreach ($parsed['items'] as $item) {
            if ($item['kind'] === 'literal') {
                $plan[] = ['kind' => 'literal', 'value' => $item['value']];
                continue;
            }

            $token       = $item['name'];
            $token_field = $structure['tables'][$source]['fields'][$token] ?? null;

            if ($token_field === null) {
                $unresolved[] = "шаблон `$source`: поле `{$token}` не существует в источнике";
                $clean        = false;
                continue;
            }

            if (($token_field['kind'] ?? '') === 'entity_field' && ($token_field['entity'] ?? '') === 'voc') {
                if (!isset($map[$token])) {
                    // словарь-токен не разрешился проходом 1 — его причина
                    // уже в unresolved, здесь не дублируем, только помечаем
                    $clean = false;
                    continue;
                }
                if ($map[$token]['subtype'] === 'projection' && !$compile($map[$token]['source_table'])) {
                    $clean = false;
                    continue;
                }
                $plan[] = ['kind' => 'dict', 'field' => $token];
                continue;
            }

            $plan[] = ['kind' => 'field', 'field' => $token];
        }

        unset($visiting[$source]);

        if (!$clean) {
            return false;
        }
        $plans[$source] = $plan;
        return true;
    };

    foreach ($map as $entry) {
        if ($entry['subtype'] === 'projection') {
            $compile($entry['source_table']);
        }
    }

    // Таблицы с маяком, на которые НИКТО не ссылается voc-полем, в
    // проход 1 не попали (его вход — voc-поля). Но подпись СОБСТВЕННОГО
    // объекта (карточка, список, «к чему привязан») не должна зависеть
    // от наличия входящих ссылок. Дособираем их под ключом '@table' —
    // '@' невозможен в имени поля, коллизии с voc-ключами исключены;
    // record_label адресует подпись объекта именно по '@'.($table).
    //
    // Унификация 07-13 распространяется и сюда: раньше только таблицы
    // с ЯВНЫМ шаблоном получали self-label здесь, а простые (только
    // data_name) — обходились отдельным путём в record_label() через
    // lookup_options напрямую (та же вторая машинка, что и у полей).
    // Теперь — тем же способом, что $resolve: нет шаблона, но есть
    // data_name → синтезируем `{data_name}`. record_label() больше не
    // нуждается в собственном обходном пути.
    foreach ($structure['tables'] as $table_name => $table_schema) {
        $self_key = '@' . $table_name;
        if (isset($map[$self_key])) {
            continue;
        }
        $tpl = $templates[$table_name] ?? null;
        if ($tpl === null) {
            if (!isset($table_schema['fields']['data_name'])) {
                continue; // подписаться нечем — record_label() падает на "#id", законно
            }
            $tpl = $templates[$table_name] = '{data_name}';
        }
        if ($compile($table_name)) {
            $map[$self_key] = [
                'source_table' => $table_name,
                'label_column' => null,
                'subtype'      => 'projection',
                'template'     => $tpl,
            ];
        }
    }

    if ($unresolved !== []) {
        return ['map' => $map, 'unresolved' => $unresolved];
    }

    // ---- Вложение дочерних записей в dict-шаги плана ----
    // Ацикличность доказана компиляцией → рекурсия конечна. Каждая
    // запись самодостаточна: исполнителю (lookup_labels) не нужна вся
    // карта — только своя запись из пакета field_data.
    $embedded = [];
    $embed    = function (string $voc_field) use (&$embed, &$embedded, &$map, $plans): array {
        if (isset($embedded[$voc_field])) {
            return $embedded[$voc_field];
        }
        $entry = $map[$voc_field];
        if ($entry['subtype'] === 'projection') {
            $plan = [];
            foreach ($plans[$entry['source_table']] as $item) {
                if ($item['kind'] === 'dict') {
                    $item['dict'] = $embed($item['field']);
                }
                $plan[] = $item;
            }
            $entry['plan'] = $plan;
        }
        return $embedded[$voc_field] = $entry;
    };

    foreach (array_keys($map) as $voc_field) {
        $map[$voc_field] = $embed($voc_field);
    }

    return ['map' => $map, 'unresolved' => []];
}

/**
 * Model-слой: граф связей (STATE.md «Сейчас» п.3, шаг 2).
 *
 * Чистая функция над УЖЕ собранной структурой — ноль SHOW. Легаси
 * find_dep() перебирал ВСЕ таблицы рантаймом на каждый чих
 * («с кучей запросов, зато да») — здесь граф компилируется один раз
 * на границе сборки, рантайм читает готовый индекс (§8).
 *
 * Семантика (журнал 07-08, по разбору легаси param.php/button_new,
 * не только дампу):
 *   dep_<parent> — FK на строке-ПОТОМКА → непосредственный родитель,
 *     ставит ссылающуюся таблицу в подчинение: связь ВКЛЮЧЕНИЯ
 *     в дерево владения. record_children читает по ней.
 *   rel_main     — связь ПРИНАДЛЕЖНОСТИ корневому досье, ШИРЕ дерева:
 *     не только сквозной доступ к корню для записей на dep_-линии, но и
 *     БОКОВЫЕ таблицы вне линии (пласты, замеры, примечания) — те, что
 *     относятся к центральной записи, но не являются ничьим прямым
 *     ребёнком. record_children их НЕ видит и не должен; будущая
 *     record_root_related по relations_root — «Позже». `main` здесь не
 *     модель, а единственный системный корень: rel_main — частный случай
 *     rel_<root>, обобщается при мультикорневости (см. STATE.md «Позже»).
 *     Сейчас компилируется, но НЕ рендерится.
 *
 * map:  [parent => [ ['child' => ..., 'fk' => 'dep_parent'], ... ]]
 * root: [table, ...] — кто несёт rel_main (боковые + линейные вперемешку).
 * dep_ на несуществующую таблицу — fail-fast (конфигуратор не должен
 * был позволить; ручной SQL падает громко, та же дисциплина, что
 * у словарей).
 */
function snapshot_build_relations(array $structure): array
{
    $map        = [];
    $root       = [];
    $unresolved = [];

    foreach ($structure['tables'] as $table_name => $table) {
        foreach ($table['fields'] as $field_name => $field) {
            if (($field['kind'] ?? '') !== 'structural') {
                continue;
            }
            if ($field_name === 'rel_main') {
                $root[] = $table_name;
                continue;
            }
            if (!str_starts_with($field_name, 'dep_')) {
                continue;
            }

            $parent = substr($field_name, 4);
            if (!isset($structure['tables'][$parent])) {
                $unresolved[] = "$table_name.$field_name: родительская таблица `$parent` не существует";
                continue;
            }

            $map[$parent][] = ['child' => $table_name, 'fk' => $field_name];
        }
    }

    return ['map' => $map, 'root' => $root, 'unresolved' => $unresolved];
}

/**
 * Model-слой: адресное пространство модели (ARCHITECTURE.md §17).
 * Ключ — полный адрес реестра (data_kind, data_owner, data_element).
 * Только active=1: неактивный элемент исключается из компиляции здесь,
 * а не проверкой постфактум — то и есть смысл §17 «active=0 исключает
 * из model/presentation-компиляции».
 *
 * $structure передаётся для лёгкого аудита сирот (доктрина: мусор
 * реестра инертен, в отчёт сборки, НЕ fail-fast) — сирота реестра
 * никого не блокирует, пока на неё никто не ссылается (§17).
 */
function snapshot_build_registry(PgSql\Connection $db_connection, array $structure): array
{
    $registry = ['table' => [], 'field' => []];
    $orphans  = [];

    $sql = 'SELECT id, data_kind, data_owner, data_element FROM '
         . MODEL_REGISTRY_TABLE . ' WHERE active = 1';
    $rows = db_select($db_connection, $sql);

    foreach ($rows as $row) {
        $kind    = $row['data_kind'];
        $owner   = $row['data_owner'];
        $element = $row['data_element'];

        if ($kind === 'table') {
            $registry['table'][$element] = $row;
            if (!isset($structure['tables'][$element])) {
                $orphans[] = "table:$element";
            }
        } elseif ($kind === 'field') {
            $registry['field'][$owner][$element] = $row;
            if (!isset($structure['tables'][$owner]['fields'][$element])) {
                $orphans[] = "field:$owner.$element";
            }
        }
        // неизвестный data_kind: инертен, не наш случай сегодня (§17 —
        // новые kind вводятся решением, не впрок) — просто игнорируется.
    }

    return ['map' => $registry, 'orphans' => $orphans];
}

/**
 * Presentation-слой: подписи (ARCHITECTURE.md §17 model_labels).
 * JOIN с реестром — не для отображения, а чтобы получить полный адрес
 * (kind/owner/element) для ключа: сама labels хранит только FK.
 * Ключи — той же формы, что у snapshot_build_registry(), той же
 * причине: потребитель ищет подпись и адрес по одному пути.
 * Только active=1 — та же компиляционная граница, что у реестра.
 */
function snapshot_build_presentation(PgSql\Connection $db_connection): array
{
    $labels = ['table' => [], 'field' => []];

    $sql = 'SELECT r.data_kind, r.data_owner, r.data_element,
                    l.data_short, l.data_full, l.data_label_template
             FROM ' . MODEL_LABELS_TABLE . ' l
             JOIN ' . MODEL_REGISTRY_TABLE . ' r
               ON r.id = l.dep_model_registry
             WHERE r.active = 1';

    foreach (db_select($db_connection, $sql) as $row) {
        $kind    = $row['data_kind'];
        $owner   = $row['data_owner'];
        $element = $row['data_element'];

        if ($kind === 'table') {
            $labels['table'][$element] = $row;
        } elseif ($kind === 'field') {
            $labels['field'][$owner][$element] = $row;
        }
    }

    return ['labels' => $labels];
}

/**
 * Маяки составных подписей (§16.1 п.2): непустой data_label_template
 * на строке data_kind='table'. Маяк — данные (model_labels), не
 * структура: потому presentation читается ДО резолвера словарей.
 */
function snapshot_templates(array $presentation): array
{
    $templates = [];

    foreach ($presentation['labels']['table'] ?? [] as $table_name => $label_row) {
        $template = trim((string) ($label_row['data_label_template'] ?? ''));
        if ($template !== '') {
            $templates[$table_name] = $template;
        }
    }

    return $templates;
}

/**
 * Явные адреса link_-полей (журнал 2026-07-12): имя поля → целевая
 * таблица. Глобально по имени — та же конвенция, что у словарей
 * (§16.1, «одно имя = один смысл»): не по строке реестра, не по
 * владеющей таблице. Источник — model_links, системная таблица вне
 * обычного цикла конфигуратора (как model_registry/model_labels).
 * Отсутствие таблицы (старая установка до этого решения) — не падаем,
 * просто link_-полей в структуре не будет, снапшот соберётся без них.
 */
function snapshot_build_links(PgSql\Connection $db_connection): array
{
    $links = [];
    foreach (db_select($db_connection, 'SELECT data_element, data_target_table FROM model_links') as $row) {
        $links[(string) $row['data_element']] = (string) $row['data_target_table'];
    }

    return $links;
}

/**
 * Полная сборка. $application — параметры приложения (root_table и т.п.):
 * принадлежат модели, приходят снаружи, здесь не зашиты.
 * null при unknown-полях — fail-fast, причина в snapshot_last_error().
 */
function snapshot_build(PgSql\Connection $db_connection, array $application = []): ?array
{
    $structure = snapshot_build_structure($db_connection);

    if ($structure['unknown_fields'] !== []) {
        snapshot_last_error(
            'Поля без сущности: ' . implode(', ', $structure['unknown_fields'])
            . '. Конфигуратор не должен был позволить их создать.'
        );
        return null;
    }

    // Presentation читается ДО резолвера словарей: маяки проекций
    // (data_label_template) — данные model_labels, не структура (§16.1).
    $presentation = snapshot_build_presentation($db_connection);

    // Словарные адреса разрешаются здесь, на границе сборки (§16.1).
    // Fail-fast — ВРЕМЕННАЯ жёсткость под гарантию конфигуратора
    // (он не даёт создать voc-поле без источника; ручной SQL падает
    // громко, а не тихо врёт). Стратегически ошибка отдельного
    // элемента модели → локальная деградация, не 503 (журнал 07-07).
    $dictionaries = snapshot_build_dictionaries(
        ['tables' => $structure['tables']],
        snapshot_templates($presentation),
        snapshot_build_links($db_connection)
    );

    if ($dictionaries['unresolved'] !== []) {
        snapshot_last_error(
            'Словари не разрешены: ' . implode('; ', $dictionaries['unresolved'])
            . '. Конфигуратор не должен был позволить такое состояние.'
        );
        return null;
    }

    $registry = snapshot_build_registry($db_connection, ['tables' => $structure['tables']]);

    // Граф связей — та же граница сборки, что словари: fail-fast на
    // dep_ в никуда, рантайм читает готовый индекс.
    $relations = snapshot_build_relations(['tables' => $structure['tables']]);
    if ($relations['unresolved'] !== []) {
        snapshot_last_error(
            'Связи не разрешены: ' . implode('; ', $relations['unresolved'])
            . '. Конфигуратор не должен был позволить такое состояние.'
        );
        return null;
    }

    // Формулы calc_ — та же граница сборки, что словари и связи:
    // fail-fast на синтаксис/переменную вне своей таблицы, рантайм
    // читает готовый план (§15, решение 07-14).
    $formulas = snapshot_build_formulas($db_connection, ['tables' => $structure['tables']]);
    if ($formulas['unresolved'] !== []) {
        snapshot_last_error(
            'Формулы не разрешены: ' . implode('; ', $formulas['unresolved'])
            . '. Конфигуратор не должен был позволить такое состояние.'
        );
        return null;
    }

    return [
        'generated_at' => date('Y-m-d H:i:s'),
        'structure'    => ['tables' => $structure['tables']],
        'model'        => [
            'registry'       => $registry['map'],
            'dictionaries'   => $dictionaries['map'],
            'relations'      => $relations['map'],
            'relations_root' => $relations['root'],
            'formulas'       => $formulas['map'],
        ],
        'presentation' => $presentation,
        'application'  => $application,
        // Диагностика, не контракт: сироты реестра не блокируют сборку
        // (§17 — мусор инертен), но видны для аудита конфигуратором.
        'registry_orphans' => $registry['orphans'],
    ];
}

/** Последняя ошибка сборки/обновления (для dev/debug-вывода). */
function snapshot_last_error(?string $set = null): ?string
{
    static $error = null;

    if ($set !== null) {
        $error = $set;
    }

    return $error;
}

/**
 * Атомарное сохранение: temp file → контрольное чтение → rename().
 * Формат — PHP return array: include + opcache, без json_decode.
 */
function snapshot_save(array $snapshot): bool
{
    if (!snapshot_validate($snapshot)) {
        return false;
    }

    $file     = config()['paths']['snapshot'];
    $tmp_file = $file . '.tmp';
    $content  = "<?php\nreturn " . var_export($snapshot, true) . ";\n";

    if (file_put_contents($tmp_file, $content, LOCK_EX) === false) {
        return false;
    }

    // .tmp — тоже фиксированное имя, переиспользуется при каждом save;
    // та же причина, что и для $file ниже.
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($tmp_file, true);
    }

    $check = @include $tmp_file;
    if (!is_array($check) || !snapshot_validate($check)) {
        @unlink($tmp_file);
        return false;
    }

    $renamed = rename($tmp_file, $file);

    // OPcache кеширует скомпилированный код по ИМЕНИ файла (§8: PHP
    // return[...] выбран ради его скорости, журнал 2026-07). rename()
    // подменяет содержимое под тем же именем — OPcache об этом не узнаёт
    // сам, следующий include может отдать старый снапшот из кеша, даже
    // в том же запросе (revalidate_freq по умолчанию — не мгновенно).
    // Без явной инвалидации refresh «срабатывает», но следующее чтение
    // видит старое — тихая рассинхронизация, не ошибка, что хуже.
    if ($renamed && function_exists('opcache_invalidate')) {
        opcache_invalidate($file, true);
    }

    return $renamed;
}

/** Чтение из файла; null = «рабочего снапшота сейчас нет». */
function snapshot_load(): ?array
{
    $file = config()['paths']['snapshot'];

    if (!is_file($file)) {
        return null;
    }

    try {
        $snapshot = include $file;
    } catch (\Throwable $e) {
        return null;
    }

    if (!is_array($snapshot) || !snapshot_validate($snapshot)) {
        return null;
    }

    return $snapshot;
}

// --- lock: только для structural-изменений (DDL) ------------------------------

function snapshot_lock_read(): ?array
{
    $file = config()['paths']['lock'];

    if (!is_file($file)) {
        return null;
    }

    $lock = json_decode((string) file_get_contents($file), true);

    return is_array($lock) ? $lock : null;
}

/**
 * Атомарный захват через fopen('x'). $source: 'auto_ddl' | 'manual' |
 * 'bootstrap' — чтобы автоматический процесс не снял чужую блокировку.
 */
function snapshot_lock_acquire(string $source, string $reason): bool
{
    $handle = @fopen(config()['paths']['lock'], 'x');
    if ($handle === false) {
        return false;
    }

    fwrite($handle, json_encode(
        ['source' => $source, 'reason' => $reason, 'started_at' => time()],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    ));
    fclose($handle);

    return true;
}

function snapshot_lock_release(string $source): bool
{
    $lock = snapshot_lock_read();
    if ($lock === null) {
        return true;
    }
    if ($lock['source'] !== $source) {
        return false;
    }

    return unlink(config()['paths']['lock']);
}

/**
 * Снятие зависшего лока — явное административное действие человека.
 *
 * 2026-07-19: до этой правки здесь жила `snapshot_lock_force_release()`,
 * чей докблок обещал «обязана сначала пересобрать и провалидировать
 * snapshot», а тело делало голый `unlink()`. Обещание существовало
 * только в комментарии, потребителей у функции не было ни одного —
 * поэтому расхождение никого и не поймало. Обещание выполнено здесь.
 *
 * Порядок обратный привычному: сперва убеждаемся, что модель здорова
 * БЕЗ файла лока, и только потом убираем файл. Если снапшот не
 * собирается или не пишется — лок остаётся на месте: он в этот момент
 * единственное, что мешает писателям заходить в сломанное состояние,
 * и снимать его было бы ровно наоборот.
 *
 * Автоснятия по возрасту здесь сознательно нет. Порог по времени не
 * отличает мёртвую операцию от медленной, а отнять лок у живого DDL —
 * тот самый сценарий, ради которого лок и заведён. Различить их можно
 * только меткой процесса и биением; при одном писателе это оборона
 * против несуществующей угрозы (§13). Повод назван в «Открытых
 * вопросах» и наступит с расширением периметра писателей, не раньше.
 */
function snapshot_lock_release_stale(PgSql\Connection $db_connection, array $application = []): array
{
    $file = config()['paths']['lock'];

    if (!is_file($file)) {
        return ['ok' => true, 'message' => 'Лок не стоит — снимать нечего.'];
    }

    $snapshot = snapshot_build($db_connection, $application);
    if ($snapshot === null) {
        return ['ok' => false, 'message' => 'Лок оставлен на месте: снапшот не собирается — '
            . (snapshot_last_error() ?? '?') . '. Сначала лечим модель, потом снимаем лок.'];
    }

    if (!snapshot_save($snapshot)) {
        return ['ok' => false, 'message' => 'Лок оставлен на месте: снапшот собран, но не записан '
            . '(права на state/?).'];
    }

    if (!unlink($file)) {
        return ['ok' => false, 'message' => 'Снапшот пересобран и проверен, но файл лока не удалён '
            . '(права на state/?). Ручное лечение: rm state/schema.lock'];
    }

    return ['ok' => true, 'message' => 'Лок снят. Снапшот перед этим пересобран и проверен.'];
}

// --- два пути обновления -------------------------------------------------------

/** Тяжёлый путь: пересборка structural-слоя. Всегда через lock. */
function snapshot_rebuild_structure(PgSql\Connection $db_connection, string $lock_source, array $application = []): bool
{
    if (!snapshot_lock_acquire($lock_source, 'Пересборка structural-слоя snapshot')) {
        return false;
    }

    try {
        $snapshot = snapshot_build($db_connection, $application);
        if ($snapshot === null) {
            return false;
        }

        return snapshot_save($snapshot);
    } finally {
        snapshot_lock_release($lock_source);
    }
}

/**
 * Лёгкий путь: обновление presentation-слоя БЕЗ DDL-lock — изменение
 * подписи не блокирует систему. Под чужим lock отступает: пересборка
 * прочитает свежие подписи сама.
 */
function snapshot_refresh_presentation(PgSql\Connection $db_connection): bool
{
    if (snapshot_lock_read() !== null) {
        return false;
    }

    $snapshot = snapshot_load();
    if ($snapshot === null) {
        return false;
    }

    $presentation = snapshot_build_presentation($db_connection);

    // Шаблоны составных подписей живут в model_labels → правка подписи
    // может менять словарный слой. Пересборка словарей — обязательная
    // часть ЛЮБОГО refresh'а presentation (§16.6: не DDL, lock не нужен,
    // нужен refresh модельного слоя). Кривой шаблон → refresh отклонён,
    // старый снапшот цел — fail-safe вместо частичного применения.
    $dictionaries = snapshot_build_dictionaries(
        $snapshot['structure'],
        snapshot_templates($presentation),
        snapshot_build_links($db_connection)
    );
    if ($dictionaries['unresolved'] !== []) {
        snapshot_last_error('Refresh отклонён, словари не разрешены: '
            . implode('; ', $dictionaries['unresolved']));
        return false;
    }

    $snapshot['presentation']          = $presentation;
    $snapshot['model']['dictionaries'] = $dictionaries['map'];
    $snapshot['generated_at']          = date('Y-m-d H:i:s');

    return snapshot_save($snapshot);
}

/**
 * Model-слой: лёгкий refresh, без DDL-lock (§8) — тот же путь, что у
 * presentation, симметрично. Валидация по structural — берём его из
 * уже загруженного снапшота, не пересобираем: structural не менялся,
 * лишь потому и разрешён refresh без lock.
 */
function snapshot_refresh_model(PgSql\Connection $db_connection): bool
{
    if (snapshot_lock_read() !== null) {
        return false;
    }

    $snapshot = snapshot_load();
    if ($snapshot === null) {
        return false;
    }

    $registry = snapshot_build_registry($db_connection, $snapshot['structure']);

    // Словарный слой зависит и от структуры (не менялась — refresh без
    // lock и разрешён), и от шаблонов в model_labels — пересобираем
    // симметрично refresh_presentation, с тем же fail-safe.
    $presentation = snapshot_build_presentation($db_connection);
    $dictionaries = snapshot_build_dictionaries(
        $snapshot['structure'],
        snapshot_templates($presentation),
        snapshot_build_links($db_connection)
    );
    if ($dictionaries['unresolved'] !== []) {
        snapshot_last_error('Refresh отклонён, словари не разрешены: '
            . implode('; ', $dictionaries['unresolved']));
        return false;
    }

    // Формулы — та же категория (model-слой, зависит от structural,
    // не от presentation) — пересобираются тем же refresh'ом, что
    // реестр/словари, тем же fail-safe (§15, решение 07-14).
    $formulas = snapshot_build_formulas($db_connection, $snapshot['structure']);
    if ($formulas['unresolved'] !== []) {
        snapshot_last_error('Refresh отклонён, формулы не разрешены: '
            . implode('; ', $formulas['unresolved']));
        return false;
    }

    $snapshot['model']['registry']     = $registry['map'];
    $snapshot['model']['dictionaries'] = $dictionaries['map'];
    $snapshot['model']['formulas']     = $formulas['map'];
    $snapshot['presentation']          = $presentation;
    $snapshot['registry_orphans']      = $registry['orphans'];
    $snapshot['generated_at']          = date('Y-m-d H:i:s');

    return snapshot_save($snapshot);
}

/**
 * Главная точка входа обычного запроса.
 * null — штатный сигнал «модель сейчас недоступна», не ошибка.
 */
function snapshot_init(PgSql\Connection $db_connection, array $application = []): ?array
{
    // Лок уважается ОДИНАКОВО в обеих ветках, первым шагом: означает
    // «структура сейчас в незавершённом состоянии» — не про способ
    // хранения кэша, dev-режим этого не отменяет (STATE.md, разговор
    // про мультиинженерный режим).
    if (snapshot_lock_read() !== null) {
        return null;
    }

    if ((config()['snapshot']['mode'] ?? 'cached') === 'live') {
        // Та же snapshot_build(), что и cached-путь — второго пути
        // сборки нет (§15.8). Файл не читается и не пишется: staleness
        // исчезает по построению. Цена — файловый путь (include,
        // atomic write, холодный старт) в этой ветке не исполняется;
        // гарант остаётся один — секция 1 смоука, всегда строящая
        // + пишущая + читающая настоящий файл в cached-режиме.
        return snapshot_build($db_connection, $application);
    }

    $snapshot = snapshot_load();
    if ($snapshot !== null) {
        return $snapshot;
    }

    if (!snapshot_rebuild_structure($db_connection, 'bootstrap', $application)) {
        return null;
    }

    return snapshot_load();
}

// ============================================================================
// record_* — универсальная запись
// ============================================================================

/** Заготовка честного результата операции (контракт ARCHITECTURE.md §3). */
function record_result(string $operation, string $table): array
{
    return [
        'ok'            => false,
        'operation'     => $operation,
        'table'         => $table,
        'id'            => null,
        'affected_rows' => 0,
        'errors'        => [],
    ];
}

/**
 * Универсальное сохранение. Сущности SQL не пишут: validate-handler
 * проверяет и нормализует, конвейер собирает prepared INSERT/UPDATE.
 *
 * Граница записи (один раз, здесь):
 *   - whitelist полей = entity-поля таблицы из snapshot;
 *     лишние ключи $input молча игнорируются;
 *   - структурные (id, rel_main, dep_*) не пишутся НИКОГДА —
 *     reparent является отдельным действием;
 *   - идентификаторы только из snapshot + backticks, значения — bind.
 */
function record_save(
    PgSql\Connection $db_connection,
    array $snapshot,
    string $table,
    array $input,
    ?int $id = null,
    array $structural = []
): array {
    $operation = $id === null ? 'insert' : 'update';
    $result    = record_result($operation, $table);

    $table_schema = $snapshot['structure']['tables'][$table] ?? null;
    if ($table_schema === null) {
        $result['errors'][] = "Таблица '$table' не существует в известной модели";
        return $result;
    }

    // --- validate: собираем нормализованные значения -------------------------
    $normalized = [];

    foreach ($table_schema['fields'] as $field_name => $field_schema) {
        if ($field_schema['kind'] !== 'entity_field') {
            continue;
        }
        if (!array_key_exists($field_name, $input)) {
            continue; // частичное обновление законно
        }

        $data = field_data($snapshot, $db_connection, $table, $field_name, $input[$field_name]);
        if ($data === null) {
            continue;
        }

        $verdict = field_exec($data, 'validate');
        if ($verdict === null) {
            continue; // сущность без validate этим путём не сохраняется
        }

        if (($verdict['valid'] ?? false) !== true) {
            foreach ($verdict['errors'] ?? [] as $error) {
                $result['errors'][] = "$field_name: $error";
            }
            continue;
        }

        $normalized[$field_name] = $verdict['value'];
    }

    if ($result['errors'] !== []) {
        return $result; // при ошибках валидации не выполняется ничего
    }

    if ($normalized === []) {
        $result['errors'][] = 'Нет ни одного поля для сохранения';
        return $result;
    }

    // --- доверенный структурный канал (только INSERT) --------------------------
    // $structural — НЕ request: значения подготовлены границей (index.php
    // сверил родителя с model.relations и его существование) и приходят
    // отдельным параметром, минуя $input. Инвариант «структурные поля не
    // пишутся из request» цел: сюда попадает подготовленный факт, не
    // подсказка (§9). На UPDATE канал закрыт — reparent остаётся
    // отдельным защищённым действием (п.5 «Сейчас»), не задним ходом.
    if ($operation === 'insert') {
        foreach ($structural as $field_name => $value) {
            if (($table_schema['fields'][$field_name]['kind'] ?? '') !== 'structural') {
                $result['errors'][] = "$field_name: не структурное поле таблицы '$table'";
                return $result;
            }
            $normalized[$field_name] = (int) $value;
        }
    }

    // --- запись: prepared statement -------------------------------------------
    $field_names = array_keys($normalized);
    $values      = array_values($normalized);
    $types       = str_repeat('s', count($values)); // MySQL приводит по схеме колонки

    if ($operation === 'insert') {
        $columns      = implode(', ', $field_names);
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        // RETURNING id — Postgres не имеет mysqli_insert_id() (докблок
        // db.php); каждая таблица имеет колонку id (STATE.md, правило
        // суррогатного ключа), безопасно требовать её здесь всегда.
        $sql = "INSERT INTO $table ($columns) VALUES ($placeholders) RETURNING id";
    } else {
        $assignments = implode(', ', array_map(
            static fn(string $name): string => "$name = ?",
            $field_names
        ));
        $sql      = "UPDATE $table SET $assignments WHERE id = ?";
        $values[] = $id;
        $types   .= 'i';
    }

    $outcome = db_execute($db_connection, $sql, $types, $values);
    if (!$outcome['ok']) {
        $result['errors'][] = 'Ошибка записи: ' . $outcome['error'];
        return $result;
    }

    $result['ok']            = true;
    $result['affected_rows'] = $outcome['affected_rows'];
    // insert_id из db_execute верен только для INSERT — для UPDATE он
    // несёт мусор (id последней вставки в этом соединении, не связан
    // с текущим запросом), поэтому для update берём переданный $id.
    $result['id'] = $operation === 'insert' ? $outcome['id'] : $id;

    return $result;
}

/** Универсальное удаление по id. Тот же честный результат. */
/**
 * Проверка использования перед удалением строки словаря (замысел
 * Влада, 2026-07-17, STATE.md «Позже»): если удаляемая строка ЯВЛЯЕТСЯ
 * значением словаря (её таблица — source_table хоть одного поля в
 * model.dictionaries), ищем, кто на неё ссылается, ПРЕЖДЕ чем удалять.
 * Единственная точка встраивания — record_delete() ниже, он один
 * обслуживает удаление ЛЮБОЙ записи любой таблицы, включая словарные.
 *
 * Быстрый выход: для подавляющего большинства таблиц (не словарь ни
 * для одного поля) — ноль дополнительных запросов, просто пустой
 * фильтр по уже скомпилированному model.dictionaries.
 *
 * voc_/link_ — скаляр (`WHERE field = id`), links_ — массив
 * (`WHERE id = ANY(field)`) — различаются по entity в структуре
 * поля-владельца, не по имени поля (одно поле может ссылаться на
 * source_table и быть voc_ ИЛИ links_ в зависимости от паспорта).
 */
function record_delete_check_usage(
    PgSql\Connection $db_connection, array $snapshot, string $table, int $id
): array {
    $referencing_fields = [];
    foreach ($snapshot['model']['dictionaries'] ?? [] as $field_name => $dict) {
        if (($dict['source_table'] ?? null) === $table) {
            $referencing_fields[] = $field_name;
        }
    }
    if ($referencing_fields === []) {
        return []; // не словарь ни для одного поля — обычная запись, проверять нечего
    }

    $used_by = [];
    foreach ($snapshot['structure']['tables'] ?? [] as $owner_table => $owner_schema) {
        foreach ($referencing_fields as $field_name) {
            $field_schema = $owner_schema['fields'][$field_name] ?? null;
            if ($field_schema === null || ($field_schema['kind'] ?? '') !== 'entity_field') {
                continue;
            }

            $is_multi = ($field_schema['entity'] ?? '') === 'links';
            $sql = $is_multi
                ? "SELECT id FROM $owner_table WHERE ? = ANY($field_name)"
                : "SELECT id FROM $owner_table WHERE $field_name = ?";

            foreach (db_select($db_connection, $sql, 'i', [$id]) as $row) {
                $used_by[] = [
                    'table' => $owner_table,
                    'field' => $field_name,
                    'id'    => (int) $row['id'],
                ];
            }
        }
    }

    return $used_by;
}

function record_delete(PgSql\Connection $db_connection, array $snapshot, string $table, int $id): array
{
    $result = record_result('delete', $table);

    if (!isset($snapshot['structure']['tables'][$table])) {
        $result['errors'][] = "Таблица '$table' не существует в известной модели";
        return $result;
    }

    $used_by = record_delete_check_usage($db_connection, $snapshot, $table, $id);
    if ($used_by !== []) {
        $result['used_by'] = $used_by;
        foreach ($used_by as $usage) {
            $result['errors'][] = "используется в {$usage['table']}#{$usage['id']} (поле {$usage['field']})";
        }
        return $result;
    }

    // 2026-07-16: LIMIT на DELETE — расширение MySQL, в Postgres такого
    // синтаксиса нет вовсе (ошибка парсера). Убран, не заменён —
    // избыточен и под MySQL: id — первичный ключ, WHERE id = ? и так
    // задевает максимум одну строку.
    $outcome = db_execute($db_connection, "DELETE FROM $table WHERE id = ?", 'i', [$id]);
    if (!$outcome['ok']) {
        $result['errors'][] = 'Ошибка удаления: ' . $outcome['error'];
        return $result;
    }

    $result['ok']            = true;
    $result['id']            = $id;
    $result['affected_rows'] = $outcome['affected_rows'];

    return $result;
}

/**
 * Смена непосредственного родителя (dep_<parent>) уже существующей
 * записи — reparent как ОТДЕЛЬНОЕ защищённое действие (STATE.md
 * «Сейчас» п.5), не задний ход record_save: её структурный канал
 * закрыт на UPDATE сознательно (см. докблок record_save выше).
 * Меняется ровно одна колонка; каскада нет по построению — потомки
 * ссылаются на id самой записи, не на корень, перенос ветки их
 * не касается (проверено доктриной §16, не только этим кодом).
 *
 * Имя FK-поля не приходит из request — резолвится record_parent_relation()
 * из собственных структурных полей таблицы. Из request попадает только
 * значение $new_parent_id, и оно проверяется на существование в той
 * родительской таблице, к которой фактически ведёт связь (record_fetch,
 * тот же паттерн проверки, что и everywhere в этом файле).
 */
function record_reparent(
    PgSql\Connection $db_connection,
    array $snapshot,
    string $table,
    int $id,
    int $new_parent_id
): array {
    $result = record_result('reparent', $table);

    $relation = record_parent_relation($snapshot, $table);
    if ($relation === null) {
        $result['errors'][] = "Таблица '$table' не имеет однозначной dep_-связи";
        return $result;
    }

    if (record_fetch($db_connection, $snapshot, $table, $id) === null) {
        $result['errors'][] = "Запись '$table'#$id не найдена";
        return $result;
    }

    if (record_fetch($db_connection, $snapshot, $relation['parent_table'], $new_parent_id) === null) {
        $result['errors'][] = "Новый родитель '{$relation['parent_table']}'#$new_parent_id не найден";
        return $result;
    }

    $outcome = db_execute(
        $db_connection,
        "UPDATE $table SET {$relation['fk']} = ? WHERE id = ?",
        'ii',
        [$new_parent_id, $id]
    );
    if (!$outcome['ok']) {
        $result['errors'][] = 'Ошибка записи: ' . $outcome['error'];
        return $result;
    }

    $result['ok']            = true;
    $result['affected_rows'] = $outcome['affected_rows'];
    $result['id']            = $id;

    return $result;
}

// --- чтение: единственные SELECT'ы рабочего конвейера --------------------------
// Дирижёр и render SQL не пишут; всё чтение записей — через эти две функции.
// Возврат — данные, не результат-контракт §3: он принадлежит операциям записи.

/**
 * Одна запись по id. null — «нет такой записи» ИЛИ «нет такой таблицы»:
 * для вызывающего оба случая означают 404, различие ему не нужно.
 */
/**
 * Композитная подпись САМОЙ записи (§16.3): заголовок карточки,
 * строка в списке, «к чему привязан» в блоке родителя. Та же
 * скомпилированная запись словаря, что рисует опции чужого списка —
 * но для конкретного id своей таблицы. Лестница §16.1 сбоку:
 *   есть проекция  → lookup_labels (композит «Мамуринская №31»);
 *   есть data_name → сырое имя;
 *   иначе          → «#id» (запись существует, подписи нет).
 * Никакого нового механизма — обёртка над готовым исполнителем.
 */
function record_label(PgSql\Connection $db_connection, array $snapshot, string $table, int $id): string
{
    // Подпись собственного объекта лежит под ключом '@table' — резолвер
    // компилирует её для ЛЮБОЙ таблицы, у которой есть чем подписаться
    // (явный шаблон ИЛИ data_name — унификация 07-13), независимо от
    // входящих ссылок (см. snapshot_build_dictionaries). Отдельного
    // пути для простых словарей больше нет — lookup_options удалён,
    // это и был весь смысл унификации.
    $dict = $snapshot['model']['dictionaries']['@' . $table] ?? null;
    if ($dict === null) {
        return "#$id";
    }

    return lookup_labels($db_connection, $dict)[$id] ?? "#$id";
}

/**
 * Зеркало record_label(), но по уже загруженной строке (helpers.php::
 * lookup_label_from_row) — не читает исходную таблицу целиком ради
 * одной подписи (обзор Chat 2026-07-20, п.2). Единственный сегодняшний
 * потребитель — record_tree_from_row() ниже: карта объекта уже держит
 * строку в руках, второй путь (record_label по id) читал бы ту же
 * таблицу с нуля через lookup_labels(). Для мест, где строки НЕТ и
 * нужен именно список по id (например record_parent_candidates —
 * много подписей одной таблицы разом), record_label()/lookup_labels()
 * остаются верным выбором: там полное кэширование таблицы — не потеря,
 * а именно то, что нужно.
 */
function record_label_from_row(PgSql\Connection $db_connection, array $snapshot, string $table, array $row): string
{
    $dict = $snapshot['model']['dictionaries']['@' . $table] ?? null;
    if ($dict === null) {
        return '#' . (string) ($row['id'] ?? '?');
    }

    return lookup_label_from_row($db_connection, $dict, $row);
}

/**
 * Прямые дети записи по скомпилированному графу (model.relations).
 * Один запрос на дочернюю таблицу (WHERE dep_<parent> = id), никакого
 * перебора таблиц — граф уже собран сборкой снапшота. Нет связей →
 * пустой массив, ноль запросов.
 */
/**
 * Резолв единственной dep_-связи таблицы: имя FK-поля и имя
 * родительской таблицы, читается напрямую из СОБСТВЕННЫХ структурных
 * полей таблицы (§1: имя поля — явный адрес, префикс dep_ снимается
 * так же, как в snapshot_build_relations). Не эвристика по графу —
 * прямой разбор имени. null — связи нет вовсе, либо больше одной
 * (сегодня в модели такого нигде нет; появится — fail-fast здесь,
 * не угадывание, §16 «эвристического выбора не существует»). Основа
 * для record_reparent/record_reparent_view (STATE.md «Сейчас» п.5).
 */
function record_parent_relation(array $snapshot, string $table): ?array
{
    $table_schema = $snapshot['structure']['tables'][$table] ?? null;
    if ($table_schema === null) {
        return null;
    }

    $fk_candidates = [];
    foreach ($table_schema['fields'] as $field_name => $field_schema) {
        if (($field_schema['kind'] ?? '') === 'structural' && str_starts_with($field_name, 'dep_')) {
            $fk_candidates[] = $field_name;
        }
    }

    if (count($fk_candidates) !== 1) {
        return null;
    }

    return ['fk' => $fk_candidates[0], 'parent_table' => substr($fk_candidates[0], 4)];
}

function record_children(PgSql\Connection $db_connection, array $snapshot, string $table, int $id): array
{
    $blocks = [];

    foreach ($snapshot['model']['relations'][$table] ?? [] as $relation) {
        $child = $relation['child'];
        $fk    = $relation['fk'];

        // Отличие от версии до db_select (журнал 07-14): раньше сбой
        // запроса пропускал блок целиком (continue). db_select при
        // ошибке молча возвращает [] — блок появится с пустыми rows,
        // не исчезнет. Считаю несущественным: $child/$fk берутся из
        // УЖЕ проверенного графа связей (§16.1, компилируется на
        // сборке снапшота), сбоя структуры здесь по построению не
        // бывает — остаётся только сбой соединения, при котором оба
        // исхода (пустой блок / пропавший блок) одинаково неполны.
        $blocks[] = [
            'table' => $child,
            'fk'    => $fk,
            'rows'  => db_select($db_connection, "SELECT * FROM $child WHERE $fk = ? ORDER BY id DESC", 'i', [$id]),
        ];
    }

    return $blocks;
}

/**
 * Карта объекта (STATE.md п.3, «карта = вся глубина сразу»): рекурсивный
 * обход ВСЕГО дерева зависимостей от записи вниз до листьев, в
 * структуру — НЕ в HTML. Наследник легаси db_tree(), но с двумя
 * исправлениями его слабостей: (1) граф читается из скомпилированного
 * model.relations, не сканированием БД на каждом узле (легаси find_dep);
 * (2) обход отделён от вывода — здесь только данные, renderer
 * разворачивает отдельно (легаси мешало обход, SQL и HTML в одной
 * рекурсии).
 *
 * Ограничения глубины НЕТ сознательно (решение Влада 07-09: «карта есть
 * карта»): дерево тампонажа неглубоко по природе (скважина → ступени →
 * интервалы → буферы), а искусственный предел был бы оптимизацией под
 * несуществующую проблему. Ацикличность гарантирована компилятором
 * графа (dep_ образует дерево владения, не цикл) — рекурсия конечна.
 *
 * Узел: ['table', 'id', 'label', 'row', 'fields', 'children' => [block...]],
 * где block: ['table', 'label' (подпись таблицы), 'fk', 'nodes' => [узел...]].
 */
function record_tree(PgSql\Connection $db_connection, array $snapshot, string $table, int $id): ?array
{
    $row = record_fetch($db_connection, $snapshot, $table, $id);
    if ($row === null) {
        return null;
    }

    return record_tree_from_row($db_connection, $snapshot, $table, $row);
}

/**
 * Тело record_tree() выше — принимает УЖЕ ЗАГРУЖЕННУЮ строку, не id.
 * До этой правки (обзор Chat 2026-07-20, п.1) рекурсия звала
 * record_tree($block['table'], $child_row['id']), а та немедленно
 * делала SELECT * ... WHERE id = ? — притом что $child_row секундой
 * раньше УЖЕ пришёл целиком из record_children() (WHERE $fk = ?).
 * Дерево из 500 записей получало сотни заведомо лишних запросов, по
 * одному на каждый узел каждого уровня. Разница только в этом: узел
 * строится из данных, которые уже есть в руках, а не запрашивается
 * заново — форма узла (ключи 'table'/'id'/'label'/… ) не изменилась
 * ни на бит, смоук и render.php их не отличат.
 */
function record_tree_from_row(PgSql\Connection $db_connection, array $snapshot, string $table, array $row): array
{
    $id = (int) $row['id'];

    $children = [];
    foreach (record_children($db_connection, $snapshot, $table, $id) as $block) {
        $nodes = [];
        foreach ($block['rows'] as $child_row) {
            $nodes[] = record_tree_from_row($db_connection, $snapshot, $block['table'], $child_row);
        }
        $children[] = [
            'table' => $block['table'],
            'label' => (string) (
                $snapshot['presentation']['labels']['table'][$block['table']]['data_full']
                ?? $block['table']
            ),
            'fk'    => $block['fk'],
            'nodes' => $nodes,
        ];
    }

    $columns = record_view_columns($snapshot, $table);

    return [
        'table'    => $table,
        'id'       => $id,
        'label'    => record_label_from_row($db_connection, $snapshot, $table, $row),
        // Признак «есть однозначная dep_-связь» — не сам родитель,
        // только флаг для рендера (STATE.md «Сейчас» п.5): показывать
        // ли ссылку «сменить родителя» у узла. Вычисление уже готово
        // (record_parent_relation), второй раз в render его звать
        // незачем — render не лезет в снапшот вообще.
        'reparentable' => record_parent_relation($snapshot, $table) !== null,
        'row'      => $row,
        // Готовая заготовка узла (view-слой, п.8б): столбцы + одна строка
        // с разрешёнными значениями. Рендер её только укладывает, разбор
        // полей (доступ к БД через словари) остаётся здесь, в ядре.
        'view'     => [
            'kind'    => 'table',
            'table'   => $table,
            'columns' => $columns,
            'rows'    => [record_view_row($db_connection, $snapshot, $table, $row, $columns)],
        ],
        'children' => $children,
    ];
}

function record_fetch(PgSql\Connection $db_connection, array $snapshot, string $table, int $id): ?array
{
    if (!isset($snapshot['structure']['tables'][$table])) {
        return null;
    }

    $rows = db_select($db_connection, "SELECT * FROM $table WHERE id = ? LIMIT 1", 'i', [$id]);

    return $rows[0] ?? null;
}

/**
 * Список записей таблицы, свежие сверху. Возвращает массив строк
 * (возможно пустой) — вызывающий не знает про mysqli_result.
 * Пагинация/сортировка по полю — слой «Позже» (batch-режимы).
 */
/**
 * Список id→подпись всех записей таблицы-родителя — источник опций
 * для формы reparent (STATE.md «Сейчас» п.5). Список id — единственный
 * собственный SELECT; подпись каждой строки — через record_label (её
 * request-level кэш lookup_labels уже решает N+1, второй кэш не заводим).
 */
function record_parent_candidates(PgSql\Connection $db_connection, array $snapshot, string $parent_table): array
{
    $ids = array_map(
        static fn(array $row): int => (int) $row['id'],
        db_select($db_connection, "SELECT id FROM $parent_table ORDER BY id DESC")
    );

    $candidates = [];
    foreach ($ids as $parent_id) {
        $candidates[$parent_id] = record_label($db_connection, $snapshot, $parent_table, $parent_id);
    }

    return $candidates;
}

/**
 * Список записей таблицы. Свежие сверху.
 *
 * $limit — временный предел, а НЕ осмысленная величина. Постраничности
 * в контуре данных нет, поэтому всё, что за пределом, через интерфейс
 * недостижимо. Обнаружилось 2026-07-20 на тестовых данных: у буферов,
 * горизонтов и интервалов записей стало больше предела, а раньше
 * данных было меньше, и он не проявлялся. Поднят с 50 до 100 тем же
 * днём — чтобы не мешал, пока не появится настоящее решение.
 *
 * Параметр рабочий, им уже пользуются: `labels.php` передаёт 500 для
 * редактора подписей. Контур данных своего значения не передаёт —
 * `index.php` зовёт `record_table_view()` без него.
 *
 * Сортировка тоже зашита (`ORDER BY id DESC`) и снаружи не задаётся.
 *
 * Решено 2026-07-20: сортировка, фильтр, поиск и постраничность
 * приходят сюда ОБРАТНЫМ ходом из слоя представления, а не пишутся
 * здесь заранее. Это ровно та четвёрка, из которой состоит определение
 * выборки; написав её дважды — для списка и для отчётов, — мы получим
 * два расходящихся механизма. Список записей таблицы должен оказаться
 * частным случаем выборки: источник без фильтра, все колонки,
 * сортировка по id. Если будущая грамматика такого не выражает —
 * грамматика неверна.
 */
function record_list(PgSql\Connection $db_connection, array $snapshot, string $table, int $limit = 100): array
{
    if (!isset($snapshot['structure']['tables'][$table])) {
        return [];
    }

    return db_select($db_connection, "SELECT * FROM $table ORDER BY id DESC LIMIT ?", 'i', [$limit]);
}

/**
 * Общие кирпичи view-слоя (STATE.md п.8): столбцы таблицы и одна строка
 * с готовыми значениями ячеек. Их используют и record_table_view
 * (список), и record_tree (карта) — один способ сборки на оба экрана.
 * Структурные поля (id/dep_/rel_main) в представление не попадают.
 */
function record_view_columns(array $snapshot, string $table): array
{
    $fields  = $snapshot['structure']['tables'][$table]['fields'] ?? [];
    $columns = [];
    foreach ($fields as $field_name => $field_schema) {
        if (($field_schema['kind'] ?? '') !== 'entity_field') {
            continue;
        }
        $labels = $snapshot['presentation']['labels']['field'][$table][$field_name] ?? [];
        $columns[] = [
            'field' => $field_name,
            'label' => (string) ($labels['data_short'] ?? $field_name),
        ];
    }
    return $columns;
}

/**
 * Одна строка представления из уже прочитанной строки БД: для каждого
 * столбца зовёт field_exec(read) — значение готово к показу (voc-число
 * разрешено в подпись). HTML не рождается: только данные.
 */
function record_view_row(
    PgSql\Connection $db_connection, array $snapshot, string $table, array $row, array $columns
): array {
    $cells = [];
    foreach ($columns as $column) {
        $field_name = $column['field'];
        $data   = field_data($snapshot, $db_connection, $table, $field_name, $row[$field_name] ?? null, $row);
        $result = $data !== null ? field_exec($data, 'read') : null;
        // 2026-07-17: значение ячейки МОЖЕТ быть списком (links_ и
        // подобные многозначные поля) — раскладка в HTML (в столбик,
        // не одной строкой) решается в render.php (§3: единственное
        // место, где рождается HTML), не здесь. Ядро больше не
        // приводит принудительно к (string) — пропускает как есть
        // (строка для обычных полей, список строк для многозначных).
        $value = $result !== null ? ($result['value'] ?? '') : '';
        $cells[] = is_array($value) ? $value : (string) $value;
    }
    return ['id' => (int) ($row['id'] ?? 0), 'cells' => $cells];
}

/**
 * Сборка представления «список» (view-слой, STATE.md п.8): из строк
 * таблицы — готовая заготовка для рендера, БЕЗ HTML. Ядро собирает,
 * render раскладывает по клеткам — шов между сборкой и укладкой
 * (инвариант трёх слоёв, журнал 07-10).
 *
 * Возврат:
 *   ['kind'=>'table', 'table'=>..., 'columns'=>[['field'=>..,'label'=>..]],
 *    'rows'=>[['id'=>N, 'cells'=>['<готовое значение>', ...]]]]
 */
/**
 * Заготовка формы reparent (view-слой, п.8 — тот же инвариант, что у
 * списка/карты/формы): подпись записи, подпись текущего родителя,
 * список кандидатов. Render только укладывает в HTML, доступ к БД
 * остаётся здесь. null — связи нет или запись не найдена; вызывающий
 * (index.php) превращает в 422/404, HTML тут не строится.
 *
 * $hidden — технические скрытые поля формы (_action/_table/_id/_return),
 * собираются дирижёром так же, как для record_form_view — как есть,
 * без интерпретации.
 */
function record_reparent_view(
    PgSql\Connection $db_connection,
    array $snapshot,
    string $table,
    int $id,
    array $hidden = []
): ?array {
    $relation = record_parent_relation($snapshot, $table);
    if ($relation === null) {
        return null;
    }

    $row = record_fetch($db_connection, $snapshot, $table, $id);
    if ($row === null) {
        return null;
    }

    $current_parent_id = (int) ($row[$relation['fk']] ?? 0);

    return [
        'label'                => record_label($db_connection, $snapshot, $table, $id),
        'current_parent_id'    => $current_parent_id,
        'current_parent_label' => $current_parent_id > 0
            ? record_label($db_connection, $snapshot, $relation['parent_table'], $current_parent_id)
            : '(нет)',
        'candidates'           => record_parent_candidates($db_connection, $snapshot, $relation['parent_table']),
        'hidden'               => $hidden,
    ];
}

function record_table_view(PgSql\Connection $db_connection, array $snapshot, string $table, int $limit = 100): array
{
    $columns = record_view_columns($snapshot, $table);
    $rows    = [];
    foreach (record_list($db_connection, $snapshot, $table, $limit) as $row) {
        $rows[] = record_view_row($db_connection, $snapshot, $table, $row, $columns);
    }
    return ['kind' => 'table', 'table' => $table, 'columns' => $columns, 'rows' => $rows];
}

/**
 * Сборка представления «форма» (view-слой, STATE.md п.8в): поля записи
 * в режиме new/edit — готовая заготовка для рендера, БЕЗ HTML. Ядро
 * собирает (зовёт field_exec в нужном режиме — получаются виджеты
 * input/choice с текущими значениями), render раскладывает.
 *
 * $row — прочитанная строка для edit (пусто/[] для new).
 * $mode — 'new' | 'edit'.
 *
 * Возврат:
 *   ['kind'=>'form', 'table'=>..., 'mode'=>..., 'elements'=>[<результаты
 *     field_exec>], 'hidden'=>['_action'=>.., '_table'=>.., ...]]
 * elements — уже готовые структурированные результаты полей (input/
 * choice/value), render превращает их в виджеты. hidden — технические
 * поля формы (действие, таблица, id, родитель), которые нужны PRG.
 */
function record_form_view(
    PgSql\Connection $db_connection, array $snapshot, string $table, string $mode,
    ?array $row = null, array $hidden = []
): array {
    $row      = $row ?? []; // new: записи ещё нет — пустой набор значений
    $fields   = $snapshot['structure']['tables'][$table]['fields'] ?? [];
    $elements = [];
    foreach ($fields as $field_name => $field_schema) {
        if (($field_schema['kind'] ?? '') !== 'entity_field') {
            continue; // структурные поля в форму не попадают
        }
        $data   = field_data($snapshot, $db_connection, $table, $field_name, $row[$field_name] ?? null, $row);
        $result = $data !== null ? field_exec($data, $mode) : null;
        if ($result !== null) {
            $elements[] = $result;
        }
    }
    return [
        'kind'     => 'form',
        'table'    => $table,
        'mode'     => $mode,
        'elements' => $elements,
        'hidden'   => $hidden,
    ];
}

/**
 * Классификация таблицы по группе (naming-driven, один источник на все
 * страницы — домашняя, labels, конфигуратор; ARCHITECTURE §17, критерий
 * «не переизобретать»). Возврат: 'system' | 'dict' | 'selection' | 'root' | 'dependent'.
 *
 *   system    — приставка model_/presentation_ (к модели данных
 *               отношения не имеет, is_reserved_table);
 *   dict      — приставка voc_ (словарь/служебная);
 *   selection — object_type='view' (решение 2026-07-21, слой
 *               представления: select_ — не «главная» предметная
 *               таблица и не словарь, отдельная категория). По факту
 *               из каталога Postgres (information_schema.tables,
 *               snapshot_build_structure), не по префиксу имени —
 *               тот же сигнал, что уже отличает DROP VIEW от
 *               DROP TABLE, не второе, отдельно поддерживаемое правило;
 *   dependent — есть dep_/rel_main (показывается под своей главной, не
 *              верхним уровнем);
 *   root      — ни dep_, ни rel_main: вершина СВОЕГО дерева. НЕ «одна
 *              главная таблица системы» — корней может быть несколько
 *              независимых (2026-07-21, Влад: «главные» вводило в
 *              заблуждение, читалось как единственная центральная сущность,
 *              да ещё и совпадало по звучанию со служебной таблицей
 *              `main`, которой пользуется только smoke_test.php и к
 *              корням деревьев отношения не имеет).
 *
 * 2026-07-19: из перечня убран 'report' — функция его не возвращала
 * никогда, в теле такой ветки не было. Заготовка «на будущее» в
 * документе — тот же анти-паттерн §12, что и в коде, только опаснее:
 * код можно проверить исполнением, а обещание в докблоке читают на
 * веру. 'selection' здесь — не та же ошибка: ветка есть, исполняется,
 * потребитель (render_table_directory) уже существует тем же коммитом.
 */
function table_group(string $table_name, array $table_schema): string
{
    if (is_reserved_table($table_name)) {
        return 'system';
    }
    if (str_starts_with($table_name, 'voc_')) {
        return 'dict';
    }
    if (($table_schema['object_type'] ?? 'table') === 'view') {
        return 'selection';
    }
    foreach ($table_schema['fields'] ?? [] as $field_name => $_) {
        if ($field_name === 'rel_main' || str_starts_with($field_name, 'dep_')) {
            return 'dependent';
        }
    }
    return 'root';
}

/**
 * Сборка представления «схема таблицы» (view-слой, для конфигуратора):
 * поля таблицы с человеческими подписями — устройство, НЕ записи. БЕЗ
 * HTML. Структурные поля (id) не показываются; dep_/rel_main тоже (они
 * не про предметное устройство). Возврат:
 *   ['table'=>.., 'label'=>.., 'group'=>.., 'fields'=>[['name'=>..,'label'=>..]]]
 */
function schema_view(array $snapshot, string $table): array
{
    $table_schema = $snapshot['structure']['tables'][$table] ?? ['fields' => []];
    $t_labels     = $snapshot['presentation']['labels']['table'][$table] ?? [];

    $fields = [];
    foreach ($table_schema['fields'] as $field_name => $field_schema) {
        if (($field_schema['kind'] ?? '') !== 'entity_field') {
            continue; // id/dep_/rel_main — не предметное устройство
        }
        $f_labels = $snapshot['presentation']['labels']['field'][$table][$field_name] ?? [];
        $fields[] = [
            'name'  => $field_name,
            'label' => (string) ($f_labels['data_full'] ?? $field_name),
        ];
    }

    return [
        'table'  => $table,
        'label'  => (string) ($t_labels['data_full'] ?? $table),
        'group'  => table_group($table, $table_schema),
        'fields' => $fields,
    ];
}

/**
 * Диагностика структуры: сравнить реальную БД с реестром модели, найти
 * расхождения (инструмент ремонта, журнал 2026-07-11). Отдельно от
 * snapshot_build_registry (тот в горячем пути компиляции и молча
 * проглатывает дубли — перезаписью ключа); здесь — по запросу, честно
 * пересчитывая сырые строки реестра.
 *
 * Возврат — три вида, каждый список готов к показу и починке:
 *   'orphan_fields'  — поле есть в БД, в реестре нет: [[table,field,entity]]
 *   'orphan_tables'  — таблица есть в БД, в реестре нет: [table]
 *   'ghost_registry' — реестр ссылается на исчезнувшее: [[id,kind,owner,element]]
 *   'duplicates'     — один адрес зарегистрирован >1 раза: [[kind,owner,element,ids]]
 * Пусто везде — структура и реестр согласованы.
 *
 * Системные таблицы (model_) и структурные поля (id/dep_/rel_main) не
 * участвуют: они не адресуются реестром по построению, их «отсутствие»
 * в реестре — норма, не расхождение.
 */
function model_diagnose(PgSql\Connection $db_connection): array
{
    $structure = snapshot_build_structure($db_connection);

    // Сырые строки реестра (все active), без свёртки в map — чтобы
    // увидеть дубли, которые map затирает. Честный контракт
    // (db_select_result, обзор Chat 2026-07-20 п.7): раньше сбой ИМЕННО
    // этого запроса читался как «реестр пуст» и раздул бы диагностику
    // ложными «сиротами» по каждой таблице и полю в базе — не тихая
    // маскировка факта, а шумная неправда вместо неё, тоже плохо. При
    // сбое НЕ считаем сироты/призраки/дубли вовсе — посчитанное на
    // пустом реестре хуже, чем явно не посчитанное.
    $sql = 'SELECT id, data_kind, data_owner, data_element FROM '
         . MODEL_REGISTRY_TABLE . ' WHERE active = 1';
    $reg_result = db_select_result($db_connection, $sql);
    if (!$reg_result['ok']) {
        return [
            'orphan_fields'  => [],
            'orphan_tables'  => [],
            'ghost_registry' => [],
            'duplicates'     => [],
            'clean'          => false,
            'error'          => 'Чтение реестра модели не удалось: ' . $reg_result['error'],
        ];
    }
    $reg_rows = $reg_result['rows'];

    // Индекс адресов реестра: адрес → список id (список, а не одно —
    // чтобы поймать дубли).
    $reg_addr = [];
    foreach ($reg_rows as $row) {
        $kind  = (string) $row['data_kind'];
        $owner = $row['data_owner'] === null ? '' : (string) $row['data_owner'];
        $elem  = (string) $row['data_element'];
        $reg_addr["$kind|$owner|$elem"][] = (int) $row['id'];
    }

    // --- дубли: один адрес с >1 id --------------------------------------
    $duplicates = [];
    foreach ($reg_addr as $addr => $ids) {
        if (count($ids) > 1) {
            [$kind, $owner, $elem] = explode('|', $addr, 3);
            $duplicates[] = [
                'kind'    => $kind,
                'owner'   => $owner === '' ? null : $owner,
                'element' => $elem,
                'ids'     => $ids,
            ];
        }
    }

    // --- призраки: реестр ссылается на исчезнувший элемент ---------------
    $ghost_registry = [];
    foreach ($reg_rows as $row) {
        $kind  = (string) $row['data_kind'];
        $owner = $row['data_owner'];
        $elem  = (string) $row['data_element'];
        $gone = match ($kind) {
            'table' => !isset($structure['tables'][$elem]),
            'field' => !isset($structure['tables'][(string) $owner]['fields'][$elem]),
            default => false,
        };
        if ($gone) {
            $ghost_registry[] = [
                'id'      => (int) $row['id'],
                'kind'    => $kind,
                'owner'   => $owner,
                'element' => $elem,
            ];
        }
    }

    // --- сироты: в БД есть, в реестре нет --------------------------------
    $orphan_tables = [];
    $orphan_fields = [];
    foreach ($structure['tables'] as $t_name => $t_schema) {
        if (is_reserved_table($t_name)) {
            continue; // системные таблицы реестром не адресуются (defense-in-depth: snapshot_build_structure их уже не отдаёт)
        }
        if (!isset($reg_addr["table||$t_name"])) {
            $orphan_tables[] = $t_name;
        }
        foreach ($t_schema['fields'] as $f_name => $f_schema) {
            if (($f_schema['kind'] ?? '') !== 'entity_field') {
                continue; // id/dep_/rel_main — структурные, не адресуются
            }
            if (!isset($reg_addr["field|$t_name|$f_name"])) {
                $orphan_fields[] = [
                    'table'  => $t_name,
                    'field'  => $f_name,
                    'entity' => (string) ($f_schema['entity'] ?? ''),
                ];
            }
        }
    }

    return [
        'orphan_fields'  => $orphan_fields,
        'orphan_tables'  => $orphan_tables,
        'ghost_registry' => $ghost_registry,
        'duplicates'     => $duplicates,
        'clean'          => $orphan_fields === [] && $orphan_tables === []
                          && $ghost_registry === [] && $duplicates === [],
        'error'          => '',
    ];
}
