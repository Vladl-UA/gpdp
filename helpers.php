<?php
declare(strict_types=1);

/**
 * GPDP / RNA — общие инструменты сущностей.
 *
 * Не является главным конвейером: этими функциями пользуются
 * обработчики сущностей (entities.php). Ядро сюда не заглядывает.
 * helpers не знают ничего про request и про index.
 *
 * Семейства: lookup_* (словарные выборки), entity_* (общие кирпичи
 * простых entity-хендлеров — read/input/nullable-check/error, вынесены
 * 2026-07-20 из дословных повторов в entities.php, обзор Chat), в
 * будущем form_*, sql_* (например date_parts_assemble уедет в
 * семейство сущности date_*).
 */

/**
 * Варианты выбора из таблицы-словаря: map id → data_name.
 *
 * Request-level кэш внутри решает N+1: 30 строк с одним словарём
 * = один SELECT. Батч-режимы (read_many, preload) — отложены,
 * этого кэша достаточно (STATE.md, журнал).
 *
 * $source ОБЯЗАН быть доверенным: он приходит из пакета $data,
 * собранного field_data() по snapshot, — не из request.
 */
/**
 * Соединение с БД для админ-страниц: один boot вместо трёх копий
 * (index.php / configurator.php / labels.php). Конфиг — из config();
 * при отказе — 500 и выход, страница без БД не имеет смысла.
 * 2026-07-16: делегирует в db_connect() (db.php) — сама больше не
 * знает деталей протокола подключения (mysqli vs pgsql), только
 * оборачивает отказ в HTTP 500, уместный именно для веб-страниц
 * (db_connect() бросает RuntimeException при отказе, не exit() —
 * решение о форме отказа остаётся за вызывающим кодом).
 */
function admin_db_connect(): PgSql\Connection
{
    try {
        return db_connect(config()['db']);
    } catch (\Throwable $e) {
        http_response_code(500);
        exit('Нет соединения с БД: ' . $e->getMessage());
    }
}

/**
 * Подписи по скомпилированной словарной записи: map id → label.
 *
 * Единый вход для ВСЕХ словарей без исключения (§16.1, унификация
 * 2026-07-13): и простой словарь (data_name), и составная подпись
 * («Мамуринская №31») идут одним и тем же путём — сборкой снапшота
 * data_name синтезирован как шаблон из одного куска, отдельной формы
 * записи для «простого» словаря больше нет (была lookup_options,
 * жёсткий SELECT id, data_name — убрана вместе со второй машинкой).
 * literal → как есть; field → сырое значение колонки той же строки;
 * dict → рекурсивно подпись вложенного словаря (его запись ВЛОЖЕНА
 * в план при компиляции — исполнителю не нужна карта словарей,
 * ацикличность доказана компилятором, рекурсия конечна).
 *
 * SQL JOIN не собирается принципиально (§16.3: «текст SQL в данных
 * не хранится и не исполняется») — один плоский SELECT источника,
 * склейка в PHP; вложенные словари идут через ту же функцию рекурсивно,
 * со своим request-кэшем — N+1 не возникает.
 *
 * $dict ОБЯЗАН быть доверенным: скомпилированная запись из пакета
 * field_data (снапшот), не из request.
 */
/**
 * Применение скомпилированного плана подписи (dict['plan']) к ОДНОЙ
 * строке. Общее ядро для lookup_labels() (обходит все строки таблицы)
 * и lookup_label_from_row() ниже (одна уже загруженная строка) — план
 * один и тот же, разница только в том, откуда берётся $row. Вынесено,
 * чтобы не заводить два места, разбирающих kind='literal'/'field'/
 * 'dict' (§7 — не перепроверять/не переисполнять внутри трубы дважды).
 */
