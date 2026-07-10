<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

// sync: 2026-07-10, STATE.md Волна 1 — редактор подписей (model_labels) и значений словарей (voc_*) через UI, снимает ручную правку адресной строкой/SQL

/**
 * GPDP / RNA — редактор подписей и словарей (Волна 1).
 *
 * Отдельный вход, тот же периметр, что у configurator.php (админ-режим,
 * §8 — живой слепок, не кэш; авторизация — известный будущий контур).
 * Назначение — снять ежедневную ручную правку двух вещей:
 *
 *   Раздел A «Подписи» — data_short / data_full / data_label_template
 *     в model_labels. Правка — UPDATE одной строки + refresh presentation
 *     (лёгкий, без DDL-lock, §17). Раньше делалось руками через адресную
 *     строку / SQL.
 *   Раздел B «Словари» — значения voc_*-таблиц (id + data_name). Правка —
 *     record_save / record_delete в конкретный voc_ + refresh. Раньше —
 *     ручной SQL.
 *
 * Ничего нового в ядре: страница — UI поверх уже существующих
 * record_save / record_delete / snapshot_refresh_presentation и живого
 * слепка snapshot_build_structure / _presentation / _registry.
 *
 * НЕ входит в Волну 1 (отдельными решениями, §15 каждое):
 *   - ALTER полей (добавить/удалить/переименовать колонку) — режим
 *     конфигуратора, DDL под lock; здесь только подписи, не структура;
 *   - порядок отображения полей — отдельная таблица весов к реестру
 *     (Волна 2), сейчас понятия порядка в системе нет.
 */

require 'config.php';
require 'core.php';
require 'helpers.php';
require 'render.php';

$cfg = config()['db'];
$db_connection = @mysqli_connect($cfg['host'], $cfg['user'], $cfg['password'], $cfg['name']);
if ($db_connection === false) {
    http_response_code(500);
    exit('Нет соединения с БД: ' . mysqli_connect_error());
}
mysqli_set_charset($db_connection, 'utf8mb4');

// --- (auth: контур не утверждён — тот же статус, что у configurator.php) ----

// ============================================================================
// Хелперы записи. Каждый: действие → refresh → результат для флеша.
// Живой слепок читается заново в каждом хелпере намеренно — правка одной
// подписи не должна тащить пересборку всего для вызывающего; refresh
// презентации сам перечитает model_labels целиком.
// ============================================================================

/**
 * Адрес строки реестра (dep_model_registry) по (kind, owner, element).
 * owner === null для kind='table'. Возвращает id или null, если адреса
 * в реестре нет (рассинхрон — сообщаем, не молча).
 */
