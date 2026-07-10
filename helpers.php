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
function lookup_options(mysqli $db_connection, string $source): array
{
    static $cache = [];

    if (isset($cache[$source])) {
        return $cache[$source];
    }

    $options = [];
    $result  = mysqli_query($db_connection, "SELECT `id`, `data_name` FROM `$source` ORDER BY `data_name`");
    while ($row = mysqli_fetch_assoc($result)) {
        $options[(int) $row['id']] = (string) $row['data_name'];
    }

    return $cache[$source] = $options;
}

/**
 * Подписи по скомпилированной словарной записи: map id → label.
 *
 * Единый вход для всех подтипов словаря (§16.1). Склад/адрес —
 * делегирование в lookup_options (плоский id + data_name). Проекция —
 * сборка подписи в PHP по плану, скомпилированному сборкой снапшота:
 * literal → как есть; field → сырое значение колонки той же строки;
 * dict → рекурсивно подпись вложенного словаря (его запись ВЛОЖЕНА
 * в план при компиляции — исполнителю не нужна карта словарей,
 * ацикличность доказана компилятором, рекурсия конечна).
 *
 * SQL JOIN не собирается принципиально (§16.3: «текст SQL в данных
 * не хранится и не исполняется») — один плоский SELECT источника,
 * склейка в PHP; вложенные словари идут через lookup_options /
 * lookup_labels с их request-кэшами, N+1 не возникает.
 *
 * $dict ОБЯЗАН быть доверенным: скомпилированная запись из пакета
 * field_data (снапшот), не из request.
 */
function lookup_labels(mysqli $db_connection, array $dict): array
{
    if (($dict['subtype'] ?? '') !== 'projection') {
        return lookup_options($db_connection, $dict['source_table']);
    }

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
