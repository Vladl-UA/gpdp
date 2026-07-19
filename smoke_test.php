<?php
declare(strict_types=1);

/**
 * Смоук-тест конвейера GPDP на тестовой БД gpdp_test.
 * Не часть системы — инструмент проверки контрактов ARCHITECTURE.md.
 */

require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/core.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/render.php';

// Та же дверь, что у системы: смоук проверяет GPDP в её собственной
// конфигурации, а не в параллельной (hardcode root — археология).
try {
    $db_connection = db_connect(config()['db']);
} catch (\Throwable $e) {
    exit('Смоук не стартовал: нет соединения с БД (' . $e->getMessage() . ")\n");
}

// --- Изолированный словарь-манекен смоука (журнал 2026-07-12) --------------
// Раньше фикстура main.voc_mr указывала на БОЕВОЙ словарь месторождений
// (well пользуется им по-настоящему) — имя поля жёстко адресует таблицу
// (§1, наименование определяет поведение), развязать частично нельзя.
// Смоук хардкодил id=1/2 и рассчитывал на content живого словаря — правки
// в проде рвали тест. Теперь у фикстуры СВОЙ словарь: пересоздаётся и
// переполняется на каждом прогоне, боевых данных не касается вовсе; id не
// хардкодятся — вычисляются по имени сразу после вставки.
db_execute($db_connection, 'DELETE FROM voc_smoke');
$smoke_dict_names = ['Самотлор', 'Приобское', 'Ромашкинское', 'Ванкорское'];
foreach ($smoke_dict_names as $n) {
    db_execute($db_connection, 'INSERT INTO voc_smoke (data_name) VALUES (?)', 's', [$n]);
}
$smoke_dict_id = static function (string $name) use ($db_connection): string {
    $rows = db_select($db_connection, 'SELECT id FROM voc_smoke WHERE data_name = ?', 's', [$name]);
    return (string) ($rows[0]['id'] ?? '0');
};
$id_samotlor  = $smoke_dict_id('Самотлор');
$id_priobskoe = $smoke_dict_id('Приобское');
db_execute($db_connection, 'DELETE FROM main'); // фикстура: чистый лист на каждый прогон

function check(string $name, bool $ok, string $details = ''): void
{
    echo ($ok ? '  OK  ' : ' FAIL ') . $name . ($details !== '' ? "  [$details]" : '') . "\n";
    if (!$ok) {
        exit(1);
    }
}

echo "=== 0. Транзакция: примитивы db.php (2026-07-19) ===\n";
// Своя изолированная таблица, к модели отношения не имеет — как и
// фикстуры остальных секций, живёт только внутри этой проверки.
db_execute($db_connection, 'DROP TABLE IF EXISTS tx_smoke');
db_execute($db_connection, 'CREATE TABLE tx_smoke (id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY, data_name VARCHAR(64))');

$tx = db_transaction_begin($db_connection);
check('BEGIN даёт честный результат', ($tx['ok'] ?? false) === true, $tx['error'] ?? '');

db_execute($db_connection, "INSERT INTO tx_smoke (data_name) VALUES ('внутри транзакции')");
db_execute($db_connection, 'CREATE TABLE tx_smoke_child (id INTEGER)');

// Свойство, на котором держится порядок структурной операции
// (configurator_with_lock): снапшот собирается ДО коммита, значит
// интроспекция обязана видеть собственный незакоммиченный DDL.
$seen = db_select(
    $db_connection,
    "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'tx_smoke_child'"
);
check('незакоммиченный DDL виден собственной интроспекции', count($seen) === 1);

$tx = db_transaction_rollback($db_connection);
check('ROLLBACK даёт честный результат', ($tx['ok'] ?? false) === true, $tx['error'] ?? '');
check('после отката строки нет', db_select($db_connection, 'SELECT id FROM tx_smoke') === []);
check(
    'после отката созданной таблицы нет',
    db_select(
        $db_connection,
        "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'tx_smoke_child'"
    ) === []
);

db_transaction_begin($db_connection);
db_execute($db_connection, "INSERT INTO tx_smoke (data_name) VALUES ('закоммичено')");
$tx = db_transaction_commit($db_connection);
check('COMMIT даёт честный результат', ($tx['ok'] ?? false) === true, $tx['error'] ?? '');
$tx_rows = db_select($db_connection, 'SELECT data_name FROM tx_smoke');
check('после коммита строка на месте', count($tx_rows) === 1 && $tx_rows[0]['data_name'] === 'закоммичено');