function labels_registry_id(mysqli $db, string $kind, ?string $owner, string $element): ?int
{
    if ($owner === null) {
        $sql = 'SELECT id FROM `' . MODEL_REGISTRY_TABLE
             . '` WHERE data_kind = ? AND data_owner IS NULL AND data_element = ? AND active = 1';
        $stmt = mysqli_prepare($db, $sql);
        mysqli_stmt_bind_param($stmt, 'ss', $kind, $element);
    } else {
        $sql = 'SELECT id FROM `' . MODEL_REGISTRY_TABLE
             . '` WHERE data_kind = ? AND data_owner = ? AND data_element = ? AND active = 1';
        $stmt = mysqli_prepare($db, $sql);
        mysqli_stmt_bind_param($stmt, 'sss', $kind, $owner, $element);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    return $row ? (int) $row['id'] : null;
}

/**
 * UPDATE подписи (data_short/data_full/data_label_template) для строки
 * реестра. Пустая строка шаблона → NULL (маяк «нет составной подписи»
 * — сам факт непустого шаблона, §16.1). Пустые short/full допустимы:
 * потребитель падает на data_element/#id, это законно.
 * Строка labels обязана существовать — при создании таблицы/поля
 * configurator_create_table её вставляет; если нет (легаси-рассинхрон)
 * — INSERT, чтобы страница чинила и такие случаи.
 */
function labels_save_label(
    mysqli $db,
    int $registry_id,
    string $short,
    string $full,
    string $template
): bool {
    $short_v    = $short === '' ? null : $short;
    $full_v     = $full === '' ? null : $full;
    $template_v = trim($template) === '' ? null : trim($template);

    // UPSERT: строка labels — 1:1 с реестром (PK = dep_model_registry).
    $sql = 'INSERT INTO `' . MODEL_LABELS_TABLE . '`
              (dep_model_registry, data_short, data_full, data_label_template)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              data_short = VALUES(data_short),
              data_full  = VALUES(data_full),
              data_label_template = VALUES(data_label_template)';
    $stmt = mysqli_prepare($db, $sql);
    mysqli_stmt_bind_param($stmt, 'isss', $registry_id, $short_v, $full_v, $template_v);
    return mysqli_stmt_execute($stmt);
}

// ============================================================================
// Обработка POST (PRG — как весь write-цикл системы, §журнал).
// ============================================================================

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['_action'] ?? '');

    // --- A. Сохранить подпись поля или таблицы ------------------------------
    if ($action === 'save_label') {
        $kind    = (string) ($_POST['kind'] ?? '');
        $owner   = ($_POST['owner'] ?? '') === '' ? null : (string) $_POST['owner'];
        $element = (string) ($_POST['element'] ?? '');

        $registry_id = labels_registry_id($db_connection, $kind, $owner, $element);
        if ($registry_id === null) {
            $flash = ['err', "Адрес не найден в реестре: $kind/$owner/$element"];
        } else {
            $ok = labels_save_label(
                $db_connection,
                $registry_id,
                (string) ($_POST['data_short'] ?? ''),
                (string) ($_POST['data_full'] ?? ''),
                (string) ($_POST['data_label_template'] ?? '')
            );
            if ($ok && snapshot_refresh_presentation($db_connection)) {
                $addr  = $owner === null ? $element : "$owner.$element";
                $flash = ['ok', "Подпись сохранена: $addr"];
            } else {
                $flash = ['err', 'Не удалось сохранить подпись или пересобрать презентацию'];
            }
        }
    }

    // --- B. Добавить значение в словарь -------------------------------------
    elseif ($action === 'dict_add') {
        $table = (string) ($_POST['table'] ?? '');
        $name  = trim((string) ($_POST['data_name'] ?? ''));
        $snapshot = snapshot_init($db_connection, config()['application']);

        if ($name === '') {
            $flash = ['err', 'Пустое значение словаря не добавляется'];
        } else {
            $result = record_save($db_connection, $snapshot, $table, ['data_name' => $name]);
            if (($result['ok'] ?? false) && snapshot_refresh_presentation($db_connection)) {
                $flash = ['ok', "Добавлено в $table: $name"];
            } else {
                $flash = ['err', 'Не удалось добавить значение: '
                                 . implode('; ', $result['errors'] ?? ['неизвестно'])];
            }
        }
    }

    // --- C. Изменить значение словаря ---------------------------------------
    elseif ($action === 'dict_edit') {
        $table = (string) ($_POST['table'] ?? '');
        $id    = (int) ($_POST['id'] ?? 0);
        $name  = trim((string) ($_POST['data_name'] ?? ''));
        $snapshot = snapshot_init($db_connection, config()['application']);

        if ($name === '' || $id === 0) {
            $flash = ['err', 'Пустое значение или отсутствует id'];
        } else {
            $result = record_save($db_connection, $snapshot, $table, ['data_name' => $name], $id);
            if (($result['ok'] ?? false) && snapshot_refresh_presentation($db_connection)) {
                $flash = ['ok', "Изменено в $table (#$id): $name"];
            } else {
                $flash = ['err', 'Не удалось изменить значение: '
                                 . implode('; ', $result['errors'] ?? ['неизвестно'])];
            }
        }
    }

    // --- D. Удалить значение словаря ----------------------------------------
    elseif ($action === 'dict_delete') {
        $table = (string) ($_POST['table'] ?? '');
        $id    = (int) ($_POST['id'] ?? 0);
        $snapshot = snapshot_init($db_connection, config()['application']);

        $result = record_delete($db_connection, $snapshot, $table, $id);
        if (($result['ok'] ?? false) && snapshot_refresh_presentation($db_connection)) {
            $flash = ['ok', "Удалено из $table (#$id)"];
        } else {
            $flash = ['err', 'Не удалось удалить значение: '
                             . implode('; ', $result['errors'] ?? ['неизвестно'])];
        }
    }

    // PRG: перекладываем флеш в GET-параметр и редиректим на себя.
    $qs = $flash ? ('?flash=' . rawurlencode($flash[0]) . '&msg=' . rawurlencode($flash[1])) : '';
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . $qs);
    exit;
}

