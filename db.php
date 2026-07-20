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
/**
 * Экранирование одного значения libpq conninfo (формат `key=value`,
 * НЕ URI): значения с пробелом должны быть в одинарных кавычках,
 * кавычка и обратный слэш внутри значения — экранированы обратным
 * слэшем (libpq, не SQL-экранирование — другой формат, другая функция).
 * Без этого пароль/имя с пробелом или `'` рвёт разбор строки
 * подключения молча (найдено обзором Chat 2026-07-20, docs.postgresql.org
 * §33.1.1). Пустая строка — валидное значение, тоже в кавычках.
 */
function db_conninfo_escape(string $value): string
{
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
}

function db_connect(array $cfg): PgSql\Connection
{
    $conninfo = sprintf(
        'host=%s port=%d dbname=%s user=%s password=%s options=--client_encoding=UTF8',
        db_conninfo_escape((string) $cfg['host']),
        $cfg['port'] ?? 5432,
        db_conninfo_escape((string) $cfg['name']),
        db_conninfo_escape((string) $cfg['user']),
        db_conninfo_escape((string) $cfg['password'])
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
 * строк», и «ошибка выполнения» — сознательная простота по умолчанию
 * (была под mysqli, сохранена для большинства мест, где оба исхода
 * ведут к одному и тому же уместному поведению). Где различие важно —
 * db_select_result() ниже, честный контракт ok/rows/error, тот же
 * приём, что у db_execute() (обзор Chat 2026-07-20, п.7).
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
 * Зеркало db_select() с честным результатом: ok, rows, error — тот же
 * контракт, что уже был у db_execute() (обзор Chat 2026-07-20, п.7).
 * db_select() НЕ упраздняется и не становится обёрткой над этой
 * функцией: для большинства экранных выборок «нет строк» и «запрос не
 * выполнился» ведут к одному и тому же уместному поведению (пустой
 * список), и заводить сюда явную проверку ok/error было бы обороной
 * без потребителя (§13). db_select_result() — для мест, где сегодня
 * ошибка запроса уже маскируется под содержательный факт модели:
 * «записи нет» вместо «не проверено», «уже не под управлением» вместо
 * «не удалось спросить», «в колонке нет данных» вместо «не посчитано»
 * — переведены именно такие call site'ы (labels.php::
 * model_label_registry_id, core.php::record_fetch/model_diagnose,
 * configurator.php::configurator_is_managed/configurator_drop_field).
 * Интроспекция схемы (snapshot_build_structure) сознательно НЕ
 * переведена в этот же заход — у неё уже есть отдельный fail-fast
 * (snapshot_validate отвергает структуру с нулём таблиц), контейнер
 * сессии не даёт живьём проверить более глубокую правку там, риск
 * трогать вслепую самый горячий путь bootstrap выше пользы здесь и
 * сейчас — см. STATE.md.
 */
function db_select_result(PgSql\Connection $db_connection, string $sql, string $types = '', array $params = []): array
{
    db_query_count(1);

    $result = $types === ''
        ? @pg_query($db_connection, $sql)
        : @pg_query_params($db_connection, db_placeholders($sql), $params);

    if ($result === false) {
        return ['ok' => false, 'rows' => [], 'error' => pg_last_error($db_connection)];
    }

    return ['ok' => true, 'rows' => pg_fetch_all($result, PGSQL_ASSOC) ?: [], 'error' => ''];
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

/**
 * Управление транзакцией. Три тонкие функции, по одному запросу
 * каждая — не менеджер транзакций: нет вложенности, нет SAVEPOINT,
 * нет автоматического отката по деструктору. Та же граница
 * механической обёртки вызова, что у db_select/db_execute (решение
 * журнала 2026-07-13): формат вызова берём на себя, смысл операции —
 * нет, порядок BEGIN/COMMIT/ROLLBACK решает вызывающий код.
 *
 * 2026-07-19: примитивы в проекте существовали и раньше, но сырым
 * pg_query() прямо в tools_bulk_import.php (dry-run с откатом) — мимо
 * db.php и без проверки результата. Здесь они подняты в свой слой и
 * получили честный результат; call site в bulk-import переведён тем
 * же коммитом (правило «функция и её вызовы едут вместе»).
 *
 * Потребитель — структурные операции конфигуратора (§17: цепочка
 * lock → DDL → реестр → rebuild → validate → unlock объявлена единой
 * операцией) и dry-run оптовой загрузки. Обычный record_save() их НЕ
 * использует и не должен: одна строка одним запросом атомарна сама
 * по себе, транзакция вокруг неё была бы обороной против
 * несуществующей угрозы (§13).
 *
 * Проверено живьём на Postgres 16 (2026-07-19, отдельный кластер):
 * DDL внутри транзакции виден собственной интроспекции до COMMIT —
 * information_schema.tables/columns и pg_index/regclass показывают
 * незакоммиченные CREATE TABLE и CREATE VIEW, ROLLBACK убирает и то
 * и другое. Именно на этом держится порядок «собрать snapshot в
 * памяти → COMMIT → опубликовать файл».
 *
 * Честная оговорка: ROLLBACK возвращает не всё. Последовательность
 * IDENTITY не откатывается никогда (общее свойство SQL-последователь-
 * ностей, не специфика диалекта) — после отката в нумерации остаётся
 * дыра. Для этого проекта безвредно (id суррогатный, гэпы уже
 * встречались — журнал 2026-07-05 и dry-run оптовой загрузки), но
 * рассчитывать на непрерывность id нельзя.
 */
function db_transaction_begin(PgSql\Connection $db_connection): array
{
    return db_execute($db_connection, 'BEGIN');
}

function db_transaction_commit(PgSql\Connection $db_connection): array
{
    return db_execute($db_connection, 'COMMIT');
}

/**
 * Откат. Вызывается на пути отказа, где уже произошла одна ошибка —
 * поэтому свой результат возвращает, но вызывающий код обычно его не
 * проверяет: сообщать пользователю надо про ПЕРВУЮ причину, не про
 * вторичную. Отказ самого ROLLBACK означает потерю соединения, при
 * которой транзакция откатывается сервером сама.
 */
function db_transaction_rollback(PgSql\Connection $db_connection): array
{
    return db_execute($db_connection, 'ROLLBACK');
}