// Отказ внутри транзакции: db_execute честно сообщает об ошибке, а
// откат после неё проходит — это и есть путь, которым идёт
// configurator_with_lock при провале тела операции.
db_transaction_begin($db_connection);
$bad = db_execute($db_connection, 'INSERT INTO tx_smoke (нет_такой_колонки) VALUES (1)');
check('ошибочный запрос: ok=false и текст причины', ($bad['ok'] ?? true) === false && ($bad['error'] ?? '') !== '');
$tx = db_transaction_rollback($db_connection);
check('ROLLBACK после ошибки проходит', ($tx['ok'] ?? false) === true, $tx['error'] ?? '');
check('данные после отката не пострадали', count(db_select($db_connection, 'SELECT id FROM tx_smoke')) === 1);

db_execute($db_connection, 'DROP TABLE tx_smoke');

echo "\n=== 1. Snapshot: bootstrap (state/) ===\n";
$snapshot = snapshot_init($db_connection, config()['application']);
check('snapshot построен', $snapshot !== null, (string) snapshot_last_error());
check('файл лежит в state/', str_contains(config()['paths']['snapshot'], '/state/')
    && is_file(config()['paths']['snapshot']));
check('поле voc_smoke — сущность voc',
    $snapshot['structure']['tables']['main']['fields']['voc_smoke']['entity'] === 'voc');
check('поле id — структурное',
    $snapshot['structure']['tables']['main']['fields']['id']['kind'] === 'structural');
check('presentation: подпись voc_smoke скомпилирована из model_labels',
    ($snapshot['presentation']['labels']['field']['main']['voc_smoke'] ?? []) !== []);
check('root_table из config, не из ядра',
    ($snapshot['application']['root_table'] ?? '') === 'main');

echo "\n=== 1а. Словари: адрес разрешён сборкой, не рантаймом (§16.1) ===\n";
// voc_mr здесь — БОЕВОЙ словарь (месторождения well), не фикстура main.
// Проверка структурная (склад разрешается верно) и не зависит от того,
// какие конкретно значения в нём лежат — content-независима, оставлена.
// После унификации 07-13 склад тоже 'projection' (синтезированный
// план {data_name}), отдельного subtype='warehouse' больше нет.
check('voc_mr разрешён как склад в model.dictionaries',
    ($snapshot['model']['dictionaries']['voc_mr']['subtype'] ?? '') === 'projection'
    && $snapshot['model']['dictionaries']['voc_mr']['source_table'] === 'voc_mr');
$probe = field_data($snapshot, $db_connection, 'main', 'voc_smoke', 1);
check('пакет несёт скомпилированный source', $probe['field']['source'] === 'voc_smoke');
$probe = field_data($snapshot, $db_connection, 'main', 'data_nkust', 'x');
check('несловарное поле: source = null', $probe['field']['source'] === null);

// Лестница целиком — на синтетической структуре: резолвер — чистая
// функция над $structure, живой БД не касается (в этом и смысл (а1):
// разрешение на границе сборки, ноль интроспекции в рантайме).
$ladder = ['tables' => [
    'voc_mr' => ['fields' => ['id' => [], 'data_name' => []]],
    'area'   => ['fields' => ['id' => [], 'data_name' => []]],
    'bad'    => ['fields' => ['id' => []]], // таблица без data_name
    'main'   => ['fields' => [
        'voc_mr'   => ['kind' => 'entity_field', 'entity' => 'voc'],
        'voc_area' => ['kind' => 'entity_field', 'entity' => 'voc'],
    ]],
]];
$resolved = snapshot_build_dictionaries($ladder);
check('склад: voc_mr → voc_mr, синтезирован план {data_name}',
    $resolved['map']['voc_mr']['subtype'] === 'projection'
    && $resolved['map']['voc_mr']['source_table'] === 'voc_mr'
    && ($resolved['map']['voc_mr']['plan'] ?? []) === [['kind' => 'field', 'field' => 'data_name']]);
check('адрес: voc_area → area, тот же синтезированный план',
    $resolved['map']['voc_area']['subtype'] === 'projection'
    && $resolved['map']['voc_area']['source_table'] === 'area'
    && ($resolved['map']['voc_area']['plan'] ?? []) === [['kind' => 'field', 'field' => 'data_name']]);
check('чистая лестница: неразрешённых нет', $resolved['unresolved'] === []);

$ladder['tables']['main']['fields']['voc_bad'] = ['kind' => 'entity_field', 'entity' => 'voc'];
$resolved = snapshot_build_dictionaries($ladder);
check('x без data_name → «нужен паспорт», fail-fast',
    count($resolved['unresolved']) === 1
    && str_contains($resolved['unresolved'][0], 'нужен паспорт'));