// Флеш после PRG.
if (isset($_GET['flash'])) {
    $flash = [(string) $_GET['flash'], (string) ($_GET['msg'] ?? '')];
}

// ============================================================================
// Живой слепок для отображения (§8, админ-режим — не кэш).
// ============================================================================

$structure    = snapshot_build_structure($db_connection);
$presentation = snapshot_build_presentation($db_connection);
$registry     = snapshot_build_registry($db_connection, $structure);

$SYS_PREFIX = defined('SYSTEM_TABLE_PREFIX') ? SYSTEM_TABLE_PREFIX : 'model_';

// Разбор таблиц на два раздела: словари voc_* и всё остальное (предметные).
$dict_tables    = [];  // voc_* с data_name
$subject_tables = [];  // предметные (для раздела подписей)

foreach ($structure['tables'] as $t_name => $t_schema) {
    if (str_starts_with($t_name, $SYS_PREFIX)) {
        continue; // служебные model_* не показываем
    }
    if (str_starts_with($t_name, 'voc_') && isset($t_schema['fields']['data_name'])) {
        $dict_tables[$t_name] = $t_schema;
    }
    $subject_tables[$t_name] = $t_schema; // словари тоже правят подписи — оставляем в обоих
}

// ============================================================================
// Вывод.
// ============================================================================

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

echo '<!doctype html><html><head><meta charset="utf-8"><title>Подписи и словари</title>';
echo render_admin_styles();
echo '</head><body>';
echo '<p><a class="home-link" href="index.php">← Домой</a> · '
   . '<a class="home-link" href="configurator.php">Конфигуратор</a></p>';
echo '<h1>Подписи и словари</h1>';

if ($flash !== null) {
    $cls = $flash[0] === 'ok' ? 'flash-ok' : 'flash-err';
    echo '<div class="flash ' . $cls . '">' . h($flash[1]) . '</div>';
}

// ----------------------------------------------------------------------------
// РАЗДЕЛ B — Словари (первым: чаще всего правят именно значения).
// ----------------------------------------------------------------------------
echo '<h2>Словари</h2>';
echo '<p><em>Значения справочников. Изменение подписи самого словаря — '
   . 'в разделе «Подписи» ниже.</em></p>';

if ($dict_tables === []) {
    echo '<p>Словарей (<code>voc_*</code>) пока нет.</p>';
}

$snapshot_for_list = snapshot_init($db_connection, config()['application']);

foreach ($dict_tables as $t_name => $t_schema) {
    $t_full = (string) ($presentation['labels']['table'][$t_name]['data_full'] ?? $t_name);
    echo '<fieldset><legend><strong>' . h($t_full) . '</strong> '
       . '<span class="badge">' . h($t_name) . '</span></legend>';

    $rows = record_list($db_connection, $snapshot_for_list, $t_name, 500);
    if ($rows === []) {
        echo '<p><em>пусто</em></p>';
    } else {
        echo '<table><tr><th>id</th><th>значение</th><th></th></tr>';
        foreach ($rows as $row) {
            $rid  = (int) $row['id'];
            $name = (string) ($row['data_name'] ?? '');
            echo '<tr><td>' . $rid . '</td>';
            // Инлайн-форма правки значения.
            echo '<td><form method="post" style="display:flex;gap:6px;margin:0">'
               . '<input type="hidden" name="_action" value="dict_edit">'
               . '<input type="hidden" name="table" value="' . h($t_name) . '">'
               . '<input type="hidden" name="id" value="' . $rid . '">'
               . '<input type="text" name="data_name" value="' . h($name) . '" style="flex:1">'
               . '<button type="submit" class="act" title="сохранить">✓</button>'
               . '</form></td>';
            // Удаление отдельной формой (не вложить form в form).
            echo '<td><form method="post" style="margin:0" '
               . 'onsubmit="return confirm(\'Удалить «' . h($name) . '»?\')">'
               . '<input type="hidden" name="_action" value="dict_delete">'
               . '<input type="hidden" name="table" value="' . h($t_name) . '">'
               . '<input type="hidden" name="id" value="' . $rid . '">'
               . '<button type="submit" class="act act-danger" title="удалить">×</button>'
               . '</form></td></tr>';
        }
        echo '</table>';
    }

    // Форма добавления нового значения.
    echo '<form method="post" style="display:flex;gap:6px;margin-top:6px">'
       . '<input type="hidden" name="_action" value="dict_add">'
       . '<input type="hidden" name="table" value="' . h($t_name) . '">'
       . '<input type="text" name="data_name" placeholder="новое значение" style="flex:1">'
       . '<button type="submit" class="act" title="добавить">+ добавить</button>'
       . '</form>';
    echo '</fieldset>';
}

