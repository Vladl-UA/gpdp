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
/**
 * Системное меню — три контура (data/configurator/labels), источник —
 * REQUEST_CONTEXTS (core.php), не отдельный список: контекст запроса
 * и пункт этого меню — буквально одно и то же (Влад, 2026-07-18: «на
 * то он и контекст»). Оформление сейчас — просто строка ссылок
 * (.sys-menu), с прицелом на будущий выпадающий список — см. комментарий
 * в style.css у .sys-menu, класс менять не придётся, когда дойдёт
 * очередь до самого вида.
 *
 * НЕ источник для будущего меню уровня представления (отчёты/
 * представления) — тот список растёт из записей модели, отдельный
 * механизм, задел под него сейчас сознательно не делается (STATE.md
 * «Позже» — не проектировать раньше реального повода).
 */
function render_context_menu(string $current): string
{
    $items = '';
    foreach (REQUEST_CONTEXTS as $key => $info) {
        $label = render_escape($info['icon'] . ' ' . $info['label']);
        $items .= $key === $current
            ? '<span class="menu-current">' . $label . '</span>'
            : '<a class="menu-item" href="' . render_escape($info['href']) . '">' . $label . '</a>';
    }
    return '<nav class="sys-menu">' . $items . '</nav>';
}

/**
 * $current_context — один из ключей REQUEST_CONTEXTS ('data'/
 * 'configurator'/'labels'), не свободная строка меню (было
 * $nav_html — каждый вызывающий сам собирал ссылки, отсюда была
 * асимметрия 2026-07-17: конфигуратор ссылался на подписи, подписи —
 * нет; здесь такое структурно невозможно, меню одно на всех).
 * Постраничные хлебные крошки («← К таблицам» и подобное) — забота
 * вызывающего, отдельным echo после этой функции, не смешивать с
 * системным меню (разная природа — переключение контура vs навигация
 * внутри одного).
 */
