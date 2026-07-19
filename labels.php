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
 *
 * 2026-07-17 (STATE.md «Позже», дорожная карта единого входа, стадия 4):
 * require -> require_once, диспетчер обёрнут в labels_dispatch(),
 * весь HTML — в render.php (render_labels_directory()/
 * render_labels_editor()), guard «запущен напрямую или подключён» в
 * конце файла — тот же приём, что configurator.php получил стадией
 * раньше. labels_registry_id()/labels_save_label() переименованы в
 * model_label_registry_id()/model_label_save() — подписи часть
 * model/presentation-слоя, не отдельное приложение (Chat). Локальный
 * h() убран — дословный дубль render_escape() (тот же
 * htmlspecialchars($s, ENT_QUOTES, 'UTF-8')), внешних ссылок на h()
 * не было (проверено grep'ом), заменён на render_escape() везде.
 */

require_once 'config.php';
require_once 'db.php';
require_once 'core.php';
require_once 'helpers.php';
require_once 'render.php';

/** id строки реестра по адресу (kind, owner, element); null — адреса нет. */
function model_label_registry_id(PgSql\Connection $db, string $kind, ?string $owner, string $element): ?int
{
    if ($owner === null) {
        $sql = 'SELECT id FROM ' . MODEL_REGISTRY_TABLE
             . ' WHERE data_kind = ? AND data_owner IS NULL AND data_element = ? AND active = 1';
        $rows = db_select($db, $sql, 'ss', [$kind, $element]);
    } else {
        $sql = 'SELECT id FROM ' . MODEL_REGISTRY_TABLE
             . ' WHERE data_kind = ? AND data_owner = ? AND data_element = ? AND active = 1';
        $rows = db_select($db, $sql, 'sss', [$kind, $owner, $element]);
    }
    // Поведение при ошибке меняется явно: db_select() сводит ошибку к [],
    // поэтому функция возвращает null так же, как при отсутствии адреса.
    $row = $rows[0] ?? null;
    return $row ? (int) $row['id'] : null;
}

/** UPSERT подписи (1:1 с реестром). Пустые строки → NULL. */
function model_label_save(PgSql\Connection $db, int $registry_id, string $short, string $full, string $template): bool
{
    $short_v    = $short === '' ? null : $short;
    $full_v     = $full === '' ? null : $full;
    $template_v = trim($template) === '' ? null : trim($template);

    // 2026-07-16: Postgres — ON CONFLICT вместо ON DUPLICATE KEY UPDATE.
    // Конфликт-таргет — dep_model_registry, он же PRIMARY KEY таблицы
    // (решение 07-05 «Сейчас» п.1: 1:1 с реестром обеспечено схемой).
    $sql = 'INSERT INTO ' . MODEL_LABELS_TABLE . '
              (dep_model_registry, data_short, data_full, data_label_template)
            VALUES (?, ?, ?, ?)
            ON CONFLICT (dep_model_registry) DO UPDATE SET
              data_short = EXCLUDED.data_short,
              data_full  = EXCLUDED.data_full,
              data_label_template = EXCLUDED.data_label_template';
    $result = db_execute($db, $sql, 'isss', [$registry_id, $short_v, $full_v, $template_v]);
    return $result['ok'];
}

/**
 * Диспетчер редактора подписей — POST (PRG) + два шага показа.
 * 2026-07-17 (STATE.md «Позже», дорожная карта единого входа, стадия 4).
 */
function labels_dispatch(PgSql\Connection $db_connection): void
{
    // -----------------------------------------------------------------------
    // POST (PRG)
    // -----------------------------------------------------------------------

    $flash = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['_action'] ?? '');
        $table  = (string) ($_POST['table'] ?? '');

        if ($action === 'save_all') {
            $saved = 0;
            $errors = 0;

            $t_id = model_label_registry_id($db_connection, 'table', null, $table);
            if ($t_id !== null) {
                model_label_save(
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
                $f_id = model_label_registry_id($db_connection, 'field', $table, $element);
                if ($f_id === null) {
                    $errors++;
                    continue;
                }
                model_label_save(
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

        $back = $table !== '' ? '?_context=labels&table=' . rawurlencode($table) : '?_context=labels';
        if ($flash) {
            $back .= '&flash=' . rawurlencode($flash[0]) . '&msg=' . rawurlencode($flash[1]);
        }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . $back);
        exit;
    }

    if (isset($_GET['flash'])) {
        $flash = [(string) $_GET['flash'], (string) ($_GET['msg'] ?? '')];
    }

    // -----------------------------------------------------------------------
    // Живой слепок (§8, админ-режим — не кэш)
    // -----------------------------------------------------------------------

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

    echo render_admin_page_open('Подписи и словари', 'labels');
    if ($valid_selected) {
        echo '<p><a class="home-link" href="index.php?_context=labels">← К списку таблиц</a></p>';
    }
    echo render_admin_flash($flash !== null ? render_escape($flash[1]) : null, ($flash[0] ?? '') === 'ok');

    // -----------------------------------------------------------------------
    // Ступень 1 — выбор таблицы (три группы)
    // -----------------------------------------------------------------------
    if (!$valid_selected) {
        render_labels_directory($snapshot_view);
        render_admin_page_close();
        return;
    }

    // -----------------------------------------------------------------------
    // Ступень 2 — форма правки выбранной таблицы
    // -----------------------------------------------------------------------
    $t_schema = $structure['tables'][$selected];
    $t_labels = $presentation['labels']['table'][$selected] ?? [];
    $is_dict  = str_starts_with($selected, 'voc_') && isset($t_schema['fields']['data_name']);

    $dict_rows = [];
    if ($is_dict) {
        $snapshot  = snapshot_init($db_connection, config()['application']);
        $dict_rows = record_list($db_connection, $snapshot, $selected, 500);
    }

    render_labels_editor($selected, $t_schema, $t_labels, $presentation, $is_dict, $dict_rows);
    render_admin_page_close();
}

// 2026-07-17 (обновлено): теперь чистая библиотека — никакой логики
// диспетчера при прямом обращении, только редирект на верный адрес
// (Влад: «должны были быть просто библиотеками», переходная
// подстраховка сняла себя за ненадобностью — стадии 5-6 подтвердили,
// что index.php справляется сам).
if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    header('Location: index.php?_context=labels');
    exit;
}
