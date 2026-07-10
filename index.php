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

// sync: 2026-07-10, STATE.md п.7 (well как корень, карта объекта, навигация «Домой»)

// --- 1. boot -----------------------------------------------------------------

require 'config.php';
require 'core.php';
require 'helpers.php';
require 'render.php';


$config = config();
/**
echo '<pre>';
print_r($config);
echo '</pre>';
*/

$db_connection = mysqli_connect(
    $config['db']['host'],
    $config['db']['user'],
    $config['db']['password'],
    $config['db']['name']
);


if ($db_connection === false) {
    http_response_code(500);
    exit('Нет соединения с БД');
}
mysqli_set_charset($db_connection, 'utf8mb4'); // обязательный шаг: иначе кириллица задваивается

// --- (auth / права: здесь, когда утвердим контур) ------------------------------

// --- 2. модель -----------------------------------------------------------------
$snapshot = snapshot_init($db_connection, config()['application']);
if ($snapshot === null) {
    http_response_code(503);
    $lock = snapshot_lock_read();
    if ($lock !== null) {
        exit('Модель недоступна: схема заблокирована (source: '
        . ($lock['source'] ?? '?') . '). Причина: ' . ($lock['reason'] ?? '?'));
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

    echo '<!doctype html><html><head><meta charset="utf-8"><title>GPDP</title>';
    echo render_admin_styles();
    echo '</head><body><h2>Главные таблицы</h2><ul>';
    foreach ($root_candidates as $t_name => $t_label) {
        echo '<li><a href="?_table=' . rawurlencode($t_name) . '&_action=view">'
            . render_escape($t_label) . '</a></li>';
    }
    echo '</ul></body></html>';
    mysqli_close($db_connection);
    exit;
}

$request_table = (string) ($_POST['_table'] ?? $_GET['_table'] ?? $snapshot['application']['root_table'] ?? '');
$request_id    = (int) ($_POST['_id'] ?? $_GET['_id'] ?? 0);

// Проверка 3/4 из десяти: таблица существует в известной модели.
if (!isset($snapshot['structure']['tables'][$request_table])) {
    http_response_code(404);
    exit('Неизвестная таблица');
}
$task_table  = $request_table;
$task_fields = $snapshot['structure']['tables'][$task_table]['fields'];

// --- 4. действия записи: операции конвейера, не режимы сущностей ----------------
// PRG: после успешной записи — redirect на просмотр, не отрисовка
// (страхует от повторной отправки формы и возвращает к исходной точке).

if (in_array($request_action, ['save_new', 'save_edit', 'delete'], true)) {

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

    $outcome = match ($request_action) {
        'save_new'  => record_save($db_connection, $snapshot, $task_table, $_POST, null, $task_structural),
        'save_edit' => record_save($db_connection, $snapshot, $task_table, $_POST, $request_id),
        'delete'    => record_delete($db_connection, $snapshot, $task_table, $request_id),
    };

    if ($outcome['ok']) {
        // Ребёнок создан из карточки родителя → вернуться в неё,
        // а не в плоский список дочерней таблицы.
        $back = $task_structural !== []
            ? '?_table=' . rawurlencode($parent_table) . '&_action=edit&_id=' . $parent_id
            : '?_table=' . rawurlencode($task_table) . '&_action=view';
        header('Location: ' . $back);
        exit;
    }

    http_response_code(422);
    echo '<h3>Запись не выполнена</h3><ul>';
    foreach ($outcome['errors'] as $error) {
        echo '<li>' . render_escape($error) . '</li>';
    }
    echo '</ul><p><a href="javascript:history.back()">Назад к форме</a></p>';
    exit;
}

// --- 5. действия чтения: единственная дверь к режимам сущностей ------------------
$mode = action_mode($request_action);
if ($mode === null) {
    http_response_code(400);
    exit('Неизвестное действие');
}

$table_title = render_escape(
    (string) ($snapshot['presentation']['labels']['table'][$task_table]['data_full'] ?? $task_table)
);

echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>$table_title</title>";
echo render_admin_styles();
echo '</head><body>';
echo '<p><a class="home-link" href="?_action=home">← Домой</a></p>';
echo "<h2>$table_title</h2>";

// --- 6. конвейер + рендер ---------------------------------------------------------

// Рекурсивный рендер карты объекта: узел (поля записи) + все его дети,
// развёрнутые вглубь до листьев. Обход уже сделан record_tree() —
// здесь только вывод готовой структуры. Отступ по глубине даёт
// визуальную вложенность дерева.
function render_object_map(
    array $node, array $snapshot, mysqli $db_connection, int $depth = 0
): void {
    $task_table = $node['table'];
    $indent     = $depth * 24;

    // Поля самой записи — read-строкой (значения через конвейер).
    $task_fields = $snapshot['structure']['tables'][$task_table]['fields'] ?? [];
    echo '<div style="margin-left:' . $indent . 'px;border-left:2px solid #dde;padding-left:12px;margin-bottom:8px">';
    echo '<table border="1" cellpadding="4"><tr>';
    foreach ($task_fields as $field_name => $field_schema) {
        if ($field_schema['kind'] !== 'entity_field') {
            continue;
        }
        $short = $snapshot['presentation']['labels']['field'][$task_table][$field_name] ?? [];
        echo '<th>' . render_escape((string) ($short['data_short'] ?? $field_name)) . '</th>';
    }
    echo '</tr><tr>';
    foreach ($task_fields as $field_name => $field_schema) {
        if ($field_schema['kind'] !== 'entity_field') {
            continue;
        }
        $data   = field_data($snapshot, $db_connection, $task_table, $field_name, $node['row'][$field_name], $node['row']);
        $result = field_exec($data, 'read');
        echo '<td>' . ($result !== null ? render_value($result) : '') . '</td>';
    }
    echo '</tr></table>';

    $id = $node['id'];
    echo "<p><a class=\"act\" href=\"?_table=$task_table&_action=edit&_id=$id\" title=\"править\">~</a>"
       . "<a class=\"act act-danger\" href=\"?_table=$task_table&_action=delete&_id=$id\" title=\"удалить\">×</a></p>";

    // Дети — каждый блок с заголовком таблицы, рекурсивно вглубь.
    foreach ($node['children'] as $block) {
        echo '<div style="margin-left:' . ($indent + 24) . 'px">';
        echo '<h4>' . render_escape($block['label']) . '</h4>';
        foreach ($block['nodes'] as $child_node) {
            render_object_map($child_node, $snapshot, $db_connection, $depth + 1);
        }
        echo '<p><a href="?_table=' . rawurlencode($block['table'])
            . '&_action=new&_parent_table=' . rawurlencode($task_table)
            . "&_parent_id=$id\">+ добавить в «" . render_escape($block['label']) . '»</a></p>';
        echo '</div>';
    }
    echo '</div>';
}

if ($mode === 'read' && $request_id === 0) {
    // Список: строка за строкой, поле за полем — та самая одна строчка
    // field_exec() на каждую ячейку. SQL живёт в record_list.
    $rows = record_list($db_connection, $snapshot, $task_table);

    echo '<table border="1" cellpadding="4"><tr>';
    foreach ($task_fields as $field_name => $field_schema) {
        if ($field_schema['kind'] !== 'entity_field') {
            continue;
        }
        $short = $snapshot['presentation']['labels']['field'][$task_table][$field_name] ?? [];
        echo '<th>' . render_escape((string) ($short['data_short'] ?? $field_name)) . '</th>';
    }
    echo '<th></th></tr>';

    foreach ($rows as $row) {
        $row_id    = (int) $row['id'];
        $card_href = "?_table=$task_table&_action=view&_id=$row_id";
        $is_first  = true;

        echo '<tr>';
        foreach ($task_fields as $field_name => $field_schema) {
            if ($field_schema['kind'] !== 'entity_field') {
                continue;
            }
            $data   = field_data($snapshot, $db_connection, $task_table, $field_name, $row[$field_name], $row);
            $result = field_exec($data, 'read');
            $value  = $result !== null ? render_value($result) : '';
            // Первая ячейка — крупная область клика на карточку объекта
            // (не форму редактирования — read-карта, ссылки на edit/delete
            // внутри неё явные), не только маленький значок ~ в конце
            // строки — так навигация естественна и на телефоне.
            echo $is_first
                ? "<td><a href=\"$card_href\">$value</a></td>"
                : "<td>$value</td>";
            $is_first = false;
        }
        echo "<td><a class=\"act\" href=\"?_table=$task_table&_action=edit&_id=$row_id\" title=\"править\">~</a>"
           . "<a class=\"act act-danger\" href=\"?_table=$task_table&_action=delete&_id=$row_id\" title=\"удалить\">×</a></td>";
        echo '</tr>';
    }
    echo '</table>';
    echo "<p><a href=\"?_table=$task_table&_action=new\">Добавить запись</a></p>";
}

if ($mode === 'read' && $request_id > 0) {
    // Карта объекта: вся глубина дерева сразу (STATE.md п.3, решение
    // Влада 07-09 «карта есть карта, без ограничений»). record_tree
    // обходит граф в структуру, render_object_map разворачивает её
    // рекурсивно — обход и вывод раздельны (в отличие от легаси db_tree,
    // мешавшего обход, SQL и HTML в одной функции).
    $tree = record_tree($db_connection, $snapshot, $task_table, $request_id);
    if ($tree === null) {
        http_response_code(404);
        exit('Запись не найдена');
    }
    render_object_map($tree, $snapshot, $db_connection);
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
        echo '<p>В составе: <b>'
            . render_escape(record_label($db_connection, $snapshot, $parent_table, $parent_id))
            . '</b></p>';
    }

    echo '<form method="post">';
    echo "<input type=\"hidden\" name=\"_action\" value=\"$save_action\">";
    echo '<input type="hidden" name="_table" value="' . render_escape($task_table) . '">';
    if ($mode === 'edit') {
        echo '<input type="hidden" name="_id" value="' . $request_id . '">';
    }
    if ($parent_ok) {
        echo '<input type="hidden" name="_parent_table" value="' . render_escape($parent_table) . '">';
        echo '<input type="hidden" name="_parent_id" value="' . $parent_id . '">';
    }

    foreach ($task_fields as $field_name => $field_schema) {
        if ($field_schema['kind'] !== 'entity_field') {
            continue; // структурные поля в форму не попадают вовсе
        }
        $data   = field_data($snapshot, $db_connection, $task_table, $field_name, $row[$field_name] ?? null, $row);
        $result = field_exec($data, $mode);
        if ($result !== null) {
            echo render_form_element($result);
        }
    }

    echo '<p><input type="submit" value="Сохранить"></p></form>';

    // Отмена — назад туда, где логически "живёт" запись: у edit — на её
    // же карточку; у new-ребёнка — на карточку родителя; у new без
    // родителя (создание с нуля, вне карточки) — в список таблицы.
    $cancel_href = match (true) {
        $mode === 'edit' => "?_table=$task_table&_action=view&_id=$request_id",
        $parent_ok       => '?_table=' . rawurlencode($parent_table) . "&_action=view&_id=$parent_id",
        default          => "?_table=$task_table&_action=view",
    };
    echo "<p><a href=\"$cancel_href\">Отмена</a></p>";
}

// --- 7. завершение -----------------------------------------------------------------
echo '</body></html>';
mysqli_close($db_connection);