$ladder['tables']['main']['fields']['voc_ghost'] = ['kind' => 'entity_field', 'entity' => 'voc'];
$resolved = snapshot_build_dictionaries($ladder);
check('источника нет вообще → «неизвестный адрес», fail-fast',
    count($resolved['unresolved']) === 2
    && str_contains($resolved['unresolved'][1], 'неизвестный адрес'));

echo "\n=== 1б. Составная подпись: маяк-шаблон (§16.1 п.2, §16.3) ===\n";
// Синтаксис отделён от семантики: template_parse — чистая единица,
// компилятор словарей — её первый потребитель (math_ — названное будущее).
$t = template_parse('{voc_mr} №{data_number}');
check('template_parse: token / literal / token',
    $t['error'] === null && count($t['items']) === 3
    && $t['items'][0] === ['kind' => 'token', 'name' => 'voc_mr']
    && $t['items'][1] === ['kind' => 'literal', 'value' => ' №']
    && $t['items'][2] === ['kind' => 'token', 'name' => 'data_number']);
$t = template_parse('скв. {data_number');
check('непарная скобка → ошибка синтаксиса, не тихий литерал',
    $t['error'] !== null && str_contains($t['error'], 'скобка'));

// Резолвер+компилятор — чистые функции: синтетическая структура,
// синтетические шаблоны, ноль обращений к БД.
$proj = ['tables' => [
    'voc_mr' => ['fields' => ['id' => [], 'data_name' => []]],
    'well'   => ['fields' => [ // БЕЗ data_name — раньше был fail-fast
        'id'          => [],
        'voc_mr'      => ['kind' => 'entity_field', 'entity' => 'voc'],
        'data_number' => ['kind' => 'entity_field', 'entity' => 'data'],
    ]],
    'main'   => ['fields' => [
        'voc_well' => ['kind' => 'entity_field', 'entity' => 'voc'],
        'voc_mr'   => ['kind' => 'entity_field', 'entity' => 'voc'],
    ]],
]];
$tpl = ['well' => '{voc_mr} №{data_number}'];
$r   = snapshot_build_dictionaries($proj, $tpl);
check('маяк спасает таблицу без data_name → проекция',
    $r['unresolved'] === []
    && ($r['map']['voc_well']['subtype'] ?? '') === 'projection'
    && $r['map']['voc_well']['source_table'] === 'well');
$plan = $r['map']['voc_well']['plan'] ?? [];
check('план: dict → literal → field, разделитель из шаблона',
    count($plan) === 3
    && $plan[0]['kind'] === 'dict'    && $plan[0]['field'] === 'voc_mr'
    && $plan[1]['kind'] === 'literal' && $plan[1]['value'] === ' №'
    && $plan[2]['kind'] === 'field'   && $plan[2]['field'] === 'data_number');
check('дочерняя запись вложена в dict-шаг (исполнителю карта не нужна)',
    ($plan[0]['dict']['subtype'] ?? '') === 'projection'
    && $plan[0]['dict']['source_table'] === 'voc_mr');

$r = snapshot_build_dictionaries($proj, ['voc_mr' => '{data_name}!', 'well' => '{voc_mr} №{data_number}']);
check('маяк сильнее конвенции: склад с шаблоном → проекция',
    $r['unresolved'] === [] && ($r['map']['voc_mr']['subtype'] ?? '') === 'projection');

$r = snapshot_build_dictionaries($proj, ['well' => '{voc_mr} №{no_such_field}']);
check('токен вне whitelist источника → fail-fast',
    count($r['unresolved']) === 1
    && str_contains($r['unresolved'][0], 'не существует'));

// Цикл: well → {voc_area} → area → {voc_well} → well
$cyc = $proj;
$cyc['tables']['area'] = ['fields' => [
    'id'       => [],
    'voc_well' => ['kind' => 'entity_field', 'entity' => 'voc'],
]];
$cyc['tables']['well']['fields']['voc_area'] = ['kind' => 'entity_field', 'entity' => 'voc'];
$r = snapshot_build_dictionaries($cyc, [
    'well' => '{voc_area} №{data_number}',
    'area' => 'скв. {voc_well}',
]);
check('цикл шаблонов → fail-fast с перечнем цепочки',
    $r['unresolved'] !== []
    && str_contains(implode(';', $r['unresolved']), 'цикл шаблонов')
    && str_contains(implode(';', $r['unresolved']), '→'));

echo "\n=== 1в. Тумблер snapshot.mode (STATE.md «Сейчас» п.6) ===\n";
// config() кэширует статически — режим не переключить внутри живого
// процесса. Но проверять тумблер запуском второго PHP (subprocess)
// хрупко: зависит от PHP_BINARY/прав на сервере. Проверяем НЕ обёртку
// config(), а сам механизм напрямую: ветка live в snapshot_init — это
// буквально `return snapshot_build(...)` без файлового пути. Вызываем
// snapshot_build() прямо и убеждаемся: (1) собирает валидный снапшот,
// (2) не создаёт/не трогает файл (build сам по себе не пишет —
// запись делает только snapshot_save в cached-ветке).
$live_file   = config()['paths']['snapshot'];
$live_before = is_file($live_file) ? md5_file($live_file) : null;
$live_built  = snapshot_build($db_connection, config()['application']);
$live_after  = is_file($live_file) ? md5_file($live_file) : null;

