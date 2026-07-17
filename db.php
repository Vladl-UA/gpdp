<?php
declare(strict_types=1);

/**
 * GPDP / RNA — низкоуровневый доступ к БД. Механическая обёртка вызова
 * (журнал 2026-07-13/14) — было под mysqli, с 2026-07-16 переведено на
 * pg_query_params (PostgreSQL). Убирает копии prepare→bind→execute→
 * fetch по всему проекту в несколько функций.
 *
 * НЕ конструктор SQL под диалекты — текст запроса пишет вызывающий
 * код, как и раньше (§16.3: текст SQL живёт в коде, не в данных).
 * Этот файл лишь исполняет уже готовый текст с параметрами.
 *
 * Единственная диалектная механика, которую этот файл берёт на себя
 * СОЗНАТЕЛЬНО (не толкование SQL, а формат вызова API) — конвертация
 * '?'-плейсхолдеров MySQLi-стиля в позиционные '$1,$2,...' Postgres:
 * весь вызывающий код (record_*, configurator_*, lookup_*, labels_*)
 * как писал '?', так и пишет — ни один call site не менялся ради
 * переезда (журнал 2026-07-16, решение STATE.md «Сейчас» п.9).
 *
 * Получение id новой строки — тоже честно объявленная граница:
 * Postgres не имеет аналога mysqli_insert_id(). Вызывающий код,
 * которому нужен id, обязан сам дописать `RETURNING id` в текст
 * своего INSERT (§16.3 — текст SQL решает вызывающий код, не этот
 * файл; здесь только читается результат, если он есть).
 *
 * Управление соединением (db_connect/db_close) — раньше было
 * продублировано в семи местах (index.php, labels.php, diag_refresh.php,
 * smoke_test.php, tools_bulk_import.php, tools_rebuild_snapshot.php,
 * helpers.php::admin_db_connect) — сведено сюда при переезде на
 * Postgres (журнал 2026-07-16): повод пересмотреть прежнее «сознательно
 * оставлено вне db.php» появился вместе с самим переездом.
 */

/**
 * Единая точка подключения. Раньше — несколько копий mysqli_connect+
 * mysqli_set_charset (admin_db_connect + четыре самостоятельных
 * консольных скрипта). $cfg — секция 'db' из config().
 *
 * Бросает RuntimeException при отказе, не exit() — у веб-страницы
 * (admin_db_connect) и у консольного скрипта (smoke_test.php и т.п.)
 * разное уместное поведение при отказе (HTTP 500 против текста в
 * stdout); решает вызывающий код, этот файл не навязывает форму.
 */
function db_connect(array $cfg): PgSql\Connection
{
    $conninfo = sprintf(
        'host=%s port=%d dbname=%s user=%s password=%s options=--client_encoding=UTF8',
        $cfg['host'],
        $cfg['port'] ?? 5432,
        $cfg['name'],
        $cfg['user'],
        $cfg['password']
    );

    $connection = @pg_connect($conninfo);
    if ($connection === false) {
        throw new \RuntimeException(pg_last_error() ?: 'pg_connect вернул false');
    }

    return $connection;
}

function db_close(PgSql\Connection $db_connection): void
{
    pg_close($db_connection);
}

/**
 * '?' (MySQLi-стиль, весь существующий вызывающий код) → '$1,$2,...'
 * (Postgres). Наивная позиционная замена — безопасна здесь, потому что
 * '?' в текстах запросов этого проекта встречается ТОЛЬКО как
 * плейсхолдер (доктрина §16.3 — SQL-текст не несёт данных пользователя
 * иначе, чем через эти плейсхолдеры; литеральных '?' в собственном SQL
 * проекта нет).
 */
function db_placeholders(string $sql): string
{
    $n = 0;
    return preg_replace_callback('/\?/', static function () use (&$n): string {
        $n++;
        return '$' . $n;
    }, $sql);
}

/**
 * Счётчик фактически выполненных запросов за текущий запрос/процесс.
 * 2026-07-16: замена MySQL `SHOW SESSION STATUS LIKE 'Questions'` для
 * N+1-проверки в smoke_test.php — у Postgres нет прямого аналога этой
 * status-переменной (`pg_stat_statements` — отдельное расширение,
 * требует superuser/shared_preload_libraries, к тому же считает по
 * БАЗЕ данных, не по этому соединению — шумит от чужих сессий на
 * общем сервере). Свой счётчик точнее для своей же цели: считает
 * ровно то, что выполнил db.php, ничего больше.
 *
 * db_query_count(1) — инкремент (вызывается db_select/db_execute на
 * каждый реальный запрос, до проверки результата — счёт идёт по
 * попытке выполнения, не по успеху, тот же принцип, что у MySQL
 * Questions); db_query_count() или db_query_count(0) — чтение текущего
 * значения. Одна static-переменная внутри функции — обнуляется каждый
 * новый процесс/запрос сама по себе.
 */
function db_query_count(int $delta = 0): int
{
    static $count = 0;
    $count += $delta;
    return $count;
}

/**
 * Чтение (SELECT). $types игнорируется по смыслу (Postgres сам выводит
 * тип параметра из контекста, типовая строка mysqli не нужна) — параметр
 * ОСТАВЛЕН в сигнатуре ради нулевой правки вызывающего кода, только
 * различает «есть параметры» / «прямой запрос без подготовки».
 *
 * Возврат: массив строк (assoc), как раньше. Пустой массив — и «нет
 * строк», и «ошибка выполнения» — та же осознанная простота, что была
 * под mysqli (для причины ошибки нужен db_execute с его полем 'error').
 */
function db_select(PgSql\Connection $db_connection, string $sql, string $types = '', array $params = []): array
{
    db_query_count(1);

    $result = $types === ''
        ? @pg_query($db_connection, $sql)
        : @pg_query_params($db_connection, db_placeholders($sql), $params);

    if ($result === false) {
        return [];
    }

    return pg_fetch_all($result, PGSQL_ASSOC) ?: [];
}

/**
 * Запись (INSERT/UPDATE/DELETE). Честный результат: ok, affected_rows,
 * id, error. 'id' заполняется, только если сам текст запроса содержит
 * `RETURNING id` (регистронезависимо) — этот файл его не дописывает
 * сам (см. докблок файла); без RETURNING 'id' всегда 0, как раньше
 * mysqli_insert_id() был бы 0/мусором для не-INSERT операций.
 */
function db_execute(PgSql\Connection $db_connection, string $sql, string $types = '', array $params = []): array
{
    db_query_count(1);

    $result = $types === ''
        ? @pg_query($db_connection, $sql)
        : @pg_query_params($db_connection, db_placeholders($sql), $params);

    if ($result === false) {
        return ['ok' => false, 'affected_rows' => 0, 'id' => 0, 'error' => pg_last_error($db_connection)];
    }

    $id = 0;
    if (preg_match('/\bRETURNING\b/i', $sql)) {
        $row = pg_fetch_assoc($result);
        $id  = $row['id'] ?? 0 ? (int) $row['id'] : 0;
    }

    return [
        'ok'            => true,
        'affected_rows' => pg_affected_rows($result),
        'id'            => $id,
        'error'         => '',
    ];
}