function render_admin_page_open(string $title, string $current_context, string $extra_head = ''): string
{
    return '<!doctype html><html><head><meta charset="utf-8"><title>'
         . render_escape($title) . '</title>'
         . render_admin_styles()
         . $extra_head
         . '</head><body>'
         . render_context_menu($current_context);
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

/**
 * Список вариантов → HTML `<option>`. Единственное место в проекте, где
 * рождается этот тег (§3, §12). 2026-07-19: до этого конфигуратор
 * собирал `<option>` сам, двенадцатью местами, и передавал в рендер уже
 * свёрстанную строку — тег рождался вне своего слоя. Здесь тег строится
 * из СПИСКА, как это и делал обычный путь данных.
 *
 * $items — список ['value' => .., 'label' => ..]; «— выберите … —»
 * передаётся первым элементом с пустым value, отдельного параметра под
 * placeholder не нужно. $current — скаляр (одиночный выбор) или массив
 * (множественный, links_): правило отметки разное, форма вызова одна.
 */
function render_options(array $items, mixed $current = null): string
{
    $html = '';
    foreach ($items as $item) {
        $value    = render_escape((string) $item['value']);
        $label    = render_escape((string) $item['label']);
        $selected = is_array($current)
            ? in_array($item['value'], $current, true)
            : ($current !== null && $item['value'] === $current);
        $html .= '<option value="' . $value . '"' . ($selected ? ' selected' : '') . '>' . $label . '</option>';
    }
    return $html;
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
        $options      = render_options($result['options'] ?? [], $selected_ids);
        return "<input type=\"hidden\" name=\"{$name}[]\" value=\"\">"
             . "<select name=\"{$name}[]\" multiple>$options</select>";
    }

    $options = render_options($result['options'] ?? [], $current);

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

    echo '<div class="rel-wrap" style="margin-left:' . $indent . 'px">';
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
/**
 * Стадия 3 дорожной карты единого входа (STATE.md «Позже», 2026-07-17):
 * HTML конфигуратора переезжает сюда действие за действием, начиная с
 * маленьких и безопасных — configurator.php не должен ничего echo'ить
 * сам (§3, «HTML рождается только в render.php»), логика (DDL,
 * валидация, живое чтение структуры) остаётся в configurator.php.
 */

function render_configurator_new_table(
    array $type_items,
    array $parent_items,
    array $link_target_items,
    array $dict_items,
    string $dict_labels_json,
    string $view_source_bul_fields_json
): void {
    $type_options        = render_options($type_items);
    $parent_options      = render_options($parent_items);
    $link_target_options = render_options($link_target_items);
    $dict_options        = render_options($dict_items);

    echo <<<HTML
    <h2>Новая таблица</h2>
    <form method="post" action="?_context=configurator&_action=create_table" id="create-form">

      <p>
        <label><input type="radio" name="table_kind" value="plain" checked onchange="onKindChange()">
          Обычная таблица</label>
        &nbsp;&nbsp;
        <label><input type="radio" name="table_kind" value="voc_simple" onchange="onKindChange()">
          Словарь (простой, уровень 0)</label>
        &nbsp;&nbsp;
        <label><input type="radio" name="table_kind" value="dependent" onchange="onKindChange()">
          Зависимая таблица</label>
        &nbsp;&nbsp;
        <label><input type="radio" name="table_kind" value="view_filtered" onchange="onKindChange()">
          Словарь-представление (фильтр по параметру)</label>
      </p>

      <div id="dependent-parent" style="display:none">
        <p>Родитель: <select name="parent_table">$parent_options</select>
           <small>(колонка <code>dep_&lt;родитель&gt;</code> создастся сама)</small></p>
        <p><label><input type="checkbox" name="add_rel_main" value="1" checked>
           привязать к корневому досье (<code>rel_main</code>)</label>
           <small>— принадлежность центральной записи, не то же, что родитель</small></p>
      </div>

      <div id="view-source-filter" style="display:none">
        <p>Источник: <select id="view-source-select" name="view_source" onchange="onViewSourceChange()">$link_target_options</select></p>
        <p>Фильтр (булево поле источника = «да»): <select name="view_filter_column" id="view-filter-column"></select></p>
      </div>

      <div id="plain-name">
        <p>Имя таблицы: <input type="text" name="table_name" pattern="[a-z][a-z0-9_]*"></p>
      </div>
      <div id="dict-name" style="display:none">
        <p>Имя словаря: <code>voc_</code><input type="text" name="dict_name" pattern="[a-z][a-z0-9_]*">
           <small>(префикс voc_ подставится сам)</small></p>
      </div>

      <p>Полная подпись: <input type="text" name="table_full" required>
         Короткая подпись: <input type="text" name="table_short" required></p>

      <div id="fields-section">
        <h3>Поля</h3>
        <div id="fields"></div>
        <button type="button" onclick="addField()">+ добавить поле</button>
      </div>
      <div id="dict-fields-note" style="display:none">
        <p><em>Поля словаря создаются автоматически: id + data_name. Больше ничего не нужно —
        как только словарю потребуются доп. атрибуты, это уже не словарь, а полноценная таблица
        (создавайте режимом "Обычная таблица", словарь будет адресовать её отдельным решением).</em></p>
      </div>

      <p><input type="submit" value="Создать"></p>
    </form>
    <p><a href="?_context=configurator&_action=list">Отмена</a></p>

    <template id="field-template">
      <div class="field-row">
        <select name="fields[__I__][entity]" onchange="onFieldTypeChange(this)">
          <option value="" selected disabled>— тип поля —</option>$type_options</select>

        <input type="text" class="f-name" name="fields[__I__][name]" placeholder="именная часть (без префикса)">
        <select class="f-voc-pick" name="fields[__I__][voc_pick]" style="display:none" onchange="onVocPick(this)">$dict_options</select>
        <select class="f-link-target" name="fields[__I__][link_target]" style="display:none">$link_target_options</select>

        <input type="text" class="f-full"  name="fields[__I__][full]"  placeholder="полная подпись">
        <input type="text" class="f-short" name="fields[__I__][short]" placeholder="короткая подпись">
        <button type="button" onclick="this.closest('.field-row').remove()" title="убрать поле">×</button>
      </div>
    </template>

    <script>
    const dictLabels = $dict_labels_json;
    const viewSourceBulFields = $view_source_bul_fields_json;
    let fieldCount = 0;

    function addField() {
      const tpl = document.getElementById('field-template').innerHTML.replaceAll('__I__', fieldCount++);
      const div = document.createElement('div');
      div.innerHTML = tpl;
      const row = div.firstElementChild;
      document.getElementById('fields').appendChild(row);
      // Сразу предлагаем список типов, не второй клик отдельно на select.
      const select = row.querySelector('select[name*="[entity]"]');
      select.focus();
      try { select.showPicker?.(); } catch { /* браузер не поддерживает — focus() уже сделан */ }
    }

    function onFieldTypeChange(select) {
      const row  = select.closest('.field-row');
      const name = row.querySelector('.f-name');
      const voc  = row.querySelector('.f-voc-pick');
      const link = row.querySelector('.f-link-target');
      const short = row.querySelector('.f-short');
      const full  = row.querySelector('.f-full');

      // voc_: имя выбирается из существующих словарей, не печатается
      // (§16, уровень 0). link_: имя СВОБОДНОЕ (как обычное поле) — а
      // цель выбирается отдельно (журнал 07-12: имя и адрес — разные
      // вещи, обе видны сразу, никакого авто-заполнения подписи от
      // цели — семантика поля («любимый цвет») не совпадает с
      // подписью цели («Цвет»)).
      voc.style.display  = select.value === 'voc'  ? '' : 'none';
      link.style.display = (select.value === 'link' || select.value === 'links') ? '' : 'none';
      name.style.display = select.value === 'voc'  ? 'none' : '';
      short.style.display = full.style.display = '';
    }

    function onVocPick(select) {
      const row = select.closest('.field-row');
      const info = dictLabels[select.value];
      if (info) {
        row.querySelector('.f-short').value = info.short;
        row.querySelector('.f-full').value  = info.full;
      }
    }

    function onKindChange() {
      const kind   = document.querySelector('input[name=table_kind]:checked').value;
      const isDict = kind === 'voc_simple' || kind === 'view_filtered';
      const isView = kind === 'view_filtered';
      document.getElementById('plain-name').style.display        = isDict ? 'none' : 'block';
      document.getElementById('dict-name').style.display         = isDict ? 'block' : 'none';
      document.getElementById('fields-section').style.display    = isDict ? 'none' : 'block';
      document.getElementById('dict-fields-note').style.display  = isDict && !isView ? 'block' : 'none';
      document.getElementById('dependent-parent').style.display  = kind === 'dependent' ? 'block' : 'none';
      document.getElementById('view-source-filter').style.display = isView ? 'block' : 'none';
      if (isView) {
        onViewSourceChange(); // сразу наполнить список фильтров под уже выбранный (или первый) источник
      }
    }

    // 2026-07-17: при смене источника — список её bul_-полей, не общий
    // список по всем таблицам (фильтр обязан быть колонкой ИМЕННО
    // выбранного источника, закрытый выбор, не произвольный текст).
    function onViewSourceChange() {
      const source = document.getElementById('view-source-select').value;
      const select = document.getElementById('view-filter-column');
      const fields = viewSourceBulFields[source] || [];
      select.innerHTML = fields.length === 0
        ? '<option value="">— в источнике нет булевых полей —</option>'
        : fields.map(f => '<option value="' + f.value + '">' + f.label + ' (' + f.value + ')</option>').join('');
    }

    addField(); // первое поле сразу видно (для режима "обычная таблица")
    </script>
    HTML;
}

/** Заголовок и плашка расхождений — общая часть шапки любого действия
 *  конфигуратора, не зависящая от того, какое именно действие открыто. */
function render_configurator_page_open(?string $flash, bool $flash_ok, array $diag, string $caction): void
{
    echo '<h1>Конфигуратор БД (v0)</h1>';
    echo render_admin_flash($flash, $flash_ok);
    if (!($diag['clean'] ?? false) && $caction !== 'diagnose') {
        $n = count($diag['orphan_fields']) + count($diag['orphan_tables'])
           + count($diag['ghost_registry']) + count($diag['duplicates']);
        echo '<div class="flash flash-err">В структуре модели расхождений: ' . $n
           . '. <a href="?_context=configurator&_action=diagnose">Состояние модели →</a></div>';
    }
}

function render_admin_page_close(): void
{
    echo '</body></html>';
}

function render_configurator_table_not_found(): void
{
    echo '<p>Таблица не найдена или системная.</p><p><a href="?_context=configurator&_action=list">← К таблицам</a></p>';
}

function render_configurator_edit_table(
    string $table,
    string $t_full,
    array $t_schema,
    array $live_presentation,
    array $data_counts,
    array $type_items,
    array $dict_items,
    array $link_target_items,
    string $dict_labels_json,
    string $formula_fields_json
): void {
    $type_options        = render_options($type_items);
    $dict_options        = render_options($dict_items);
    $link_target_options = render_options($link_target_items);

    $tbl_esc = render_escape($table);
    echo '<h2>Поля таблицы: ' . render_escape($t_full)
       . ' <span class="badge">' . $tbl_esc . '</span></h2>';
    echo '<p><a href="?_context=configurator&_action=list">← К таблицам</a></p>';

    // Текущие поля: структурные — серым без действий, entity — с удалением.
    echo '<table class="data-list"><tr><th>поле</th><th>тип</th><th>подпись</th><th>данных</th><th class="col-actions"></th></tr>';
    foreach ($t_schema['fields'] as $f_name => $f_schema) {
        $f_esc = render_escape($f_name);
        if (($f_schema['kind'] ?? '') !== 'entity_field') {
            echo '<tr><td><code style="color:#999">' . $f_esc . '</code></td>'
               . '<td colspan="4" style="color:#999">структурное — не редактируется</td></tr>';
            continue;
        }
        $f_labels = $live_presentation['labels']['field'][$table][$f_name] ?? [];
        $f_full   = render_escape((string) ($f_labels['data_full'] ?? ''));
        $entity   = render_escape((string) ($f_schema['entity'] ?? ''));
        $cnt      = (int) ($data_counts[$f_name] ?? 0);

        // Подтверждение зависит от данных: пустое поле — простое,
        // с данными — предупреждение + force. Сервер перепроверит.
        if ($cnt > 0) {
            $confirm = "В поле $f_name данные: $cnt знач. Удалить ВМЕСТЕ С ДАННЫМИ? Это необратимо.";
            $force   = '<input type="hidden" name="force" value="1">';
        } else {
            $confirm = "Удалить пустое поле $f_name?";
            $force   = '';
        }
        echo '<tr><td><code>' . $f_esc . '</code></td>'
           . '<td>' . $entity . '</td><td>' . $f_full . '</td>'
           . '<td>' . ($cnt > 0 ? $cnt : '—') . '</td>'
           . '<td class="col-actions"><form method="post" action="?_context=configurator&_action=alter_drop_field" style="margin:0" '
           . 'onsubmit="return confirm(\'' . render_escape($confirm) . '\')">'
           . '<input type="hidden" name="table" value="' . $tbl_esc . '">'
           . '<input type="hidden" name="field" value="' . $f_esc . '">' . $force
           . '<button type="submit" class="act act-danger" title="удалить поле">×</button>'
           . '</form></td></tr>';
    }
    echo '</table>';

    echo <<<HTML
    <h3>Добавить поле</h3>
    <form method="post" action="?_context=configurator&_action=alter_add_field">
      <input type="hidden" name="table" value="$tbl_esc">
      <div class="field-row">
        <select name="field[entity]" onchange="onFieldTypeChange(this)">
          <option value="" selected disabled>— тип поля —</option>$type_options</select>
        <input type="text" class="f-name" name="field[name]" placeholder="именная часть (без префикса)">
        <select class="f-voc-pick" name="field[voc_pick]" style="display:none" onchange="onVocPick(this)">$dict_options</select>
        <select class="f-link-target" name="field[link_target]" style="display:none">$link_target_options</select>
        <input type="text" class="f-full"  name="field[full]"  placeholder="полная подпись">
        <input type="text" class="f-short" name="field[short]" placeholder="короткая подпись">
        <button type="submit">+ добавить</button>
      </div>
      <div class="field-row f-formula-row" style="display:none">
        <input type="text" class="f-formula" name="field[formula]"
               placeholder="{поле} оператор {поле} — например {dec_volume_plan} - {dec_volume_fact}"
               style="flex:1">
      </div>
      <div class="f-formula-hint" style="display:none;margin:-4px 0 8px 0;font-size:.85em;color:#666">
        переменные (клик — вставить): <span class="f-formula-vars"></span>
      </div>
    </form>
    <script>
    const dictLabels    = $dict_labels_json;
    const formulaFields = $formula_fields_json;
    function onFieldTypeChange(select) {
      const row     = select.closest('.field-row');
      const name    = row.querySelector('.f-name');
      const voc     = row.querySelector('.f-voc-pick');
      const link    = row.querySelector('.f-link-target');
      const form    = row.parentElement;
      const fRow    = form.querySelector('.f-formula-row');
      const fHint   = form.querySelector('.f-formula-hint');
      voc.style.display  = select.value === 'voc'  ? '' : 'none';
      link.style.display = (select.value === 'link' || select.value === 'links') ? '' : 'none';
      name.style.display = select.value === 'voc'  ? 'none' : '';
      fRow.style.display  = select.value === 'calc' ? '' : 'none';
      fHint.style.display = select.value === 'calc' ? '' : 'none';
      if (select.value === 'calc' && fHint.querySelector('.f-formula-vars').children.length === 0) {
        const varsBox = fHint.querySelector('.f-formula-vars');
        Object.keys(formulaFields).forEach(fname => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'act';
          btn.textContent = formulaFields[fname] + ' ({' + fname + '})';
          btn.onclick = () => {
            const input = fRow.querySelector('.f-formula');
            input.value += '{' + fname + '}';
            input.focus();
          };
          varsBox.appendChild(btn);
          varsBox.appendChild(document.createTextNode(' '));
        });
      }
    }
    function onVocPick(select) {
      const row = select.closest('.field-row');
      const info = dictLabels[select.value];
      if (info) {
        row.querySelector('.f-short').value = info.short;
        row.querySelector('.f-full').value  = info.full;
      }
    }
    </script>
    HTML;
}