check('live-путь (snapshot_build): собирает валидный снапшот',
    $live_built !== null && isset($live_built['model']['dictionaries']));
check('live-путь: snapshot_build не пишет файл сам по себе',
    $live_before === $live_after);
check('тумблер: дефолт cached (прод не получает live молча)',
    (config()['snapshot']['mode'] ?? 'cached') === 'cached');

echo "\n=== 1г. Граф связей dep_/rel_main (п.3 шаг 2) ===\n";
// Чистая функция — синтетика по топологии легаси-дампа tt_utf8_fixed.sql.
$tree = ['tables' => [
    'main'     => ['fields' => ['id' => ['kind' => 'structural']]],
    'steps'    => ['fields' => [
        'id'       => ['kind' => 'structural'],
        'dep_main' => ['kind' => 'structural'],
        'rel_main' => ['kind' => 'structural'],
    ]],
    'material' => ['fields' => [
        'id'        => ['kind' => 'structural'],
        'dep_steps' => ['kind' => 'structural'],
        'rel_main'  => ['kind' => 'structural'],
    ]],
    'bufers'   => ['fields' => [
        'id'           => ['kind' => 'structural'],
        'dep_material' => ['kind' => 'structural'],
        'rel_main'     => ['kind' => 'structural'],
    ]],
]];
$g = snapshot_build_relations($tree);
check('обратный индекс: main → steps через dep_main',
    $g['unresolved'] === []
    && ($g['map']['main'][0] ?? []) === ['child' => 'steps', 'fk' => 'dep_main']);
check('цепочка 3 уровней: steps → material → bufers',
    ($g['map']['steps'][0]['child'] ?? '') === 'material'
    && ($g['map']['material'][0]['child'] ?? '') === 'bufers');
$root_sorted = $g['root'];
sort($root_sorted);
check('rel_main-разрез: кто привязан к корню (компилируется, не рендерится)',
    $root_sorted === ['bufers', 'material', 'steps']);

$tree['tables']['orphan'] = ['fields' => [
    'dep_ghost' => ['kind' => 'structural'],
]];
$g = snapshot_build_relations($tree);
check('dep_ в никуда → fail-fast с именем таблицы',
    count($g['unresolved']) === 1 && str_contains($g['unresolved'][0], 'ghost'));

check('живой снапшот несёт model.relations',
    isset($snapshot['model']['relations']) && is_array($snapshot['model']['relations']));
check('у словаря нет детей → record_children пуст, ноль запросов',
    record_children($db_connection, $snapshot, 'voc_smoke', (int) $id_samotlor) === []);
check('record_label: подпись объекта по data_name-ветке',
    record_label($db_connection, $snapshot, 'voc_smoke', (int) $id_samotlor) !== '#' . $id_samotlor);

echo "\n=== 1д. Reparent: резолв связи (STATE.md «Сейчас» п.5) ===\n";
// Резолв — чистая функция над структурой (record_parent_relation),
// проверяется синтетикой тем же приёмом, что 1г для snapshot_build_relations:
// не нужна БД, нужна только форма структуры.
$one_dep = ['structure' => ['tables' => ['child' => ['fields' => [
    'id'        => ['kind' => 'structural'],
    'dep_well'  => ['kind' => 'structural'],
]]]]];
$rel = record_parent_relation($one_dep, 'child');
check('одна dep_-связь резолвится: fk + имя родителя',
    $rel === ['fk' => 'dep_well', 'parent_table' => 'well']);

$zero_dep = ['structure' => ['tables' => ['orphan' => ['fields' => [
    'id' => ['kind' => 'structural'],
]]]]];
check('нет dep_-полей → null, не угадывание',
    record_parent_relation($zero_dep, 'orphan') === null);

$two_dep = ['structure' => ['tables' => ['ambiguous' => ['fields' => [
    'id'         => ['kind' => 'structural'],
    'dep_well'   => ['kind' => 'structural'],
    'dep_stage'  => ['kind' => 'structural'],
]]]]];
check('две dep_-связи → null (неоднозначность — fail-fast, не эвристика §16)',
    record_parent_relation($two_dep, 'ambiguous') === null);