function lookup_label_apply_plan(PgSql\Connection $db_connection, array $plan, array $row): string
{
    $text = '';
    foreach ($plan as $item) {
        if ($item['kind'] === 'literal') {
            $text .= $item['value'];
            continue;
        }
        if ($item['kind'] === 'field') {
            $text .= (string) ($row[$item['field']] ?? '');
            continue;
        }
        // dict: значение поля — id в вложенном словаре; 0/пусто → ''
        $ref = (int) ($row[$item['field']] ?? 0);
        $text .= $ref === 0 ? '' : (string) (lookup_labels($db_connection, $item['dict'])[$ref] ?? '');
    }
    return $text;
}

function lookup_labels(PgSql\Connection $db_connection, array $dict): array
{
    static $cache = [];
    $source = $dict['source_table'];

    if (isset($cache[$source])) {
        return $cache[$source];
    }

    $labels = [];
    // Поведение при ошибке меняется явно: db_select() возвращает [],
    // поэтому ошибочная выборка теперь не вызывает mysqli warning/TypeError,
    // а даёт тот же внешний результат, что и пустой словарь.
    $rows = db_select($db_connection, "SELECT * FROM $source");
    foreach ($rows as $row) {
        $labels[(int) $row['id']] = lookup_label_apply_plan($db_connection, $dict['plan'], $row);
    }

    asort($labels, SORT_NATURAL | SORT_FLAG_CASE);

    return $cache[$source] = $labels;
}

/**
 * Подпись ОДНОЙ уже загруженной строки — без SELECT * по всей таблице
 * (обзор Chat 2026-07-20, п.2). Потребитель — карта объекта
 * (record_tree_from_row, core.php): запись уже прочитана однажды выше
 * по стеку (record_children отдаёт rows целиком), второй раз читать
 * ВСЮ таблицу ради подписи ЭТОЙ ОДНОЙ строки незачем. Вложенные
 * словари (kind='dict' в плане) всё равно идут через lookup_labels()
 * рекурсивно — там действительно нужен полный список вариантов ЧУЖОЙ
 * таблицы (для будущего <select>, а не для этой одной строки), не
 * подмена. Не отдельный язык подписи — та же lookup_label_apply_plan(),
 * что и у lookup_labels(), просто без обхода источника.
 *
 * ВНИМАНИЕ вызывающему: $row обязана быть строкой ИМЕННО источника
 * $dict['source_table'] — функция это не проверяет (симметрично
 * lookup_labels(), которая тоже доверяет $dict как скомпилированному
 * снапшотом факту, не request-вводу, см. её докблок выше).
 */
function lookup_label_from_row(PgSql\Connection $db_connection, array $dict, array $row): string
{
    return lookup_label_apply_plan($db_connection, $dict['plan'], $row);
}

/**
 * Обратный поиск: человеческая подпись → id (журнал 2026-07-15, для
 * массовой заливки данных — «Синий» из файла, не внутренний id, о
 * котором тот, кто готовит данные, знать не обязан).
 *
 * Зеркало lookup_labels() — тот же $dict, та же скомпилированная
 * запись из snapshot['model']['dictionaries'], тот же request-кэш
 * (переиспользуется, второй SELECT не идёт). Не отдельная машинка —
 * разворот уже существующей карты id→label.
 *
 * Точное совпадение (не подстрока, не регистронезависимо) — частичное
 * совпадение само создало бы неоднозначность там, где её не было.
 *
 * Явная ошибка при неоднозначности (два id с одной и той же подписью
 * — data_name сегодня без UNIQUE) — НЕ первый попавшийся: тихий выбор
 * был бы источником непредсказуемых, невоспроизводимых результатов
 * массовой загрузки, обнаруживаемых только постфактум на живых данных.
 *
 * Возврат честный, не голое значение: null не отличил бы «не нашлось»
 * от «ошибка», а вызывающему (загрузчику) нужно различать эти два
 * случая по-разному в отчёте.
 */
