<?php
declare(strict_types=1);

/**
 * GPDP / RNA — общие инструменты сущностей.
 *
 * Не является главным конвейером: этими функциями пользуются
 * обработчики сущностей (entities.php). Ядро сюда не заглядывает.
 * helpers не знают ничего про request и про index.
 *
 * Семейства: lookup_* (словарные выборки), в будущем form_*, sql_*
 * (например date_parts_assemble уедет в семейство сущности date_*).
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
        $text = '';
        foreach ($dict['plan'] as $item) {
            if ($item['kind'] === 'literal') {
                $text .= $item['value'];
                continue;
            }
            if ($item['kind'] === 'field') {
                $text .= (string) ($row[$item['field']] ?? '');
                continue;
            }
            // dict: значение поля — id в вложенном словаре; 0/пусто → ''
            $ref   = (int) ($row[$item['field']] ?? 0);
            $text .= $ref === 0 ? '' : (string) (lookup_labels($db_connection, $item['dict'])[$ref] ?? '');
        }
        $labels[(int) $row['id']] = $text;
    }

    asort($labels, SORT_NATURAL | SORT_FLAG_CASE);

    return $cache[$source] = $labels;
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
