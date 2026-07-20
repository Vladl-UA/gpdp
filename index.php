<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
/**
 * GPDP / RNA — входная точка. Дирижёр жизненного цикла, не библиотека
 * логики: index не знает, как работают data, voc, date. Он доводит
 * грязный request до доверенного задания и передаёт его конвейеру.
 *
 * Цикл:  boot → config → db → charset → (auth: контур не утверждён,
 *        место зарезервировано) → разбор request → подготовка задания
 *        → конвейер → структурированный результат → render | PRG.
 *
 * Не request → pipeline, а request → prepared task → pipeline.
 * Всё недоверенное умирает на этапе подготовки; дальше границы
 * сырой request не проходит.
 */

// sync: 2026-07-11, view-слой п.8бв — список, карта и форма через сборщики core + укладчики render

// --- 1. boot -----------------------------------------------------------------

require_once 'config.php';
require_once 'db.php';
require_once 'core.php';
require_once 'helpers.php';
require_once 'render.php';


$db_connection = admin_db_connect();

// --- (auth / права: здесь, когда утвердим контур) ------------------------------

// --- 1а. Контекст — РАНЬШЕ модели (STATE.md «Позже», дорожная карта
// единого входа; находка Chat 2026-07-17: configurator обязан работать,
// когда снапшот сломан — строгий snapshot_init ниже не должен
// блокировать контур, которому именно это и нужно чинить).
// Стадия 5: configurator.php/labels.php подключаются условно, по
// контексту — тем же require_once, что уже используют они сами
// (guard в них проверяет $_SERVER['SCRIPT_FILENAME'] против
// basename(__FILE__), не совпадёт с index.php — самозагрузка внутри
// НЕ сработает повторно, дispatch зовётся отсюда явно, один раз).
$context = request_context($_GET, $_POST);
if ($context === null) {
    http_response_code(400);
    exit('Неизвестный _context.');
}
if ($context === 'configurator') {
    require_once 'configurator.php';
    configurator_dispatch($db_connection);
    db_close($db_connection);
    exit;
}
if ($context === 'labels') {
    require_once 'labels.php';
    labels_dispatch($db_connection);
    db_close($db_connection);
    exit;
}

// --- 2. модель -----------------------------------------------------------------
$snapshot = snapshot_init($db_connection, config()['application']);
if ($snapshot === null) {
    http_response_code(503);
    $lock = snapshot_lock_read();
    if ($lock !== null) {
        $age = isset($lock['started_at'])
            ? render_duration(max(0, time() - (int) $lock['started_at']))
            : 'неизвестно';
        exit('Модель недоступна: схема заблокирована (source: '
        . ($lock['source'] ?? '?') . '). Причина: ' . ($lock['reason'] ?? '?')
        . '. Держится: ' . $age
        . '. Если операция давно закончилась — «Состояние модели» в конфигураторе снимет лок с проверкой.');
    }
    exit('Модель недоступна. '
    . (snapshot_last_error() ?? 'Причина не зафиксирована — проверьте права на state/.'));
}
// --- 3. разбор request → подготовленное задание ---------------------------------
// Hidden-поля и параметры URL — подсказки, не факты (ARCHITECTURE.md §9):
// каждая сверяется с известной моделью прежде, чем попасть в задание.

$request_action = (string) ($_POST['_action'] ?? $_GET['_action'] ?? 'view');

// --- 3а. «Домой» — точка входа без конкретной таблицы --------------------------
// Критерий «главная таблица» ВРЕМЕННЫЙ (STATE.md «Сейчас» п.7, журнал
// 07-09): отсутствие полей dep_/rel_main. Не компилируется в снапшот —
// сам автор критерия называет его слабой заглушкой на время обкатки;
// компилировать временное в модельный слой значило бы усложнять под то,
// что скоро заменится. Считается на лету, один проход по уже
// загруженной структуре, без новых запросов. voc_-таблицы (словари)
// из списка исключены явно: по критерию «нет dep_/rel_» они тоже
// формально проходят, но захламляют навигацию — не то, что имелось
// в виду под «главными таблицами» (решение принято здесь, не спрошено
// отдельно — при несогласии легко вернуть одной строкой).
$table_requested = isset($_GET['_table']) || isset($_POST['_table']);

if (!$table_requested || $request_action === 'home') {
    $root_candidates = [];
    foreach ($snapshot['structure']['tables'] as $t_name => $t_schema) {
        if (str_starts_with($t_name, 'voc_')) {
            continue;
        }
        $has_subordination = false;
        foreach ($t_schema['fields'] as $f_name => $f_schema) {
            if ($f_name === 'rel_main' || str_starts_with($f_name, 'dep_')) {
                $has_subordination = true;
                break;
            }
        }
        if (!$has_subordination) {
            $root_candidates[$t_name] = (string) (
                $snapshot['presentation']['labels']['table'][$t_name]['data_full'] ?? $t_name
            );
        }
    }
    asort($root_candidates, SORT_NATURAL | SORT_FLAG_CASE);

    echo render_admin_page_open('GPDP', 'data');
    echo render_data_home($root_candidates);
    render_admin_page_close();
    db_close($db_connection);
    exit;
}

