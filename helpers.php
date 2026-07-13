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
 * utf8mb4 обязателен: иначе кириллица задваивается.
 */
function admin_db_connect(): mysqli
{
    $cfg = config()['db'];
    $db  = @mysqli_connect($cfg['host'], $cfg['user'], $cfg['password'], $cfg['name']);
    if ($db === false) {
        http_response_code(500);
        exit('Нет соединения с БД: ' . mysqli_connect_error());
    }
    mysqli_set_charset($db, 'utf8mb4');
    return $db;
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
function lookup_labels(mysqli $db_connection, array $dict): array
{
    static $cache = [];
    $source = $dict['source_table'];

    if (isset($cache[$source])) {
        return $cache[$source];
    }

    $labels = [];
    $result = mysqli_query($db_connection, "SELECT * FROM `$source`");
    while ($row = mysqli_fetch_assoc($result)) {
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