/**
 * Длительность в секундах → человеческая строка. Презентация, поэтому
 * живёт здесь: ядро хранит `started_at` числом, «висит 4 минуты» —
 * это уже способ сказать, а не факт.
 */
function render_duration(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . ' с';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . ' мин ' . ($seconds % 60) . ' с';
    }
    if ($seconds < 86400) {
        return intdiv($seconds, 3600) . ' ч ' . intdiv($seconds % 3600, 60) . ' мин';
    }

    return intdiv($seconds, 86400) . ' сут ' . intdiv($seconds % 86400, 3600) . ' ч';
}

/**
 * Блок состояния лока для страницы диагностики. Показывает КТО поставил,
 * ЗАЧЕМ и СКОЛЬКО висит — последнее раньше просто не читалось никем,
 * хотя `started_at` в файл пишется с самого начала.
 */
function render_lock_state(?array $lock): string
{
    if ($lock === null) {
        return '<h3>Блокировка схемы</h3><p>Лок не стоит — структурные операции доступны.</p>';
    }

    $source = render_escape((string) ($lock['source'] ?? '?'));
    $reason = render_escape((string) ($lock['reason'] ?? '?'));
    $age    = isset($lock['started_at'])
        ? render_duration(max(0, time() - (int) $lock['started_at']))
        : 'неизвестно (в файле нет метки времени)';

    return '<h3>Блокировка схемы</h3>'
        . '<p>Схема заблокирована. Источник: <b>' . $source . '</b>. Причина: ' . $reason . '.'
        . ' Держится: <b>' . render_escape($age) . '</b>.</p>'
        . '<p>Если операция давно закончилась, а лок остался — это осиротевший файл '
        . 'после аварийного завершения. Снятие пересоберёт и проверит модель прежде, '
        . 'чем удалить файл; если модель не в порядке, лок останется на месте.</p>'
        . '<form method="post" action="?_context=configurator&_action=release_lock">'
        . '<button type="submit">Снять лок с проверкой модели</button></form>';
}

