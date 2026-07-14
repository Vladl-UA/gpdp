<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

// sync: 2026-07-10, Волна 1 — редактор подписей/словарей: выбор таблицы (группы) → форма правки (50%, чередование строк, «Сохранить всё»)

/**
 * GPDP / RNA — редактор подписей и словарей (Волна 1).
 *
 * Ступень 1 (без ?table): список таблиц тремя группами — Главные (нет
 * dep_/rel_main), Зависимые (есть), Словари (voc_*).
 * Ступень 2 (?table=X): форма правки подписей одной таблицы, одна
 * кнопка «Сохранить всё» (один POST); для словаря — плюс значения.
 *
 * Подписи: UPSERT в model_labels + snapshot_refresh_presentation
 * (лёгкий refresh, §17). Значения словарей: record_save/record_delete.
 * Ничего нового в ядре. Вне охвата (Волна 2, §15): ALTER полей,
 * порядок отображения.
 */

require 'config.php';
require 'db.php';
require 'core.php';
require 'helpers.php';
require 'render.php';

$db_connection = admin_db_connect();

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** id строки реестра по адресу (kind, owner, element); null — адреса нет. */
function labels_registry_id(mysqli $db, string $kind, ?string $owner, string $element): ?int
{
    if ($owner === null) {
        $sql = 'SELECT id FROM `' . MODEL_REGISTRY_TABLE
             . '` WHERE data_kind = ? AND data_owner IS NULL AND data_element = ? AND active = 1';
        $rows = db_select($db, $sql, 'ss', [$kind, $element]);
    } else {
        $sql = 'SELECT id FROM `' . MODEL_REGISTRY_TABLE
             . '` WHERE data_kind = ? AND data_owner = ? AND data_element = ? AND active = 1';
        $rows = db_select($db, $sql, 'sss', [$kind, $owner, $element]);
    }
    // Поведение при ошибке меняется явно: db_select() сводит ошибку к [],
    // поэтому функция возвращает null так же, как при отсутствии адреса.
    $row = $rows[0] ?? null;
    return $row ? (int) $row['id'] : null;
}

/** UPSERT подписи (1:1 с реестром). Пустые строки → NULL. */
function labels_save_label(mysqli $db, int $registry_id, string $short, string $full, string $template): bool
{
    $short_v    = $short === '' ? null : $short;
    $full_v     = $full === '' ? null : $full;
    $template_v = trim($template) === '' ? null : trim($template);

    $sql = 'INSERT INTO `' . MODEL_LABELS_TABLE . '`
              (dep_model_registry, data_short, data_full, data_label_template)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              data_short = VALUES(data_short),
              data_full  = VALUES(data_full),
              data_label_template = VALUES(data_label_template)';
    $result = db_execute($db, $sql, 'isss', [$registry_id, $short_v, $full_v, $template_v]);
    return $result['ok'];
}