// Против живого снапшота, без единой записи в БД: таблица известна,
// но у 'main' (изолированная фикстура смоука) нет dep_-поля вовсе —
// ровно тот случай, что должен отказать, а не тихо промолчать.
$no_relation = record_reparent($db_connection, $snapshot, 'main', 1, 1);
check('record_reparent на таблице без dep_ → честная ошибка, не запись',
    $no_relation['ok'] === false
    && str_contains($no_relation['errors'][0] ?? '', 'dep_-связи'));

$unknown_table = record_reparent($db_connection, $snapshot, 'no_such_table_xyz', 1, 1);
check('record_reparent на неизвестной таблице → честная ошибка',
    $unknown_table['ok'] === false);

// НЕ ПРОВЕРЕНО ЭТИМ СМОУКОМ (честно, не молчать): happy path записи
// (реальный UPDATE dep_-колонки) и отказ на несуществующем новом
// родителе/записи — оба пути требуют реальной dep_-таблицы с данными,
// а у изолированной фикстуры main такой таблицы сегодня нет (создавать
// её здесь незачем — это DDL, смоуку не место его делать). Живая
// проверка этих двух путей — либо новая фикстура (child-таблица main,
// по образцу voc_smoke), либо ручной прогон через UI на реальном
// дереве (Влад подтверждает кликом). Пункт 5 остаётся «код написан
// и частично проверен», не «выполнено целиком» до одного из двух.

echo "\n=== 1е. Формулы calc_ (STATE.md «Позже», решение 2026-07-14) ===\n";
// Чистые функции — синтетика без БД, тот же приём, что 1д для
// record_parent_relation.
check('formula_parse: два поля с минусом — корректный план',
    formula_parse('{dec_volume_plan} - {dec_volume_fact}') === [
        ['type' => 'field', 'name' => 'dec_volume_plan'],
        ['type' => 'op', 'value' => '-'],
        ['type' => 'field', 'name' => 'dec_volume_fact'],
    ]);
check('formula_parse: одно поле без оператора — тоже корректный план',
    formula_parse('{dec_volume_plan}') === [['type' => 'field', 'name' => 'dec_volume_plan']]);
check('formula_parse: незакрытая скобка — null, не тихий обрыв',
    formula_parse('{dec_volume_plan - {dec_volume_fact}') === null);
check('formula_parse: висящий оператор в конце — null',
    formula_parse('{dec_volume_plan} -') === null);
check('formula_parse: пустая строка — null',
    formula_parse('') === null);

$plan = formula_parse('{dec_volume_plan} - {dec_volume_fact}');
check('formula_eval: корректный расчёт (план 15 минус факт 12 = 3)',
    formula_eval($plan, ['dec_volume_plan' => '15.00', 'dec_volume_fact' => '12.00']) === 3.0);
check('formula_eval: пустая переменная в строке → null, не 0 и не фатал',
    formula_eval($plan, ['dec_volume_plan' => '15.00', 'dec_volume_fact' => null]) === null);

$div_plan = formula_parse('{a} / {b}');
check('formula_eval: деление на ноль → null, не фатал',
    formula_eval($div_plan, ['a' => '10', 'b' => '0']) === null);

// Живая проверка: сборка снапшота НЕ падает при отсутствующей таблице
// model_formulas (её ещё не завёл Влад — SQL передан отдельно, не
// DDL смоука). db_select возвращает [] на ошибку запроса (db.php) —
// snapshot_build_formulas() деградирует до пустой карты, тем же
// способом, что snapshot_build_links() при отсутствующей model_links.
check('snapshot несёт model.formulas (даже пустой, до появления таблицы)',
    isset($snapshot['model']['formulas']) && is_array($snapshot['model']['formulas']));

// НЕ ПРОВЕРЕНО ЭТИМ СМОУКОМ: живой JOIN model_formulas/model_registry
// и whitelist-отказ на переменной вне владеющей таблицы — оба требуют
// реальной строки в model_formulas, которой ещё нет. Живая проверка —
// после того как Влад применит SQL и добавит первую формулу
// (calc_volume_deviation, cementing_interval).

echo "\n=== 2. Пространство имён ===\n";
check('в системе не осталось функций gpdp_*',
    array_filter(get_defined_functions()['user'],
        fn($f) => str_starts_with($f, 'gpdp_')) === []);
check('зарезервированный id отвергается', !entity_id_valid('field') && !entity_id_valid('render'));
check('легальный id проходит', entity_id_valid('voc') && entity_id_valid('mlt'));

echo "\n=== 3. Парсер и карта действий ===\n";
$p = field_parse('data_nkust');
check('data_nkust → entity_field/data/nkust',
    $p['kind'] === 'entity_field' && $p['entity'] === 'data' && $p['name'] === 'nkust');