function render_configurator_diagnose(array $diag): void
{
    echo '<h2>Состояние модели</h2>';
    echo '<p><a href="?_context=configurator&_action=list">← К таблицам</a></p>';
    echo render_model_diagnosis($diag);
    echo render_lock_state(snapshot_lock_read());
}

function render_configurator_delete_confirm(string $table): void
{
    $table = render_escape($table);
    echo <<<HTML
    <h2>Удаление таблицы "$table"</h2>
    <p>Действие необратимо: физическая таблица и все её записи будут уничтожены.</p>
    <form method="post" action="?_context=configurator&_action=delete_table">
      <input type="hidden" name="table" value="$table">
      <div id="stage1">
        <button type="button" onclick="reveal('stage2')">Я понимаю, что это необратимо</button>
      </div>
      <div id="stage2" style="display:none">
        <button type="button" onclick="reveal('stage3')">Да, удалить таблицу "$table"</button>
      </div>
      <div id="stage3" style="display:none">
        <button type="submit" style="color:red;font-weight:bold">ПОДТВЕРДИТЬ УДАЛЕНИЕ ОКОНЧАТЕЛЬНО</button>
      </div>
    </form>
    <p><a href="?_context=configurator&_action=list">Отмена</a></p>
    <script>
    function reveal(id) { document.getElementById(id).style.display = 'block'; }
    </script>
    HTML;
}