// ---------------------------------------------------------------------------
// POST (PRG)
// ---------------------------------------------------------------------------

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['_action'] ?? '');
    $table  = (string) ($_POST['table'] ?? '');

    if ($action === 'save_all') {
        $saved = 0;
        $errors = 0;

        $t_id = labels_registry_id($db_connection, 'table', null, $table);
        if ($t_id !== null) {
            labels_save_label(
                $db_connection, $t_id,
                (string) ($_POST['t_short'] ?? ''),
                (string) ($_POST['t_full'] ?? ''),
                (string) ($_POST['t_template'] ?? '')
            ) ? $saved++ : $errors++;
        }

        $f_short = (array) ($_POST['f_short'] ?? []);
        $f_full  = (array) ($_POST['f_full'] ?? []);
        foreach ($f_short as $element => $short) {
            $element = (string) $element;
            $f_id = labels_registry_id($db_connection, 'field', $table, $element);
            if ($f_id === null) {
                $errors++;
                continue;
            }
            labels_save_label(
                $db_connection, $f_id,
                (string) $short,
                (string) ($f_full[$element] ?? ''),
                ''
            ) ? $saved++ : $errors++;
        }

        $flash = (snapshot_refresh_presentation($db_connection) && $errors === 0)
            ? ['ok', "Сохранено подписей: $saved"]
            : ['err', "Сохранено: $saved, с ошибками: $errors"];
    }

    elseif ($action === 'dict_add' || $action === 'dict_edit' || $action === 'dict_delete') {
        $snapshot = snapshot_init($db_connection, config()['application']);
        $id   = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['data_name'] ?? ''));

        if ($action === 'dict_delete') {
            $result = record_delete($db_connection, $snapshot, $table, $id);
            $msg = "Удалено (#$id)";
        } elseif ($action === 'dict_edit') {
            $result = ($name === '' || $id === 0)
                ? ['ok' => false, 'errors' => ['Пустое значение или нет id']]
                : record_save($db_connection, $snapshot, $table, ['data_name' => $name], $id);
            $msg = "Изменено (#$id): $name";
        } else {
            $result = ($name === '')
                ? ['ok' => false, 'errors' => ['Пустое значение']]
                : record_save($db_connection, $snapshot, $table, ['data_name' => $name]);
            $msg = "Добавлено: $name";
        }

        $flash = (($result['ok'] ?? false) && snapshot_refresh_presentation($db_connection))
            ? ['ok', $msg]
            : ['err', implode('; ', $result['errors'] ?? ['неизвестно'])];
    }

    $back = $table !== '' ? '?table=' . rawurlencode($table) : '?';
    if ($flash) {
        $back .= '&flash=' . rawurlencode($flash[0]) . '&msg=' . rawurlencode($flash[1]);
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . $back);
    exit;
}

if (isset($_GET['flash'])) {
    $flash = [(string) $_GET['flash'], (string) ($_GET['msg'] ?? '')];
}

// ---------------------------------------------------------------------------
// Живой слепок (§8, админ-режим — не кэш)
// ---------------------------------------------------------------------------

$structure    = snapshot_build_structure($db_connection);
$presentation = snapshot_build_presentation($db_connection);
$relations    = snapshot_build_relations(['tables' => $structure['tables']]);
$snapshot_view = [
    'structure'    => $structure,
    'presentation' => $presentation,
    'model'        => ['relations' => $relations['map']],
];

$SYS_PREFIX = defined('SYSTEM_TABLE_PREFIX') ? SYSTEM_TABLE_PREFIX : 'model_';

$selected = (string) ($_GET['table'] ?? '');
$valid_selected = $selected !== ''
    && isset($structure['tables'][$selected])
    && !str_starts_with($selected, $SYS_PREFIX);