$request_table = (string) ($_POST['_table'] ?? $_GET['_table'] ?? $snapshot['application']['root_table'] ?? '');
$request_id    = (int) ($_POST['_id'] ?? $_GET['_id'] ?? 0);

// Проверка 3/4 из десяти: таблица существует в известной модели.
if (!isset($snapshot['structure']['tables'][$request_table])) {
    http_response_code(404);
    exit('Неизвестная таблица');
}
$task_table = $request_table;

// --- 4. действия записи: операции конвейера, не режимы сущностей ----------------
// PRG: после успешной записи — redirect на просмотр, не отрисовка
// (страхует от повторной отправки формы и возвращает к исходной точке).

if (in_array($request_action, ['save_new', 'save_edit', 'delete', 'save_reparent'], true)) {

    // Родитель из request — подсказка (§9): становится доверенным
    // структурным фактом только после сверки с model.relations
    // (эта пара родитель→ребёнок реально существует в графе) и
    // существования самой родительской записи. Иначе — 422, не
    // молчаливое игнорирование.
    $task_structural = [];
    $parent_table    = (string) ($_POST['_parent_table'] ?? '');
    $parent_id       = (int) ($_POST['_parent_id'] ?? 0);

    if ($request_action === 'save_new' && $parent_table !== '') {
        $parent_fk = null;
        foreach ($snapshot['model']['relations'][$parent_table] ?? [] as $relation) {
            if ($relation['child'] === $task_table) {
                $parent_fk = $relation['fk'];
                break;
            }
        }
        if ($parent_fk === null
            || record_fetch($db_connection, $snapshot, $parent_table, $parent_id) === null) {
            http_response_code(422);
            exit('Родительская привязка не подтверждена моделью');
        }
        $task_structural = [$parent_fk => $parent_id];
    }

    // Смена родителя (STATE.md «Сейчас» п.5) — значение, не имя поля:
    // само имя FK резолвит record_reparent() из модели, не отсюда.
    $new_parent_id = (int) ($_POST['_new_parent_id'] ?? 0);

    $outcome = match ($request_action) {
        'save_new'      => record_save($db_connection, $snapshot, $task_table, $_POST, null, $task_structural),
        'save_edit'     => record_save($db_connection, $snapshot, $task_table, $_POST, $request_id),
        'delete'        => record_delete($db_connection, $snapshot, $task_table, $request_id),
        'save_reparent' => record_reparent($db_connection, $snapshot, $task_table, $request_id, $new_parent_id),
    };

    if ($outcome['ok']) {
        // Возврат «откуда позвали» (несён формой). Если его нет —
        // прежняя логика: ребёнок из карточки родителя → в родителя,
        // reparent без _return → на собственную карточку записи,
        // иначе → в список таблицы.
        $return = (string) ($_POST['_return'] ?? '');
        if ($return !== '' && str_starts_with($return, '/')) {
            $back = $return;
        } elseif ($task_structural !== []) {
            $back = '?_table=' . rawurlencode($parent_table) . '&_action=edit&_id=' . $parent_id;
        } elseif ($request_action === 'save_reparent') {
            $back = '?_table=' . rawurlencode($task_table) . '&_action=view&_id=' . $request_id;
        } else {
            $back = '?_table=' . rawurlencode($task_table) . '&_action=view';
        }
        header('Location: ' . $back);
        exit;
    }

    http_response_code(422);
    echo render_save_errors($outcome['errors']);
    exit;
}

// --- 4а. форма смены родителя — отдельное защищённое действие (STATE.md ---------
// «Сейчас» п.5): не режим сущности, не идёт через action_mode/field_exec,
// работает со структурным полем dep_ напрямую поверх record_reparent_view.
if ($request_action === 'reparent') {
    $ref      = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $ref_path = $ref !== '' ? (parse_url($ref, PHP_URL_PATH) ?? '') : '';
    $hidden   = ['_action' => 'save_reparent', '_table' => $task_table, '_id' => $request_id];
    if ($ref_path !== '') {
        $ref_q = parse_url($ref, PHP_URL_QUERY);
        $hidden['_return'] = $ref_path . ($ref_q ? '?' . $ref_q : '');
    }

    $view = record_reparent_view($db_connection, $snapshot, $task_table, $request_id, $hidden);
    if ($view === null) {
        http_response_code(422);
        exit("Смена родителя недоступна для '$task_table'#$request_id");
    }

    $table_title = (string) ($snapshot['presentation']['labels']['table'][$task_table]['data_full'] ?? $task_table);
    echo render_admin_page_open($table_title, 'data');
    echo render_page_heading("Смена родителя: $table_title");
    echo render_reparent_form($view);
    echo render_link_line("?_table=$task_table&_action=view&_id=$request_id", 'Отмена');
    render_admin_page_close();
    db_close($db_connection);
    exit;
}