/** Каталог таблиц конфигуратора — обёртка над уже общей
 *  render_table_directory() (та же функция, что и на labels). */
function render_configurator_directory(array $snapshot, array $referenced_as_dict): void
{
    echo '<h2>Таблицы</h2><p><a href="?_context=configurator&_action=new_table">+ новая таблица</a></p>';
    render_table_directory($snapshot, [
        'содержимое'    => 'index.php?_context=data&_table={t}&_action=view',
        'редактировать' => '?_context=configurator&_action=edit&table={t}',
        'удалить'       => '?_context=configurator&_action=delete_confirm&table={t}',
    ], ['referenced' => $referenced_as_dict]);
}

/**
 * Стадия 4 дорожной карты единого входа (STATE.md «Позже», 2026-07-17):
 * HTML редактора подписей — тем же принципом, что configurator.php
 * стадией раньше. Логика (живое чтение структуры, POST/PRG) остаётся
 * в labels.php, здесь только вывод.
 */
function render_labels_directory(array $snapshot_view): void
{
    echo '<h1>Подписи и словари</h1>';
    echo '<p><em>Выберите таблицу — правка подписей её полей; у словарей '
       . 'также значения.</em></p>';

    // Тот же каталог таблиц, что в конфигураторе — «сверху одно и то
    // же». Под капотом действие одно: выбрать таблицу для правки
    // подписей. Системные (model_) не правятся здесь.
    render_table_directory($snapshot_view, [
        'править' => '?_context=labels&table={t}',
    ], ['show_system' => false, 'reports_note' => 'Отчёты не созданы.']);
}