check('dep_main → structural', field_parse('dep_main')['kind'] === 'structural');
check('footnotes → unknown', field_parse('footnotes')['kind'] === 'unknown');
check('view → read', action_mode('view') === 'read');
check('create → null: admin-режимы не рождаются картой', action_mode('create') === null);
check('rebuild_schema → null', action_mode('rebuild_schema') === null);

echo "\n=== 4. Тонкая труба ===\n";
$data = field_data($snapshot, $db_connection, 'main', 'data_nkust', 'проверка');
check('пакет собран: entity внутри, повторный разбор не нужен',
    $data['field']['entity'] === 'data');
check('field_data для чужого поля → null (подсказка не подтвердилась)',
    field_data($snapshot, $db_connection, 'main', 'data_fake', 'x') === null);
check('field_data для структурного поля → null',
    field_data($snapshot, $db_connection, 'main', 'id', 1) === null);
$r = field_exec($data, 'read');
check('exec: одна строчка, без охраны несуществующих дверей', $r['value'] === 'проверка');

echo "\n=== 5. Запись: validate → универсальный save ===\n";
$input  = ['data_nkust' => '  Куст-101  ', 'voc_smoke' => $id_priobskoe,
           'id' => '999', 'rel_main' => '777', 'handler' => 'evil_function'];
$result = record_save($db_connection, $snapshot, 'main', $input);
check('insert ok', $result['ok'] === true, implode('; ', $result['errors']));
check('operation result честный',
    $result['operation'] === 'insert' && $result['id'] > 0 && $result['affected_rows'] === 1);
$new_id = $result['id'];

$row = db_select($db_connection, 'SELECT * FROM main WHERE id = ?', 'i', [$new_id])[0] ?? null;
check('data нормализовано (trim)', $row['data_nkust'] === 'Куст-101');
check('структурные/чужие ключи input проигнорированы', (int) $row['id'] === $new_id);

$bad = record_save($db_connection, $snapshot, 'main', ['voc_smoke' => '999999']);
check('validate ловит несуществующее значение словаря',
    $bad['ok'] === false && str_contains($bad['errors'][0] ?? '', 'словаре'));

$upd = record_save($db_connection, $snapshot, 'main', ['data_nkust' => 'Куст-102'], $new_id);
check('update ok', $upd['ok'] === true && $upd['operation'] === 'update');

echo "\n=== 6. Чтение и рендер ===\n";
$row = record_fetch($db_connection, $snapshot, 'main', $new_id);
check('record_fetch: существующая запись читается',
    is_array($row) && (int) $row['id'] === $new_id && $row['data_nkust'] === 'Куст-102');
check('record_fetch: несуществующий id → null',
    record_fetch($db_connection, $snapshot, 'main', 999999) === null);
check('record_fetch: неизвестная таблица → null',
    record_fetch($db_connection, $snapshot, 'evil', 1) === null);

$list = record_list($db_connection, $snapshot, 'main');
check('record_list: массив строк, свежие сверху, запись на месте',
    is_array($list) && (int) ($list[0]['id'] ?? 0) === $new_id);
check('record_list: лимит соблюдается',
    count(record_list($db_connection, $snapshot, 'main', 1)) === 1);
check('record_list: неизвестная таблица → пустой массив',
    record_list($db_connection, $snapshot, 'evil') === []);

$data = field_data($snapshot, $db_connection, 'main', 'voc_smoke', $row['voc_smoke'], $row);
$read = field_exec($data, 'read');
check('voc read → человекочитаемое значение', $read['value'] === 'Приобское');

$edit = field_exec($data, 'edit');
// +1 — voc_handler в режиме edit всегда добавляет пустой вариант выбора
// впереди списка (entities.php: ['value'=>0,'label'=>'']), сверх реальных
// значений словаря. Считаем от того же списка, что сеяли — не магическим
// числом (моя ошибка при первой правке 07-12: посадила 4 значения, забыв
// про этот пустой вариант, получилось 5 вместо ожидаемых 4).
check('voc edit → структура choice',
    $edit['type'] === 'choice' && count($edit['options']) === count($smoke_dict_names) + 1);

// Живое исполнение проекции — без изменений схемы: синтетическая
// скомпилированная запись над РЕАЛЬНОЙ main (у записи $new_id:
// data_nkust='Куст-102', voc_smoke=id «Приобское»), вложенный словарь —
// изолированная фикстура voc_smoke из снапшота, не боевой voc_mr.
$live_projection = [
    'source_table' => 'main',
    'label_column' => null,
    'subtype'      => 'projection',
    'plan'         => [
        ['kind' => 'field',   'field' => 'data_nkust'],
        ['kind' => 'literal', 'value' => ' / '],
        ['kind' => 'dict',    'field' => 'voc_smoke',
         'dict' => $snapshot['model']['dictionaries']['voc_smoke']],
    ],
];
$live_labels = lookup_labels($db_connection, $live_projection);
$mr_label    = lookup_labels($db_connection, $snapshot['model']['dictionaries']['voc_smoke'])[(int) $id_priobskoe] ?? '';
check('проекция живьём: {data_nkust} / {voc_smoke} собрано по плану',
    $mr_label !== ''
    && ($live_labels[$new_id] ?? '') === 'Куст-102 / ' . $mr_label);

