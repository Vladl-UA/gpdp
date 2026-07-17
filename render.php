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

// sync: 2026-07-11, view-слой п.8г + render_table_directory (общий каталог таблиц: конфигуратор и labels)

/**
 * Общий вид админ-интерфейсов (index.php, configurator.php) — один
 * источник CSS, не два независимых куска, которые разойдутся при
 * следующей правке. Не про сущности — про оболочку страницы, поэтому
 * живёт в render.php, а не дублируется в каждом файле-потребителе.
 *
 * Сами правила — в style.css (статика, кэшируется браузером отдельно
 * от HTML). Здесь только ссылка. Путь относительный: index.php,
 * configurator.php, labels.php — соседи style.css в одном каталоге,
 * второй адрес заводить незачем (07-14, оформление п.1).
 */
function render_admin_styles(): string
{
    // 2026-07-17: версия файла в самой ссылке (не просто "жёсткое
    // обновление, надеюсь поможет") — после нескольких правок style.css
    // без видимого эффекта на экране (сервер отдавал верный файл,
    // подтверждено curl'ом; дело было в кэше конкретно style.css у
    // браузера, не у HTML-страницы) единственный надёжный способ —
    // сделать правку физически другим URL. filemtime — не хардкод
    // версии, меняется сам с каждой правкой файла.
    $version = @filemtime(__DIR__ . '/style.css') ?: time();
    return '<link rel="stylesheet" href="style.css?v=' . $version . '">';
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

/** Значение для ячейки таблицы / просмотра. Список (многозначное поле,
 *  напр. links_) — в столбик, тот же приём, что render_record_table. */
function render_value(array $result): string
{
    $value = $result['value'] ?? '';
    if (is_array($value)) {
        return implode('<br>', array_map(static fn($v): string => render_escape((string) $v), $value));
    }
    return render_escape((string) $value);
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
        // Скрытое поле ПЕРЕД чекбоксом (тот же трюк, что везде в вебе):
        // непроверенный чекбокс браузер вообще не отправляет — без этой
        // страховки record_save() увидел бы отсутствие ключа как «поле
        // не трогали» (частичное обновление законно), а не как «снято».
        // Порядок важен: если чекбокс отмечен, браузер шлёт оба значения
        // одним именем, и «1» от чекбокса в теле запроса идёт ПОСЛЕ
        // скрытого «0» — выигрывает он.
        'checkbox' => '<input type="hidden" name="' . $name . '" value="0">'
        . '<input type="checkbox" name="' . $name . '" value="1"'
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

    // 2026-07-17: links_ — множественный выбор. Тот же трюк, что у
    // bul_'s чекбокса: скрытое поле ПЕРЕД <select multiple> — иначе
    // браузер при снятии ВСЕХ отметок не пришлёт ключ вовсе, и
    // record_save() увидел бы «поле не трогали» (законное частичное
    // обновление) вместо «выбор явно очищён». Пустая строка в
    // сентинеле отфильтровывается на стороне validate (links_handler).
    if (($result['widget'] ?? 'select') === 'select_multiple') {
        $selected_ids = is_array($current) ? $current : [];
        $options = '';
        foreach ($result['options'] ?? [] as $option) {
            $value    = render_escape((string) $option['value']);
            $label    = render_escape((string) $option['label']);
            $selected = in_array($option['value'], $selected_ids, true) ? ' selected' : '';
            $options .= "<option value=\"$value\"$selected>$label</option>";
        }
        return "<input type=\"hidden\" name=\"{$name}[]\" value=\"\">"
             . "<select name=\"{$name}[]\" multiple>$options</select>";
    }

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
/**
 * Компактная строка «поле: значение · поле: значение» вместо полной
 * таблицы (2026-07-17, план Chat, пп.2-3, по заказу Влада: «однострочные
 * дочерние сущности не должны быть полноценными таблицами» — буфер,
 * репер, компонент занимали заголовок+кнопку+шапку+строку ради трёх
 * значений). Применяется по числу колонок (RENDER_COMPACT_MAX_COLS),
 * не по имени таблицы — универсальный рендерер не знает имён таблиц.
 * Пустые значения пропускаются молча (не «Поле: —» через всю строку).
 */
const RENDER_COMPACT_MAX_COLS = 4;

function render_record_compact(array $view, array $opts = []): string
{
    $columns = $view['columns'] ?? [];
    $rows    = $view['rows'] ?? [];
    $html    = '';

    foreach ($rows as $row) {
        $id    = (int) $row['id'];
        $cells = $row['cells'] ?? [];
        $parts = [];
        foreach ($cells as $i => $value) {
            $text = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
            if ($text === '') {
                continue; // пусто — не загромождаем строку
            }
            $label   = (string) ($columns[$i]['label'] ?? '');
            $parts[] = '<b>' . render_escape($label) . ':</b> ' . render_escape($text);
        }

        $actions = '';
        if (isset($opts['edit_href'], $opts['delete_href'])) {
            $items = [
                ['label' => 'Править', 'href' => str_replace('{id}', (string) $id, $opts['edit_href'])],
            ];
            if (isset($opts['reparent_href'])) {
                $items[] = ['label' => 'Сменить родителя', 'href' => str_replace('{id}', (string) $id, $opts['reparent_href'])];
            }
            $items[]  = ['label' => 'Удалить', 'href' => str_replace('{id}', (string) $id, $opts['delete_href']), 'danger' => true];
            $actions  = render_actions_dropdown($items);
        }

        $html .= '<div class="rec-line"><span>' . implode(' · ', $parts) . '</span>' . $actions . '</div>';
    }

    return $html;
}

/** Компактно (строкой) или таблицей — решает число колонок, не имя
 *  таблицы (универсальный рендерер таблиц по именам не различает). */
function render_record_auto(array $view, array $opts = []): string
{
    return count($view['columns'] ?? []) <= RENDER_COMPACT_MAX_COLS
        ? render_record_compact($view, $opts)
        : render_record_table($view, $opts);
}

function render_object_tree(array $node, int $depth = 0): void
{
    $task_table = $node['table'];
    $id         = $node['id'];
    // 2026-07-17 (план Chat п.1, по заказу Влада): глубина больше НЕ
    // кодируется нарастающим отступом (24/48/72/96px — «вертикальные
    // линии выглядят как случайная проводка», разъезжались с
    // содержимым). Один шаг отступа на всё, дальше иерархию несут
    // карточки/заголовки, не пиксели.
    $indent = min($depth, 1) * 24;

    echo '<div style="margin-left:' . $indent . 'px;padding-left:12px;margin-bottom:8px">';

    // Поля узла — готовая заготовка от record_tree (ядро), рендер только
    // укладывает. Действия (править/сменить родителя/удалить) — одной
    // ячейкой в конце ЭТОЙ ЖЕ строки. reparent — только у записей с
    // однозначной dep_ связью (флаг уже вычислен в core.php, render его
    // не пересчитывает, STATE.md «Сейчас» п.5); корень дерева (well)
    // флаг false, опция в меню не появляется сама.
    $opts = [
        'edit_href'   => "?_table=$task_table&_action=edit&_id={id}",
        'delete_href' => "?_table=$task_table&_action=delete&_id={id}",
    ];
    if ($node['reparentable'] ?? false) {
        $opts['reparent_href'] = "?_table=$task_table&_action=reparent&_id={id}";
    }
    echo render_record_auto($node['view'], $opts);

    foreach ($node['children'] as $block) {
        render_object_tree_block($block, $task_table, $id, $depth);
    }
    echo '</div>';
}

/**
 * Один раздел детей. Развилка по факту: есть ли у сиблингов СВОИХ
 * детей.
 *
 * Нет своих детей (лист) → все строки сведены в одну компактную/
 * табличную укладку разом (не таблица на запись — «Компонент состава
 * репера»/«Химреагент испытания», найдено живьём 2026-07-17).
 * Есть свои дети → каждый сиблинг остаётся самостоятельной карточкой
 * (строка + сразу под ней её собственные дети) — связь строки со
 * своими детьми важнее компактности.
 */
function render_object_tree_block(array $block, string $parent_table, int $parent_id, int $depth): void
{
    // 2026-07-17: тот же принцип, что в render_object_tree — один шаг
    // отступа на раздел, не нарастающий по глубине.
    $indent      = min($depth + 1, 1) * 24;
    $child_table = $block['table'];

    echo '<div style="margin-left:' . $indent . 'px">';
    echo '<div class="block-head"><h4>' . render_escape($block['label']) . '</h4>'
       . '<a class="act" href="?_table=' . rawurlencode($child_table)
       . '&_action=new&_parent_table=' . rawurlencode($parent_table)
       . "&_parent_id=$parent_id\" title=\"добавить в «" . render_escape($block['label']) . '»">+</a></div>';

    $siblings = $block['nodes'];
    $has_grandchildren = false;
    foreach ($siblings as $sibling) {
        if ($sibling['children'] !== []) {
            $has_grandchildren = true;
            break;
        }
    }

    if ($siblings !== [] && !$has_grandchildren) {
        $merged_rows = [];
        foreach ($siblings as $sibling) {
            foreach ($sibling['view']['rows'] ?? [] as $row) {
                $merged_rows[] = $row;
            }
        }
        $merged_view         = $siblings[0]['view'];
        $merged_view['rows'] = $merged_rows;

        $opts = [
            'edit_href'   => "?_table=$child_table&_action=edit&_id={id}",
            'delete_href' => "?_table=$child_table&_action=delete&_id={id}",
        ];
        if ($siblings[0]['reparentable'] ?? false) {
            $opts['reparent_href'] = "?_table=$child_table&_action=reparent&_id={id}";
        }
        echo render_record_auto($merged_view, $opts);
    } else {
        foreach ($siblings as $sibling) {
            render_object_tree($sibling, $depth + 1);
        }
    }
    echo '</div>';
}

/**
 * Укладчик формы reparent (view-слой): заготовка record_reparent_view()
 * → HTML. Ничего не вычисляет — подписи и список кандидатов уже готовы.
 * Один select с текущим родителем, выбранным по умолчанию.
 */
function render_reparent_form(array $view): string
{
    $html = '<p>Запись: <b>' . render_escape($view['label']) . '</b></p>'
          . '<p>Текущий родитель: <b>' . render_escape($view['current_parent_label']) . '</b></p>';

    $html .= '<form method="post">';
    foreach ($view['hidden'] as $name => $value) {
        $html .= '<input type="hidden" name="' . render_escape((string) $name)
               . '" value="' . render_escape((string) $value) . '">';
    }
    $html .= '<p><label>Новый родитель: <select name="_new_parent_id">';
    foreach ($view['candidates'] as $parent_id => $label) {
        $selected = $parent_id === $view['current_parent_id'] ? ' selected' : '';
        $html .= '<option value="' . (int) $parent_id . '"' . $selected . '>'
               . render_escape($label) . '</option>';
    }
    $html .= '</select></label></p>';
    $html .= '<p><input type="submit" value="Сменить родителя"></p></form>';

    return $html;
}

/**
 * Укладчик представления «список» (view-слой): готовая заготовка
 * record_table_view() → HTML-таблица. Ничего не вычисляет — значения
 * ячеек уже готовы (voc разрешён в подпись сборщиком). Рендер только
 * раскладывает по клеткам + оборачивает управляющими ссылками.
 *
 * $opts: 'first_link' => шаблон href для первой ячейки (крупная область
 * клика на карточку); 'actions' => шаблон href-пары ~/× (править/удалить).
 * Плейсхолдер {id} подставляется. Пусто → без ссылок (нейтральная
 * таблица, напр. внутри карты объекта).
 */
/**
 * Меню действий в одной ячейке, открывается по наведению — CSS,
 * без JS (2026-07-17, замечание Влада: список должен раскрываться
 * при наведении, обычный <select> так не умеет физически). Пустой
 * список действий — пустая строка, ячейку не рисуем вовсе.
 * $actions: [['label'=>..., 'href'=>..., 'danger'=>bool], ...]
 */
function render_actions_dropdown(array $actions): string
{
    if ($actions === []) {
        return '';
    }
    $items = '';
    foreach ($actions as $action) {
        $cls = ($action['danger'] ?? false) ? ' class="act-danger"' : '';
        $items .= '<a href="' . render_escape((string) $action['href']) . '"' . $cls . '>'
                 . render_escape((string) $action['label']) . '</a>';
    }
    return '<span class="act-menu"><button type="button" class="act-trigger">···</button>'
         . '<span class="act-menu-list">' . $items . '</span></span>';
}

function render_record_table(array $view, array $opts = []): string
{
    $columns = $view['columns'] ?? [];
    $rows    = $view['rows'] ?? [];

    $has_actions = isset($opts['edit_href'], $opts['delete_href']);

    // 2026-07-17 (подсказка Chat): два режима ширины, не один — узкие
    // таблицы (АКЦ, пласт) сжимаются по содержимому, широкие (много
    // колонок — интервал, лабораторные испытания, скважина) занимают
    // всю ширину, сжимать их так же было бы нечитаемо. Порог считает
    // колонку действий тоже (реальная ширина строки, не только полей).
    $col_count   = count($columns) + ($has_actions ? 1 : 0);
    $table_class = $col_count <= 7 ? 'data-list data-list-fit' : 'data-list data-list-wide';

    $html = '<table class="' . $table_class . '"><tr>';
    foreach ($columns as $column) {
        $html .= '<th>' . render_escape((string) $column['label']) . '</th>';
    }
    if ($has_actions) {
        $html .= '<th class="col-actions"></th>';
    }
    $html .= '</tr>';

    foreach ($rows as $row) {
        $id    = (int) $row['id'];
        $cells = $row['cells'] ?? [];
        $html .= '<tr>';
        foreach ($cells as $i => $value) {
            // 2026-07-17: список (многозначное поле, напр. links_) —
            // в столбик внутри той же ячейки, не растягивая таблицу
            // вширь; каждый выбранный вариант словаря — своя строка.
            // Пустой список — пустая ячейка, не "Array"/мусор.
            $escaped = is_array($value)
                ? implode('<br>', array_map(static fn($v): string => render_escape((string) $v), $value))
                : render_escape((string) $value);
            if ($i === 0 && isset($opts['card_href'])) {
                $href = render_escape(str_replace('{id}', (string) $id, $opts['card_href']));
                $html .= '<td><a href="' . $href . '">' . $escaped . '</a></td>';
            } else {
                $html .= '<td>' . $escaped . '</td>';
            }
        }
        if ($has_actions) {
            // 2026-07-17: одна ячейка, выпадающий список — не несколько
            // иконок врозь (замечание Влада). reparent_href — опционален,
            // появляется в опциях только когда передан (карточка объекта
            // знает про конкретную запись; список — нет, как и раньше).
            $actions = [
                ['label' => 'Править', 'href' => str_replace('{id}', (string) $id, $opts['edit_href'])],
            ];
            if (isset($opts['reparent_href'])) {
                $actions[] = ['label' => 'Сменить родителя', 'href' => str_replace('{id}', (string) $id, $opts['reparent_href'])];
            }
            $actions[] = ['label' => 'Удалить', 'href' => str_replace('{id}', (string) $id, $opts['delete_href']), 'danger' => true];
            $html .= '<td class="col-actions">' . render_actions_dropdown($actions) . '</td>';
        }
        $html .= '</tr>';
    }

    $html .= '</table>';
    return $html;
}

/**
 * Укладчик представления «форма» (view-слой): заготовка record_form_view()
 * → HTML-форма. Ничего не вычисляет — элементы уже готовы (виджеты
 * input/choice собраны сборщиком в ядре). Рендер оборачивает в <form>,
 * раскладывает скрытые технические поля и элементы, добавляет кнопку.
 *
 * $submit — подпись кнопки. Скрытые поля из $view['hidden'] — как есть
 * (ключ→значение), это технические имена (_action/_table/_id/_parent_*),
 * не подписи.
 */
function render_record_form(array $view, string $submit = 'Сохранить'): string
{
    $html = '<form method="post">';
    foreach ($view['hidden'] ?? [] as $name => $value) {
        $html .= '<input type="hidden" name="' . render_escape((string) $name)
               . '" value="' . render_escape((string) $value) . '">';
    }
    foreach ($view['elements'] ?? [] as $element) {
        $html .= render_form_element($element);
    }
    $html .= '<p><input type="submit" value="' . render_escape($submit) . '"></p></form>';
    return $html;
}

/**
 * Укладчик «карточка таблицы» для конфигуратора (view-слой п.8г):
 * заготовка schema_view() → HTML. Подпись + действия + поля в столбик.
 * Ничего не вычисляет. Действия приходят готовыми ссылками ($actions:
 * массив [подпись=>href], {t} подставляется на имя таблицы). $badge —
 * готовый HTML значка или ''. $depth — сдвиг для дерева зависимых.
 *
 * Поля раскладываются колонками по $per_col строк (по умолчанию 5):
 * заполнили колонку — следующая рядом. Пустая таблица — без блока полей.
 */
function render_schema_card(array $view, array $actions = [], string $badge = '', int $depth = 0, bool $bare = false): string
{
    $table  = $view['table'];
    $indent = $depth * 24;

    // $bare — карточка внутри семейного блока (.schema-family): своей
    // рамки/ширины не рисует, их даёт блок; иначе одиночная карточка 70%.
    $cls   = $bare ? 'schema-card schema-card-bare' : 'schema-card';
    $html  = '<div class="' . $cls . '" style="margin-left:' . $indent . 'px">';

    // Шапка: подпись таблицы + значок + действия — залитая полоса.
    $links = '';
    foreach ($actions as $label => $href_tpl) {
        $href = render_escape(str_replace('{t}', rawurlencode($table), $href_tpl));
        $links .= ' <a class="act" href="' . $href . '">' . render_escape((string) $label) . '</a>';
    }
    $html .= '<div class="schema-head"><span class="schema-title"><strong>'
           . render_escape((string) $view['label']) . '</strong> '
           . '<span class="badge">' . render_escape($table) . '</span>' . $badge . '</span>'
           . '<span class="schema-acts">' . $links . '</span></div>';

    // Поля в столбик по $per_col — таблица-раскладка (не данные).
    $fields  = $view['fields'] ?? [];
    $per_col = 5;
    if ($fields !== []) {
        $rows = [];
        $cols = array_chunk($fields, $per_col);
        $height = 0;
        foreach ($cols as $c) {
            $height = max($height, count($c));
        }
        $html .= '<table class="schema-fields">';
        for ($r = 0; $r < $height; $r++) {
            $html .= '<tr>';
            foreach ($cols as $col) {
                $cell = $col[$r]['label'] ?? '';
                $html .= '<td>' . render_escape((string) $cell) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';
    }

    $html .= '</div>';
    return $html;
}

/**
 * Общий каталог таблиц по группам (view-слой): четыре раздела —
 * Главные (каждая с деревом зависимых в семейном блоке), Отчёты,
 * Служебные, Системные. Одна раскладка на конфигуратор и labels
 * («сверху одно и то же»); под капотом расходятся действия карточек.
 *
 * $snapshot — снапшот-для-показа (structure + presentation + relations).
 * $actions  — ссылки карточки [подпись => href-шаблон], {t} → имя
 *             таблицы; у конфигуратора одни, у labels другие.
 * $opts:
 *   'dict_badge'    => bool — рисовать значок «словарь» (множество имён
 *                      voc-полей передаётся как 'referenced');
 *   'referenced'    => массив имён таблиц-словарей (для значка);
 *   'show_system'   => bool — показывать раздел системных (labels может
 *                      не показывать; по умолчанию true);
 *   'reports_note'  => текст пустой полки отчётов.
 */
function render_table_directory(array $snapshot, array $actions, array $opts = []): void
{
    $referenced   = $opts['referenced'] ?? [];
    $show_system  = $opts['show_system'] ?? true;
    $reports_note = $opts['reports_note'] ?? 'Отчёты не созданы.';

    $tables = $snapshot['structure']['tables'] ?? [];

    $by_group = ['main' => [], 'dict' => [], 'system' => []];
    foreach ($tables as $t_name => $t_schema) {
        $g = table_group($t_name, $t_schema);
        if ($g === 'dependent') {
            continue; // покажется под своей главной деревом
        }
        $by_group[$g][] = $t_name;
    }
    foreach ($by_group as &$names) {
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    }
    unset($names);

    $badge_of = static function (string $t) use ($referenced): string {
        return isset($referenced[$t]) ? '<span class="badge">словарь</span>' : '';
    };

    // Рекурсивный вывод главной с зависимыми внутри семейного блока.
    $tree = static function (string $t_name, int $depth) use (
        &$tree, $snapshot, $actions, $badge_of
    ): void {
        echo render_schema_card(schema_view($snapshot, $t_name), $actions, $badge_of($t_name), $depth, true);
        foreach ($snapshot['model']['relations'][$t_name] ?? [] as $relation) {
            $tree($relation['child'], $depth + 1);
        }
    };

    echo '<h3>Главные таблицы</h3>';
    if ($by_group['main'] === []) {
        echo '<p><em>нет</em></p>';
    } else {
        foreach ($by_group['main'] as $t_name) {
            echo '<div class="schema-family">';
            $tree($t_name, 0);
            echo '</div>';
        }
    }

    echo '<h3>Отчёты</h3><p><em>' . render_escape($reports_note) . '</em></p>';

    echo '<h3>Служебные таблицы</h3>';
    if ($by_group['dict'] === []) {
        echo '<p><em>нет</em></p>';
    } else {
        foreach ($by_group['dict'] as $t_name) {
            echo render_schema_card(schema_view($snapshot, $t_name), $actions, $badge_of($t_name), 0);
        }
    }

    if ($show_system) {
        echo '<h3>Системные таблицы</h3>';
        if ($by_group['system'] === []) {
            echo '<p><em>нет</em></p>';
        } else {
            foreach ($by_group['system'] as $t_name) {
                echo render_schema_card(schema_view($snapshot, $t_name), $actions, '', 0);
            }
        }
    }
}

/**
 * Укладчик диагностики структуры (view-слой): результат model_diagnose()
 * → HTML раздела «Состояние модели». На языке модели, не SQL (журнал
 * 07-11): человек, забывший SQL, чинит структуру, не вспоминая SQL.
 * Кнопки починки — формы POST в конфигуратор (действие + адрес); сам
 * обработчик в configurator.php. $action_url — куда слать (обычно '').
 *
 * У каждого расхождения — обе кнопки, где уместно (взять под управление /
 * удалить): система показывает, судит человек (Мир А, журнал 07-11).
 */
function render_model_diagnosis(array $diag): string
{
    if ($diag['clean'] ?? false) {
        return '<div class="flash flash-ok">Структура и реестр согласованы. Расхождений нет.</div>';
    }

    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $html = '';

    // Кнопка починки: скрытая форма POST с действием и адресом.
    // $confirm — текст подтверждения для необратимых (удаление данных).
    $btn = static function (string $action, array $fields, string $label, string $cls = 'act', string $confirm = '') use ($h): string {
        $inputs = '<input type="hidden" name="_action" value="' . $h($action) . '">';
        foreach ($fields as $k => $v) {
            $inputs .= '<input type="hidden" name="' . $h((string) $k) . '" value="' . $h((string) $v) . '">';
        }
        $onsub = $confirm !== '' ? ' onsubmit="return confirm(\'' . $h($confirm) . '\')"' : '';
        return '<form method="post" style="display:inline;margin:0"' . $onsub . '>' . $inputs
             . '<button type="submit" class="' . $h($cls) . '">' . $h($label) . '</button></form> ';
    };

    // --- поля-сироты: в БД есть, реестр не знает ------------------------
    if (($diag['orphan_fields'] ?? []) !== []) {
        $html .= '<h3>Поля вне управления</h3>';
        $html .= '<p><em>Поле есть в таблице, но система про него не знает — '
               . 'подпись и поведение к нему не привязать, пока не взято под управление.</em></p>';
        $html .= '<table class="data-list"><tr><th>таблица</th><th>поле</th><th>тип</th><th>действие</th></tr>';
        foreach ($diag['orphan_fields'] as $of) {
            $addr = ['table' => $of['table'], 'field' => $of['field'], 'entity' => $of['entity']];
            $html .= '<tr><td>' . $h($of['table']) . '</td>'
                   . '<td><code>' . $h($of['field']) . '</code></td>'
                   . '<td>' . $h($of['entity']) . '</td><td>'
                   . $btn('reg_adopt_field', $addr, 'взять под управление')
                   . $btn('reg_drop_column', $addr, 'удалить поле из БД', 'act act-danger',
                          'Удалить поле ' . $of['table'] . '.' . $of['field'] . ' из базы? Данные в этом столбце будут потеряны.')
                   . '</td></tr>';
        }
        $html .= '</table>';
    }

    // --- таблицы-сироты -------------------------------------------------
    if (($diag['orphan_tables'] ?? []) !== []) {
        $html .= '<h3>Таблицы вне управления</h3>';
        $html .= '<p><em>Таблица есть в базе, но система про неё не знает.</em></p>';
        $html .= '<table class="data-list"><tr><th>таблица</th><th>действие</th></tr>';
        foreach ($diag['orphan_tables'] as $t) {
            $html .= '<tr><td>' . $h($t) . '</td><td>'
                   . $btn('reg_adopt_table', ['table' => $t], 'взять под управление')
                   . '</td></tr>';
        }
        $html .= '</table>';
    }

    // --- записи-призраки: реестр помнит исчезнувшее ---------------------
    if (($diag['ghost_registry'] ?? []) !== []) {
        $html .= '<h3>Записи о несуществующем</h3>';
        $html .= '<p><em>Реестр помнит элемент, которого в базе уже нет — '
               . 'осталась от удаления в обход системы.</em></p>';
        $html .= '<table class="data-list"><tr><th>что</th><th>адрес</th><th>действие</th></tr>';
        foreach ($diag['ghost_registry'] as $g) {
            $addr = $g['kind'] === 'field'
                ? $h((string) $g['owner']) . '.' . $h($g['element'])
                : $h($g['element']);
            $kind = $g['kind'] === 'field' ? 'поле' : 'таблица';
            $html .= '<tr><td>' . $kind . '</td><td><code>' . $addr . '</code></td><td>'
                   . $btn('reg_drop_ghost', ['id' => $g['id']], 'убрать из реестра', 'act act-danger')
                   . '</td></tr>';
        }
        $html .= '</table>';
    }

    // --- дубли: один адрес зарегистрирован дважды ----------------------
    if (($diag['duplicates'] ?? []) !== []) {
        $html .= '<h3>Двойная регистрация</h3>';
        $html .= '<p><em>Один элемент записан в реестре несколько раз. '
               . 'Оставить нужно одну запись, лишние убрать.</em></p>';
        $html .= '<table class="data-list"><tr><th>что</th><th>адрес</th><th>записи (id)</th><th>действие</th></tr>';
        foreach ($diag['duplicates'] as $d) {
            $addr = $d['kind'] === 'field'
                ? $h((string) $d['owner']) . '.' . $h($d['element'])
                : $h($d['element']);
            $kind = $d['kind'] === 'field' ? 'поле' : 'таблица';
            // Оставляем наименьший id, лишние (остальные) — на удаление.
            $ids  = $d['ids'];
            sort($ids);
            $keep = array_shift($ids);
            $drop_buttons = '';
            foreach ($ids as $drop_id) {
                $drop_buttons .= $btn('reg_drop_dup', ['id' => $drop_id], "убрать #$drop_id", 'act act-danger');
            }
            $html .= '<tr><td>' . $kind . '</td><td><code>' . $addr . '</code></td>'
                   . '<td>оставить #' . (int) $keep . ', лишние: ' . $h(implode(', ', $ids)) . '</td>'
                   . '<td>' . $drop_buttons . '</td></tr>';
        }
        $html .= '</table>';
    }

    return $html;
}
