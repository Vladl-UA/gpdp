<?php
declare(strict_types=1);

/**
 * Временный прибор: раскладывает snapshot_refresh_presentation() на шаги
 * и показывает, какой именно возвращает false. После диагностики — удалить.
 * Запуск: php diag_refresh.php (тем же пользователем, что и смоук).
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

require 'config.php';
require 'core.php';
require 'helpers.php';
require 'render.php';

$cfg = config()['db'];
$db_connection = mysqli_connect($cfg['host'], $cfg['user'], $cfg['password'], $cfg['name']);
if ($db_connection === false) {
    exit("Нет соединения с БД\n");
}
mysqli_set_charset($db_connection, 'utf8mb4');

echo 'PHP ' . PHP_VERSION . ', пользователь: ' . get_current_user()
    . ', opcache.enable_cli=' . var_export(ini_get('opcache.enable_cli'), true) . "\n\n";

echo "1. lock:                 ";
var_dump(snapshot_lock_read());

echo "2. snapshot_load:        ";
$snapshot = snapshot_load();
var_dump(is_array($snapshot));

echo "3. build_presentation:   ";
$presentation = snapshot_build_presentation($db_connection);
var_dump(is_array($presentation));
if (is_array($presentation)) {
    echo '   ключи: ' . implode(', ', array_keys($presentation)) . "\n";
}

if (!is_array($snapshot)) {
    exit("Дальше идти не с чем: load дал не-массив.\n");
}
$snapshot['presentation'] = $presentation;
$snapshot['generated_at'] = date('Y-m-d H:i:s');

echo "4. validate до записи:   ";
var_dump(snapshot_validate($snapshot));

$file = config()['paths']['snapshot'];
$tmp  = $file . '.tmp';
$content = "<?php\nreturn " . var_export($snapshot, true) . ";\n";
echo '   длина содержимого: ' . strlen($content) . " байт\n";

echo "5. запись tmp:           ";
$written = file_put_contents($tmp, $content, LOCK_EX);
var_dump($written);

echo "6. include tmp:          ";
try {
    $check = include $tmp;   // БЕЗ @ — хотим увидеть ошибку, если она есть
    var_dump(is_array($check));
} catch (\Throwable $e) {
    echo 'ИСКЛЮЧЕНИЕ: ' . $e->getMessage() . "\n";
    $check = null;
}

if (is_array($check)) {
    echo "7. validate после чтения: ";
    var_dump(snapshot_validate($check));
}

echo "8. rename:               ";
var_dump(@rename($tmp, $file));

echo "\nИтоговый владелец файла:\n";
system('ls -la ' . escapeshellarg(dirname($file)));