// ----------------------------------------------------------------------------
// РАЗДЕЛ A — Подписи (таблицы и их поля).
// ----------------------------------------------------------------------------
echo '<h2>Подписи</h2>';
echo '<p><em>Короткая (шапки таблиц), полная (формы) и шаблон составной '
   . 'подписи объекта — например <code>{voc_area} №{data_number}</code>. '
   . 'Пустой шаблон = составной подписи нет.</em></p>';

foreach ($subject_tables as $t_name => $t_schema) {
    $t_labels   = $presentation['labels']['table'][$t_name] ?? [];
    $t_short    = (string) ($t_labels['data_short'] ?? '');
    $t_full     = (string) ($t_labels['data_full'] ?? '');
    $t_template = (string) ($t_labels['data_label_template'] ?? '');

    echo '<fieldset><legend><strong>' . h($t_full !== '' ? $t_full : $t_name) . '</strong> '
       . '<span class="badge">' . h($t_name) . '</span></legend>';

    // Подпись самой таблицы (+ шаблон объекта — только для таблиц).
    echo '<form method="post" style="margin-bottom:10px">'
       . '<input type="hidden" name="_action" value="save_label">'
       . '<input type="hidden" name="kind" value="table">'
       . '<input type="hidden" name="owner" value="">'
       . '<input type="hidden" name="element" value="' . h($t_name) . '">'
       . '<div class="field-row"><span style="min-width:120px">таблица:</span>'
       . '<input type="text" name="data_short" value="' . h($t_short) . '" placeholder="кратко">'
       . '<input type="text" name="data_full" value="' . h($t_full) . '" placeholder="полностью">'
       . '</div>'
       . '<div class="field-row"><span style="min-width:120px">шаблон объекта:</span>'
       . '<input type="text" name="data_label_template" value="' . h($t_template) . '" '
       . 'placeholder="{voc_area} №{data_number}" style="flex:1">'
       . '<button type="submit" class="act" title="сохранить">✓</button></div>'
       . '</form>';

    // Подписи полей таблицы.
    echo '<table><tr><th>поле</th><th>кратко</th><th>полностью</th><th></th></tr>';
    foreach ($t_schema['fields'] as $f_name => $f_schema) {
        if (($f_schema['kind'] ?? '') === 'structural') {
            continue; // id / dep_ / rel_main — структурные, подпись не редактируют
        }
        $f_labels = $presentation['labels']['field'][$t_name][$f_name] ?? [];
        $f_short  = (string) ($f_labels['data_short'] ?? '');
        $f_full   = (string) ($f_labels['data_full'] ?? '');

        echo '<tr><td><code>' . h($f_name) . '</code></td>';
        echo '<td colspan="3"><form method="post" style="display:flex;gap:6px;margin:0">'
           . '<input type="hidden" name="_action" value="save_label">'
           . '<input type="hidden" name="kind" value="field">'
           . '<input type="hidden" name="owner" value="' . h($t_name) . '">'
           . '<input type="hidden" name="element" value="' . h($f_name) . '">'
           . '<input type="text" name="data_short" value="' . h($f_short) . '" placeholder="кратко" style="flex:1">'
           . '<input type="text" name="data_full" value="' . h($f_full) . '" placeholder="полностью" style="flex:2">'
           . '<button type="submit" class="act" title="сохранить">✓</button>'
           . '</form></td></tr>';
    }
    echo '</table>';
    echo '</fieldset>';
}

echo '</body></html>';
mysqli_close($db_connection);