function lookup_id_by_label(PgSql\Connection $db_connection, array $dict, string $label): array
{
    $matches = array_keys(lookup_labels($db_connection, $dict), $label, true);

    if ($matches === []) {
        return ['ok' => false, 'id' => null, 'error' => "значение «$label» не найдено в словаре"];
    }

    if (count($matches) > 1) {
        return ['ok' => false, 'id' => null, 'error' => "«$label» неоднозначно — совпадает "
            . count($matches) . ' записей (id: ' . implode(', ', $matches) . ')'];
    }

    return ['ok' => true, 'id' => $matches[0], 'error' => ''];
}

// ============================================================================
// entity_* — общие кирпичи простых entity-хендлеров (entities.php).
// Вынесены 2026-07-20 (Влад, по следам обзора Chat: «дублирование
// внутри entities.php», data_handler/ltext_handler почти дословно
// совпадали) — ровно то, что буквально повторялось в data/ltext/
// footnote/date/year/time/int/dec, ни строкой больше. НЕ универсальная
// фабрика паспортов/валидаторов (предостережение самого обзора Chat):
// типовая валидация (формат даты, диапазон года, целое без дробной
// части, разделитель дробного) остаётся собственным кодом каждого
// хендлера — только она и делает сущности разными. voc_handler и
// bul_handler эти кирпичи не зовут вовсе: у них другой смысл read
// (резолв через словарь / текст «Да»/«Нет») и другой виджет ввода —
// совпадение было бы случайным, не общим правилом.
// ============================================================================

/**
 * Общий "read": значение как есть, строкой. Тот же кусок был в восьми
 * хендлерах дословно.
 */
function entity_read_value(array $data): array
{
    $field = $data['field'];
    return [
        'type'   => 'value',
        'name'   => $field['raw'],
        'value'  => (string) ($data['value'] ?? ''),
        'subscr' => $field['subscr'],
    ];
}

/**
 * Общий "new"/"edit": текстовый ввод простого виджета (text/textarea/
 * date/number/...). $widget/$meta решает вызывающий хендлер — это и
 * есть единственное, чем различались data_handler/ltext_handler
 * (только widget) и footnote_handler/dec_handler (только meta).
 * Хендлеры, которым нужно предобработать значение перед показом
 * (time_handler — HH:MM:SS → HH:MM) или построить нетривиальный
 * виджет (voc_handler — список options, bul_handler — чекбокс с особым
 * value), эту функцию не зовут — не натягивается без искажения смысла.
 */
function entity_input(array $data, string $mode, string $widget, array $meta = []): array
{
    $field = $data['field'];
    return [
        'type'   => 'input',
        'widget' => $widget,
        'name'   => $field['raw'],
        'value'  => $mode === 'new' ? '' : (string) ($data['value'] ?? ''),
        'subscr' => $field['subscr'],
        'meta'   => $meta,
    ];
}

/**
 * Общая развилка "validate" для nullable-полей: пустой ввод — не
 * ошибка формата, а вопрос "обязательно ли поле" по ЖИВОЙ схеме
 * колонки (не паспортному дефолту умолчания, а факту из БД — см.
 * комментарии на местах в date/year/time/int/dec_handler, сохранены).
 * null — ввод НЕ пустой, разбор продолжает сам хендлер своей типовой
 * проверкой; формат даты, диапазон года, «целое без дробной части»,
 * разделитель дробного — разные и по правилу, и по тексту ошибки,
 * сюда осознанно не собраны (предостережение обзора Chat).
 */
function entity_nullable_empty(array $field, string $raw): ?array
{
    if ($raw !== '') {
        return null;
    }
    $nullable = (bool) ($field['schema']['nullable'] ?? true);
    return $nullable
        ? ['valid' => true, 'value' => null, 'errors' => []]
        : ['valid' => false, 'value' => null, 'errors' => ['Поле обязательно']];
}

/** Общий ответ на неизвестный режим — одна и та же форма во всех хендлерах. */
function entity_unsupported_mode(string $mode): array
{
    return ['type' => 'error', 'errors' => ["Неподдерживаемый режим '$mode'"]];
}
