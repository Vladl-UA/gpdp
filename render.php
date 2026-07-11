<?php
declare(strict_types=1);

/**
 * GPDP / RNA — renderer: структурированный результат → формат вывода.
 *
 * Единственный слой, где рождается HTML. render_* не лезет в БД. Renderer не знает про
 * сущности — только про типы элементов результата (value / input /
 * choice / error). Тот же результат может стать JSON, CSV, текстом —
 * добавлением функции, без правки сущностей (ARCHITECTURE.md §3).
 *
 * Подпись берётся из скомпилированной строки model_labels (§17)
 * прямым обращением по ключу: data_full — полная (формы),
 * data_short — короткая (шапки таблиц). Никакого промежуточного
 * маппинга в label.
 */

// sync: 2026-07-11, общий каркас админ-страниц + render_object_tree (view-слой п.8а)

/**
 * Общий вид админ-интерфейсов (index.php, configurator.php) — один
 * источник CSS, не два независимых куска, которые разойдутся при
 * следующей правке. Не про сущности — про оболочку страницы, поэтому
 * живёт в render.php, а не дублируется в каждом файле-потребителе.
 */
function render_admin_styles(): string
{
    return <<<CSS
    <style>
    body{font-family:sans-serif;margin:2em;color:#222;text-align:left}
    table{border-collapse:collapse;width:100%;margin-bottom:1em}
    td,th{border:1px solid #ccc;padding:6px 10px;text-align:left}
    td:first-child a{display:block;margin:-6px -10px;padding:6px 10px;color:inherit;text-decoration:none}
    td:first-child a:hover{background:#eef}
    ul{padding-left:20px}
    li{margin-bottom:4px}
    .badge{display:inline-block;background:#eef;color:#225;border-radius:3px;padding:1px 6px;font-size:.8em;margin-left:6px}
    .flash{padding:8px 12px;margin-bottom:1em;border-radius:4px}
    .flash-ok{background:#e6f6e6}
    .flash-err{background:#f8e2e2}
    fieldset{margin-bottom:.5em}
    .field-row{display:flex;gap:8px;align-items:center;margin-bottom:6px}
    .field-row select, .field-row input{padding:4px}
    .home-link{display:inline-block;margin-bottom:1em}
    .act{display:inline-block;min-width:1.6em;text-align:center;text-decoration:none;
         border:1px solid #ccc;border-radius:3px;padding:1px 6px;margin-left:4px;color:#225}
    .act:hover{background:#eef}
    .act-danger{color:#a22}
    .act-danger:hover{background:#fee}
    </style>
    CSS;
}

/**
 * Открытие админ-страницы: doctype, head (charset, title, общие стили),
 * открытый body и строка навигации. Один каркас на index.php /
 * configurator.php / labels.php — тем же ходом, что render_admin_styles():
 * страницы остаются хозяевами содержимого, обвязка — одна.
 *
 * $nav_html   — готовый HTML строки навигации (ссылки различаются по
 *               страницам; пустая строка — без навигации);
 * $extra_head — дополнительный <style>/<meta> конкретной страницы.
 */
function render_admin_page_open(string $title, string $nav_html = '', string $extra_head = ''): string
{
    $out = '<!doctype html><html><head><meta charset="utf-8"><title>'
         . render_escape($title) . '</title>'
         . render_admin_styles()
         . $extra_head
         . '</head><body>';
    if ($nav_html !== '') {
        $out .= '<p>' . $nav_html . '</p>';
    }
    return $out;
}

/**
 * Блок флеш-сообщения. null — пустая строка (ничего не выводится).
 * Экранирование — на вызывающем: часть страниц кладёт в флеш уже
 * экранированный текст (configurator), часть — сырой (labels).
 */
function render_admin_flash(?string $message, bool $ok): string
{
    if ($message === null || $message === '') {
        return '';
    }
    return '<div class="flash ' . ($ok ? 'flash-ok' : 'flash-err') . '">' . $message . '</div>';
}

function render_escape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/** Значение для ячейки таблицы / просмотра. */
function render_value(array $result): string
{
    return render_escape((string) ($result['value'] ?? ''));
}

/** Элемент формы с подписью. */
function render_form_element(array $result): string
{
    $label = render_escape((string) ($result['subscr']['data_full'] ?? $result['name'] ?? ''));

    $element = match ($result['type'] ?? '') {
        'input'  => render_input($result),
        'choice' => render_choice($result),
        'value'  => render_value($result),
        default  => '',
    };

    if ($element === '') {
        return '';
    }

    return "<div class=\"form_field_name\">$label</div>"
         . "<div class=\"form_element\">$element</div>\n";
}

function render_input(array $result): string
{
    $name  = render_escape((string) ($result['name'] ?? ''));
    $value = render_escape((string) ($result['value'] ?? ''));

    // meta — доверенные HTML-атрибуты ОТ ХЕНДЛЕРА (не request): step,
    // maxlength и т.п. Renderer не решает, что нужно конкретной сущности —
    // просто прокидывает то, что хендлер уже подготовил. Общий механизм,
    // не завязан на конкретное поле/тип (§15.8).
    $attrs = '';
    foreach ($result['meta'] ?? [] as $attr_name => $attr_value) {
        $attrs .= ' ' . render_escape((string) $attr_name) . '="' . render_escape((string) $attr_value) . '"';
    }

    return match ($result['widget'] ?? 'text') {
        'textarea' => "<textarea name=\"$name\"$attrs rows=\"5\" cols=\"40\">$value</textarea>",
        'checkbox' => '<input type="checkbox" name="' . $name . '" value="1"'
        . (($result['value'] ?? 0) ? ' checked' : '') . '>',
        'hidden'   => "<input type=\"hidden\" name=\"$name\" value=\"$value\">",
        'date'     => "<input type=\"date\" name=\"$name\" value=\"$value\"$attrs>",
        'time'     => "<input type=\"time\" name=\"$name\" value=\"$value\"$attrs>",
        'number'   => "<input type=\"number\" name=\"$name\" value=\"$value\"$attrs>",
        default    => "<input type=\"text\" name=\"$name\" value=\"$value\"$attrs>",
    };
}
function render_choice(array $result): string
{
    $name    = render_escape((string) ($result['name'] ?? ''));
    $current = $result['value'] ?? null;
    $options = '';

    foreach ($result['options'] ?? [] as $option) {
        $value    = render_escape((string) $option['value']);
        $label    = render_escape((string) $option['label']);
        $selected = $option['value'] === $current ? ' selected' : '';
        $options .= "<option value=\"$value\"$selected>$label</option>";
    }

    return "<select name=\"$name\">$options</select>";
}


/**
 * Рендер карты объекта: узел (поля записи read-строкой) + все его дети,
 * рекурсивно вглубь до листьев. Обход дерева уже сделан record_tree()
 * (core.php) — здесь ТОЛЬКО вывод готовой структуры в HTML, ни обхода,
 * ни SQL (в отличие от легаси db_tree, мешавшего всё в одной функции).
 *
 * Перенесено из index.php 2026-07-11 (view-слой, STATE.md п.8а): рендер
 * представления живёт в render.php, входная точка его только вызывает.
 * Поведение идентично прежнему render_object_map — чистый перенос.
 */
function render_object_tree(
    array $node, array $snapshot, mysqli $db_connection, int $depth = 0
): void {
    $task_table = $node['table'];
    $indent     = $depth * 24;

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

    foreach ($node['children'] as $block) {
        echo '<div style="margin-left:' . ($indent + 24) . 'px">';
        echo '<h4>' . render_escape($block['label']) . '</h4>';
        foreach ($block['nodes'] as $child_node) {
            render_object_tree($child_node, $snapshot, $db_connection, $depth + 1);
        }
        echo '<p><a href="?_table=' . rawurlencode($block['table'])
            . '&_action=new&_parent_table=' . rawurlencode($task_table)
            . "&_parent_id=$id\">+ добавить в «" . render_escape($block['label']) . '»</a></p>';
        echo '</div>';
    }
    echo '</div>';
}