/**
 * Форма правки подписей выбранной таблицы + (для словарей) правка
 * значений. $dict_rows — уже прочитанные record_list() строки (пусто,
 * если не словарь) — данные читает labels.php, здесь только укладка.
 */
function render_labels_editor(
    string $selected,
    array $t_schema,
    array $t_labels,
    array $presentation,
    bool $is_dict,
    array $dict_rows
): void {
    $t_full_lbl = (string) ($t_labels['data_full'] ?? '');

    echo '<h1>' . render_escape($t_full_lbl !== '' ? $t_full_lbl : $selected)
       . ' <span class="badge">' . render_escape($selected) . '</span></h1>';

    echo '<form method="post" class="edit-form">';
    echo '<input type="hidden" name="_action" value="save_all">';
    echo '<input type="hidden" name="table" value="' . render_escape($selected) . '">';

    echo '<fieldset><legend>Подпись таблицы</legend>';
    echo '<div class="field-row"><span style="min-width:110px">кратко:</span>'
       . '<input type="text" name="t_short" value="' . render_escape((string) ($t_labels['data_short'] ?? '')) . '" style="flex:1"></div>';
    echo '<div class="field-row"><span style="min-width:110px">полностью:</span>'
       . '<input type="text" name="t_full" value="' . render_escape($t_full_lbl) . '" style="flex:1"></div>';
    echo '<div class="field-row"><span style="min-width:110px">шаблон объекта:</span>'
       . '<input type="text" name="t_template" value="' . render_escape((string) ($t_labels['data_label_template'] ?? '')) . '" style="flex:1"></div>';
    echo '</fieldset>';

    echo '<fieldset><legend>Подписи полей</legend>';
    echo '<table class="data-list"><tr><th>поле</th><th>кратко</th><th>полностью</th></tr>';
    foreach ($t_schema['fields'] as $f_name => $f_schema) {
        if (($f_schema['kind'] ?? '') === 'structural') {
            continue;
        }
        $f_labels = $presentation['labels']['field'][$selected][$f_name] ?? [];
        $key = render_escape($f_name);
        echo '<tr><td><code>' . $key . '</code></td>'
           . '<td><input type="text" name="f_short[' . $key . ']" value="'
           . render_escape((string) ($f_labels['data_short'] ?? '')) . '" style="width:95%"></td>'
           . '<td><input type="text" name="f_full[' . $key . ']" value="'
           . render_escape((string) ($f_labels['data_full'] ?? '')) . '" style="width:95%"></td></tr>';
    }
    echo '</table></fieldset>';

    echo '<button type="submit" class="save-all">Сохранить всё</button>';
    echo '</form>';

    if ($is_dict) {
        echo '<h2>Значения</h2><div class="edit-form">';

        if ($dict_rows !== []) {
            echo '<table class="data-list"><tr><th>id</th><th>значение</th><th></th></tr>';
            foreach ($dict_rows as $row) {
                $rid  = (int) $row['id'];
                $name = (string) ($row['data_name'] ?? '');
                echo '<tr><td>' . $rid . '</td>'
                   . '<td><form method="post" style="display:flex;gap:6px;margin:0">'
                   . '<input type="hidden" name="_action" value="dict_edit">'
                   . '<input type="hidden" name="table" value="' . render_escape($selected) . '">'
                   . '<input type="hidden" name="id" value="' . $rid . '">'
                   . '<input type="text" name="data_name" value="' . render_escape($name) . '" style="flex:1">'
                   . '<button type="submit" class="act" title="сохранить">✓</button></form></td>'
                   . '<td><form method="post" style="margin:0" onsubmit="return confirm(\'Удалить значение?\')">'
                   . '<input type="hidden" name="_action" value="dict_delete">'
                   . '<input type="hidden" name="table" value="' . render_escape($selected) . '">'
                   . '<input type="hidden" name="id" value="' . $rid . '">'
                   . '<button type="submit" class="act act-danger" title="удалить">×</button></form></td></tr>';
            }
            echo '</table>';
        } else {
            echo '<p><em>пусто</em></p>';
        }

        echo '<form method="post" style="display:flex;gap:6px;margin-top:6px">'
           . '<input type="hidden" name="_action" value="dict_add">'
           . '<input type="hidden" name="table" value="' . render_escape($selected) . '">'
           . '<input type="text" name="data_name" placeholder="новое значение" style="flex:1">'
           . '<button type="submit" class="act" title="добавить">+ добавить</button></form>';
        echo '</div>';
    }
}

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