$html = render_form_element($edit);
// Подпись сверяется с самим снапшотом (model_labels), не с зашитым
// словом: смоук не знает, short или full рисует renderer — знает,
// что рисуется ОДНА ИЗ скомпилированных.
$label_row  = $snapshot['presentation']['labels']['field']['main']['voc_smoke'] ?? [];
$label_seen = false;
foreach ([$label_row['data_full'] ?? '', $label_row['data_short'] ?? ''] as $label_candidate) {
    if ($label_candidate !== '' && str_contains($html, $label_candidate)) {
        $label_seen = true;
        break;
    }
}
check('renderer собрал select с подписью из model_labels',
    $label_seen && str_contains($html, 'selected>Приобское'));

echo "\n=== 6а. render_options: единственный источник тега option (2026-07-19) ===\n";
// Ветка множественного выбора (links_) не покрывалась смоуком вовсе и
// в живой модели сейчас нет ни одного links_-поля — проверяется здесь
// напрямую, функция чистая и БД не требует.
$opt_items = [
    ['value' => '',  'label' => '— выберите —'],
    ['value' => 1,   'label' => 'Первый'],
    ['value' => 2,   'label' => 'Второй & <b>'],
];
check('без выбора: ни одного selected',
    substr_count(render_options($opt_items), 'selected') === 0);
check('скаляр: отмечен ровно один вариант',
    substr_count(render_options($opt_items, 2), 'selected') === 1
    && str_contains(render_options($opt_items, 2), '<option value="2" selected>'));
check('массив (links_): отмечены оба варианта',
    substr_count(render_options($opt_items, [1, 2]), 'selected') === 2);
check('подпись экранируется, разметка из данных не рождается',
    str_contains(render_options($opt_items), 'Второй &amp; &lt;b&gt;'));
check('конфигуратор больше не собирает option сам (§3, §12)',
    preg_match('/<option/', (string) file_get_contents(__DIR__ . '/configurator.php')) === 0
    || substr_count((string) file_get_contents(__DIR__ . '/configurator.php'), '<option value=') === 0);

echo "\n=== 7. Lookup-кэш (N+1, теперь в helpers) ===\n";
// 2026-07-16: SHOW SESSION STATUS LIKE 'Questions' — не Postgres, замена
// на собственный счётчик db.php (db_query_count()), см. докблок в db.php.
$q_before = db_query_count();
for ($i = 0; $i < 30; $i++) {
    lookup_labels($db_connection, $snapshot['model']['dictionaries']['voc_smoke']);
}
$q_after = db_query_count();
check('30 обращений → не 30 SELECT', ($q_after - $q_before) <= 2,
    'запросов: ' . ($q_after - $q_before));

echo "\n=== 8. Два пути обновления snapshot ===\n";
// Подпись живёт в model_labels (§17). Таблица subscr физически ещё
// существует (страховка миграции), но ядро её НЕ читает — обновлять
// её в этом тесте бессмысленно: подпись бы «не обновилась» вечно.
$label_before = $snapshot['presentation']['labels']['field']['main']['voc_smoke']['data_full'] ?? '';
// 2026-07-16: MySQL UPDATE...JOIN не существует в Postgres как синтаксис
// — UPDATE...FROM, условие связи переезжает из ON в WHERE.
$label_update_sql = 'UPDATE model_labels SET data_full = ?
                 FROM model_registry
                 WHERE model_registry.id = model_labels.dep_model_registry
                   AND model_registry.data_kind = \'field\' AND model_registry.data_owner = \'main\'
                   AND model_registry.data_element = \'voc_smoke\'';
db_execute($db_connection, $label_update_sql, 's', ['Родовище']);
check('refresh presentation без lock', snapshot_refresh_presentation($db_connection));
$fresh = snapshot_load();
check('подпись обновилась из model_labels',
    ($fresh['presentation']['labels']['field']['main']['voc_smoke']['data_full'] ?? '') === 'Родовище');
check('lock не создавался', snapshot_lock_read() === null);
$label_restore = $label_before === '' ? null : $label_before;
db_execute($db_connection, $label_update_sql, 's', [$label_restore]);
snapshot_refresh_presentation($db_connection);

