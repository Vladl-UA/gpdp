<?php
declare(strict_types=1);

/**
 * Форсирует ПОЛНУЮ пересборку снапшота (структура + презентация +
 * словари + модель) — не только presentation, как diag_refresh.php.
 * Нужен, когда меняется КОД резолвера (не сама БД): файл снапшота на
 * диске не знает об этом сам по себе, обновляется только как побочный
 * эффект структурных действий конфигуратора (журнал 2026-07-13).
 *
 * Обычно НЕ запускается вручную — вызывается git-хуком post-merge
 * (.githooks/post-merge), когда после pull находит флаг
 * SNAPSHOT_REBUILD_REQUIRED. Ручной запуск (`php tools_rebuild_snapshot.php`)
 * годится как аварийный запасной путь, если хук не настроен/не сработал.
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

require 'config.php';
require 'db.php';
require 'core.php';
require 'helpers.php';
require 'render.php';

try {
    $db_connection = db_connect(config()['db']);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Нет соединения с БД: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Собираю снапшот заново...\n";
$snapshot = snapshot_build($db_connection, config()['application']);

if ($snapshot === null) {
    fwrite(STDERR, 'ПРОВАЛ: ' . (snapshot_last_error() ?? '?') . "\n");
    exit(1);
}

echo "Собран. Сохраняю...\n";
if (!snapshot_save($snapshot)) {
    fwrite(STDERR, "ПРОВАЛ записи.\n");
    exit(1);
}

echo "Готово — снапшот пересобран и записан.\n";
exit(0);