// --- 5. действия чтения: единственная дверь к режимам сущностей ------------------
$mode = action_mode($request_action);
if ($mode === null) {
    http_response_code(400);
    exit('Неизвестное действие');
}

$table_title = (string) ($snapshot['presentation']['labels']['table'][$task_table]['data_full'] ?? $task_table);

echo render_admin_page_open($table_title, 'data');
echo render_page_heading($table_title);

// --- 6. конвейер + рендер ---------------------------------------------------------

if ($mode === 'read' && $request_id === 0) {
    // Список через view-слой (п.8б): ядро собирает заготовку, render
    // укладывает. Кустарный цикл здесь убран — одна пара функций на
    // все экраны-списки, один стиль таблицы.
    $view = record_table_view($db_connection, $snapshot, $task_table);
    echo render_record_table($view, [
        'card_href'   => "?_table=$task_table&_action=view&_id={id}",
        'edit_href'   => "?_table=$task_table&_action=edit&_id={id}",
        'delete_href' => "?_table=$task_table&_action=delete&_id={id}",
    ]);
    echo render_link_line("?_table=$task_table&_action=new", 'Добавить запись');
}

if ($mode === 'read' && $request_id > 0) {
    // Карта объекта: вся глубина дерева сразу (STATE.md п.3, решение
    // Влада 07-09 «карта есть карта, без ограничений»). record_tree
    // record_tree обходит граф в структуру и прикрепляет к каждому узлу
    // готовую заготовку (view-слой, п.8б); render_object_tree только
    // укладывает — разбор полей и доступ к БД остаются в ядре.
    $tree = record_tree($db_connection, $snapshot, $task_table, $request_id);
    if ($tree === null) {
        http_response_code(404);
        exit('Запись не найдена');
    }
    render_object_tree($tree);
}

if ($mode === 'new' || $mode === 'edit') {
    $row = null;
    if ($mode === 'edit') {
        $row = record_fetch($db_connection, $snapshot, $task_table, $request_id);
        if ($row === null) {
            http_response_code(404);
            exit('Запись не найдена');
        }
    }

    $save_action = $mode === 'new' ? 'save_new' : 'save_edit';

    // Контекст родителя при создании ребёнка: параметры URL — подсказки,
    // сверяются с графом здесь же (не подтвердились → форма без привязки,
    // честная запись в корень; окончательная проверка — на save).
    $parent_table = (string) ($_GET['_parent_table'] ?? '');
    $parent_id    = (int) ($_GET['_parent_id'] ?? 0);
    $parent_ok    = false;
    if ($mode === 'new' && $parent_table !== '') {
        foreach ($snapshot['model']['relations'][$parent_table] ?? [] as $relation) {
            if ($relation['child'] === $task_table) {
                $parent_ok = record_fetch($db_connection, $snapshot, $parent_table, $parent_id) !== null;
                break;
            }
        }
    }
    if ($parent_ok) {
        echo render_parent_context(record_label($db_connection, $snapshot, $parent_table, $parent_id));
    }

    // Технические скрытые поля формы (PRG): действие, таблица, id для
    // edit, родитель для new-ребёнка. Собираются здесь, в дирижёре, из
    // разобранного запроса — идут в заготовку формы как есть.
    $hidden = ['_action' => $save_action, '_table' => $task_table];
    if ($mode === 'edit') {
        $hidden['_id'] = $request_id;
    }
    if ($parent_ok) {
        $hidden['_parent_table'] = $parent_table;
        $hidden['_parent_id']    = $parent_id;
    }
    // Возврат «откуда позвали»: адрес страницы, с которой открыли форму
    // (Referer при показе), несём через форму — после сохранения вернёмся
    // ровно туда. Только локальные пути (ведущий /), чужой Referer не берём.
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $ref_path = $ref !== '' ? (parse_url($ref, PHP_URL_PATH) ?? '') : '';
    if ($ref_path !== '') {
        $ref_q = parse_url($ref, PHP_URL_QUERY);
        $hidden['_return'] = $ref_path . ($ref_q ? '?' . $ref_q : '');
    }

    // Форма через view-слой (п.8в): ядро собирает заготовку (виджеты
    // полей в режиме new/edit), render укладывает. Кустарный цикл убран.
    $view = record_form_view($db_connection, $snapshot, $task_table, $mode, $row, $hidden);
    echo render_record_form($view);

    // Отмена — назад туда, где логически "живёт" запись: у edit — на её
    // же карточку; у new-ребёнка — на карточку родителя; у new без
    // родителя (создание с нуля, вне карточки) — в список таблицы.
    $cancel_href = match (true) {
        $mode === 'edit' => "?_table=$task_table&_action=view&_id=$request_id",
        $parent_ok       => '?_table=' . rawurlencode($parent_table) . "&_action=view&_id=$parent_id",
        default          => "?_table=$task_table&_action=view",
    };
    echo render_link_line($cancel_href, 'Отмена');
}

// --- 7. завершение -----------------------------------------------------------------
render_admin_page_close();
db_close($db_connection);
