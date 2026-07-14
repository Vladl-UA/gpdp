<?php
declare(strict_types=1);

/**
 * GPDP / RNA — низкоуровневый доступ к БД. Механическая обёртка вызова
 * mysqli (журнал 2026-07-13/14, решение — приоритет 0 в STATE.md
 * «Сейчас»): убирает ~150 копий повторяющегося prepare→bind→execute→
 * fetch по всему проекту в две функции.
 *
 * НЕ конструктор SQL под диалекты — текст запроса пишет вызывающий
 * код, как и раньше (§16.3: текст SQL живёт в коде, не в данных).
 * Этот файл лишь исполняет уже готовый текст с параметрами. Диалектные
 * различия (AUTO_INCREMENT/ENGINE=/ON DUPLICATE KEY) этим не решаются
 * и не должны — три места остаются точечными до реального переезда
 * на другую СУБД (см. STATE.md, «портируемость на Postgres»).
 *
 * НЕ верхний уровень — не путать со структурированным планом выборки
 * (`record_select($plan)`, отложен, STATE.md → Позже). Этот файл ничего
 * не знает про модель, снапшот, сущности — просто исполнитель текста.
 *
 * Отдельно от helpers.php: тот — для сущностей («ядро сюда не
 * заглядывает», его же заголовок), этот — наоборот, годен откуда
 * угодно (core.php, configurator.php, labels.php, index.php).
 *
 * Две функции по смыслу возврата, не одна на всё (журнал 07-13):
 * чтение и запись отличаются не механикой вызова (она одна), а тем,
 * что осмысленно вернуть — строки против факта успеха и id.
 */

/**
 * Чтение (SELECT/SHOW). $types/$params пустые — запрос выполняется
 * напрямую, без подготовки (для случаев без плейсхолдеров: например,
 * текст уже несёт доверенные имена таблиц/полей, подставленные
 * ВЫЗЫВАЮЩИМ кодом из проверенной структуры — не из request).
 *
 * Возврат: массив строк (assoc). Пустой массив — как «нет строк»,
 * так и «ошибка выполнения»; для разбора причины ошибки нужен другой
 * инструмент (mysqli_error), здесь сознательно не пробрасывается —
 * это тонкий слой вызова, не место для дипломатии об ошибках.
 */
function db_select(mysqli $db_connection, string $sql, string $types = '', array $params = []): array
{
    if ($types === '') {
        $result = mysqli_query($db_connection, $sql);
        if ($result === false) {
            return [];
        }
        return mysqli_fetch_all($result, MYSQLI_ASSOC);
    }

    $statement = mysqli_prepare($db_connection, $sql);
    if ($statement === false) {
        return [];
    }

    mysqli_stmt_bind_param($statement, $types, ...$params);
    if (!mysqli_stmt_execute($statement)) {
        return [];
    }

    $result = mysqli_stmt_get_result($statement);
    if ($result === false) {
        return [];
    }

    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

/**
 * Запись (INSERT/UPDATE/DELETE). Честный результат, не молчание:
 * ok, affected_rows, id (последний вставленный — для INSERT; для
 * UPDATE/DELETE не имеет смысла, но поле присутствует всегда, для
 * единообразия формы), error (текст, если ok=false).
 */
function db_execute(mysqli $db_connection, string $sql, string $types = '', array $params = []): array
{
    if ($types === '') {
        $ok = mysqli_query($db_connection, $sql) !== false;
        return [
            'ok'            => $ok,
            'affected_rows' => $ok ? mysqli_affected_rows($db_connection) : 0,
            'id'            => $ok ? mysqli_insert_id($db_connection) : 0,
            'error'         => $ok ? '' : mysqli_error($db_connection),
        ];
    }

    $statement = mysqli_prepare($db_connection, $sql);
    if ($statement === false) {
        return ['ok' => false, 'affected_rows' => 0, 'id' => 0, 'error' => mysqli_error($db_connection)];
    }

    mysqli_stmt_bind_param($statement, $types, ...$params);
    if (!mysqli_stmt_execute($statement)) {
        return ['ok' => false, 'affected_rows' => 0, 'id' => 0, 'error' => mysqli_stmt_error($statement)];
    }

    return [
        'ok'            => true,
        'affected_rows' => mysqli_stmt_affected_rows($statement),
        'id'            => mysqli_insert_id($db_connection),
        'error'         => '',
    ];
}