$extra_head = '<style>
.edit-form{max-width:50%}
.edit-form tr:nth-child(even) td{background:#ececec}
.save-all{margin-top:12px;padding:6px 18px;background:#eef;border:1px solid #99c;border-radius:4px;cursor:pointer}
.save-all:hover{background:#dde}
</style>';
$nav = '<a class="home-link" href="index.php">← Домой</a>'
     . ($valid_selected ? ' · <a class="home-link" href="labels.php">← К списку таблиц</a>' : '');
echo render_admin_page_open('Подписи и словари', $nav, $extra_head);
echo render_admin_flash($flash !== null ? h($flash[1]) : null, ($flash[0] ?? '') === 'ok');

// ---------------------------------------------------------------------------
// Ступень 1 — выбор таблицы (три группы)
// ---------------------------------------------------------------------------
if (!$valid_selected) {
    echo '<h1>Подписи и словари</h1>';
    echo '<p><em>Выберите таблицу — правка подписей её полей; у словарей '
       . 'также значения.</em></p>';

    // Тот же каталог таблиц, что в конфигураторе (render.php) — «сверху
    // одно и то же». Под капотом действие одно: выбрать таблицу для
    // правки подписей. Системные (model_) не правятся здесь.
    render_table_directory($snapshot_view, [
        'править' => '?table={t}',
    ], ['show_system' => false, 'reports_note' => 'Отчёты не созданы.']);

    echo '</body></html>';
    mysqli_close($db_connection);
    exit;
}

// ---------------------------------------------------------------------------
// Ступень 2 — форма правки выбранной таблицы
// ---------------------------------------------------------------------------
$t_schema   = $structure['tables'][$selected];
$t_labels   = $presentation['labels']['table'][$selected] ?? [];
$is_dict    = str_starts_with($selected, 'voc_') && isset($t_schema['fields']['data_name']);
$t_full_lbl = (string) ($t_labels['data_full'] ?? '');

echo '<h1>' . h($t_full_lbl !== '' ? $t_full_lbl : $selected)
   . ' <span class="badge">' . h($selected) . '</span></h1>';

echo '<form method="post" class="edit-form">';
echo '<input type="hidden" name="_action" value="save_all">';
echo '<input type="hidden" name="table" value="' . h($selected) . '">';

echo '<fieldset><legend>Подпись таблицы</legend>';
echo '<div class="field-row"><span style="min-width:110px">кратко:</span>'
   . '<input type="text" name="t_short" value="' . h((string) ($t_labels['data_short'] ?? '')) . '" style="flex:1"></div>';
echo '<div class="field-row"><span style="min-width:110px">полностью:</span>'
   . '<input type="text" name="t_full" value="' . h($t_full_lbl) . '" style="flex:1"></div>';
echo '<div class="field-row"><span style="min-width:110px">шаблон объекта:</span>'
   . '<input type="text" name="t_template" value="' . h((string) ($t_labels['data_label_template'] ?? '')) . '" style="flex:1"></div>';
echo '</fieldset>';

echo '<fieldset><legend>Подписи полей</legend>';
echo '<table><tr><th>поле</th><th>кратко</th><th>полностью</th></tr>';
foreach ($t_schema['fields'] as $f_name => $f_schema) {
    if (($f_schema['kind'] ?? '') === 'structural') {
        continue;
    }
    $f_labels = $presentation['labels']['field'][$selected][$f_name] ?? [];
    $key = h($f_name);
    echo '<tr><td><code>' . $key . '</code></td>'
       . '<td><input type="text" name="f_short[' . $key . ']" value="'
       . h((string) ($f_labels['data_short'] ?? '')) . '" style="width:95%"></td>'
       . '<td><input type="text" name="f_full[' . $key . ']" value="'
       . h((string) ($f_labels['data_full'] ?? '')) . '" style="width:95%"></td></tr>';
}
echo '</table></fieldset>';

echo '<button type="submit" class="save-all">Сохранить всё</button>';
echo '</form>';

if ($is_dict) {
    echo '<h2>Значения</h2><div class="edit-form">';
    $snapshot = snapshot_init($db_connection, config()['application']);
    $rows = record_list($db_connection, $snapshot, $selected, 500);

    if ($rows !== []) {
        echo '<table><tr><th>id</th><th>значение</th><th></th></tr>';
        foreach ($rows as $row) {
            $rid  = (int) $row['id'];
            $name = (string) ($row['data_name'] ?? '');
            echo '<tr><td>' . $rid . '</td>'
               . '<td><form method="post" style="display:flex;gap:6px;margin:0">'
               . '<input type="hidden" name="_action" value="dict_edit">'
               . '<input type="hidden" name="table" value="' . h($selected) . '">'
               . '<input type="hidden" name="id" value="' . $rid . '">'
               . '<input type="text" name="data_name" value="' . h($name) . '" style="flex:1">'
               . '<button type="submit" class="act" title="сохранить">✓</button></form></td>'
               . '<td><form method="post" style="margin:0" onsubmit="return confirm(\'Удалить значение?\')">'
               . '<input type="hidden" name="_action" value="dict_delete">'
               . '<input type="hidden" name="table" value="' . h($selected) . '">'
               . '<input type="hidden" name="id" value="' . $rid . '">'
               . '<button type="submit" class="act act-danger" title="удалить">×</button></form></td></tr>';
        }
        echo '</table>';
    } else {
        echo '<p><em>пусто</em></p>';
    }

    echo '<form method="post" style="display:flex;gap:6px;margin-top:6px">'
       . '<input type="hidden" name="_action" value="dict_add">'
       . '<input type="hidden" name="table" value="' . h($selected) . '">'
       . '<input type="text" name="data_name" placeholder="новое значение" style="flex:1">'
       . '<button type="submit" class="act" title="добавить">+ добавить</button></form>';
    echo '</div>';
}

echo '</body></html>';
mysqli_close($db_connection);
