<?php
declare(strict_types=1);

/**
 * GPDP / RNA — конфигурация окружения.
 *
 * Единственный файл, который правится при переносе на другой сервер.
 * Доступ из кода — только через config(): без глобальных переменных.
 */

function config(): array
{
    static $config = null;

    if ($config === null) {
        $config = [

            // 2026-07-16: переезд на Postgres (STATE.md «Сейчас» п.9) —
            // MySQL из конфигурации убран целиком, не второй вариант.
            'db' => [
                'host'     => '127.0.0.1',
                'port'     => 5432,
                'user'     => 'gpdp',
                'password' => '111',
                'name'     => 'gpdp_test',
            ],

            // Сгенерированное состояние живёт отдельно от кода (state/):
            // snapshot и lock не коммитятся, кроме .gitkeep.
            'paths' => [
                'snapshot' => __DIR__ . '/state/snapshot.php',
                'lock'     => __DIR__ . '/state/schema.lock',
                'entities' => __DIR__ . '/entities.php',
            ],

            // Режим снапшота (STATE.md «Сейчас» п.6): 'cached' — боевой
            // путь (файл на диске, атомарная запись, holodный старт);
            // 'live' — dev: та же snapshot_build(), без записи, живёт
            // один рендер в памяти. Дефолт 'cached' — прод НИКОГДА не
            // получает 'live' молча, только явной правкой этого файла.
            'snapshot' => [
                'mode' => 'cached',
            ],

            // Параметры приложения — часть МОДЕЛИ, а не ядра.
            // root_table временно живёт здесь до появления конфигуратора,
            // затем переедет в служебную таблицу модели (ARCHITECTURE.md).
            'application' => [
                'root_table' => 'main',
            ],
        ];
    }

    return $config;
}