snapshot_lock_acquire('manual', 'тест');
check('под lock snapshot_init → null', snapshot_init($db_connection) === null);
check('под lock refresh отступает', snapshot_refresh_presentation($db_connection) === false);
snapshot_lock_release('manual');

echo "\n=== 9. Жизненный цикл index.php (HTTP) ===\n";
// Контракт «дирижёр не пишет SQL»: в исходнике индекса нет ни SQL-глаголов,
// ни формирования/исполнения запросов — только connect/charset/close.
$index_source = (string) file_get_contents(__DIR__ . '/index.php');
check('в index.php нет SQL и pg_query/pg_query_params',
    preg_match('/pg_query_params|pg_query\(|SELECT |INSERT |UPDATE |DELETE /', $index_source) === 0);

// Встроенный сервер PHP; проходим цикл: view → new → save (PRG) → view.
// _table=main — обязателен явно: без него index.php показывает домашнюю
// страницу «Главные таблицы» (условие !$table_requested в дирижёре),
// не список записей — родилось позже, чем эта секция теста, разошлись.
$pid = (int) shell_exec('php -S localhost:8088 -t ' . escapeshellarg(__DIR__) . ' >/dev/null 2>&1 & echo $!');
usleep(600000);

$view = (string) file_get_contents('http://localhost:8088/index.php?_table=main&_action=view');
check('view отрисован с подписями', str_contains($view, 'Куст') && str_contains($view, 'Смоук'));

$form = (string) file_get_contents('http://localhost:8088/index.php?_table=main&_action=new');
check('форма new собрана конвейером', str_contains($form, 'name="data_nkust"')
    && str_contains($form, '<select name="voc_smoke"'));
check('структурные поля в форму не попали', !str_contains($form, 'name="rel_main"'));

$context = stream_context_create(['http' => [
    'method'          => 'POST',
    'header'          => 'Content-Type: application/x-www-form-urlencoded',
    'content'         => http_build_query([
        '_action' => 'save_new', '_table' => 'main',
        'data_nkust' => 'Куст-HTTP', 'voc_smoke' => $id_samotlor,
    ]),
    'follow_location' => 0,
    'ignore_errors'   => true,
]]);
file_get_contents('http://localhost:8088/index.php', false, $context);
$prg = implode(' ', $http_response_header ?? []);
check('PRG: после записи redirect, не отрисовка',
    str_contains($prg, '302') && str_contains($prg, '_action=view'));

$view2 = (string) file_get_contents('http://localhost:8088/index.php?_table=main&_action=view');
check('запись видна после redirect: словарь отрендерен именем',
    str_contains($view2, 'Куст-HTTP') && str_contains($view2, 'Самотлор'));

@file_get_contents('http://localhost:8088/index.php?_table=evil&_action=view');
check('неизвестная таблица отвергнута на границе',
    str_contains(implode(' ', $http_response_header ?? []), '404'));

// 2026-07-17: стадия 1 дорожной карты единого входа (STATE.md
// «Позже») — контекст определяется раньше snapshot_init(). Стадия 5
// (2026-07-17): configurator/labels подключаются реально через
// index.php, не заглушка.
@file_get_contents('http://localhost:8088/index.php?_context=nope&_table=main&_action=view');
check('неизвестный _context -> 400',
    str_contains(implode(' ', $http_response_header ?? []), '400'));

$ctx_configurator = (string) file_get_contents('http://localhost:8088/index.php?_context=configurator&_action=diagnose');
check('_context=configurator через index.php — рабочий вызов, не заглушка',
    str_contains($ctx_configurator, 'Конфигуратор БД')
    && str_contains($ctx_configurator, 'Состояние модели'));

$ctx_labels = (string) file_get_contents('http://localhost:8088/index.php?_context=labels');
check('_context=labels через index.php — рабочий вызов, не заглушка',
    str_contains($ctx_labels, 'Подписи и словари'));

$view_ctx = (string) file_get_contents('http://localhost:8088/index.php?_context=data&_table=main&_action=view');
check('_context=data явно — тот же результат, что без параметра (регрессия)',
    str_contains($view_ctx, 'Куст-HTTP') && str_contains($view_ctx, 'Самотлор'));

exec("kill $pid 2>/dev/null");

echo "\n=== 10. Удаление ===\n";
db_execute($db_connection, 'DELETE FROM main WHERE data_nkust = ?', 's', ['Куст-HTTP']);
$del = record_delete($db_connection, $snapshot, 'main', $new_id);
check('delete ok', $del['ok'] === true && $del['affected_rows'] === 1);

echo "\nВСЕ ПРОВЕРКИ ПРОЙДЕНЫ\n";
