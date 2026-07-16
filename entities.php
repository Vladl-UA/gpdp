<?php
declare(strict_types=1);

/**
 * GPDP / RNA — библиотека функций-сущностей.
 *
 * Контракт файла (стандарт, проверяется на ревью, не в рантайме):
 *   1. Функция-паспорт: имя ent_<id>, ноль аргументов, возвращает массив.
 *   2. id в паспорте == имя функции без ent_.
 *   3. Обработчик: <id>_handler(array $data, string $mode): array.
 *      Один handler на несколько режимов — законно, решает паспорт.
 *   4. Handler возвращает структурированный массив, НИКОГДА не HTML —
 *      вывод собирает renderer (render.php).
 *   5. Handler НЕ пишет в БД: режим validate возвращает
 *      {valid, value, errors}, запись выполняет конвейер.
 *   6. Имена вызываемых функций происходят ТОЛЬКО из паспортов.
 *
 * Рабочие режимы: new / edit / read / validate (ARCHITECTURE.md §3).
 * Сущность появляется в системе тем, что её паспорт лежит здесь.
 */

// ============================================================
// data — простые данные. Необрабатываемый текст: имя человека,
// название химиката. Ввод-вывод как есть, валидация — trim.
// ============================================================
function ent_data(): array
{
    return [
        'id'    => 'data',
        'label' => 'Простые данные',

        'db' => [
            'kind'          => 'column',
            'default_type'  => 'varchar(255)',
            'allowed_types' => ['varchar', 'text', 'int', 'float'],
            'nullable'      => true,
        ],

        'create' => [
            'params' => ['name', 'label', 'length'],
        ],

        'handlers' => [
            'new'      => 'data_handler',
            'edit'     => 'data_handler',
            'read'     => 'data_handler',
            'validate' => 'data_handler',
        ],
    ];
}

function data_handler(array $data, string $mode): array
{
    $field = $data['field'];

    if ($mode === 'read') {
        return [
            'type'   => 'value',
            'name'   => $field['raw'],
            'value'  => (string) ($data['value'] ?? ''),
            'subscr' => $field['subscr'],
        ];
    }

    if ($mode === 'new' || $mode === 'edit') {
        return [
            'type'   => 'input',
            'widget' => 'text',
            'name'   => $field['raw'],
            'value'  => $mode === 'new' ? '' : (string) ($data['value'] ?? ''),
            'subscr' => $field['subscr'],
            'meta'   => [],
        ];
    }

    if ($mode === 'validate') {
        return [
            'valid'  => true,
            'value'  => trim((string) ($data['value'] ?? '')),
            'errors' => [],
        ];
    }

    return ['type' => 'error', 'errors' => ["Неподдерживаемый режим '$mode'"]];
}

// ============================================================
// voc — словарь/подстановка. В БД хранится id записи из таблицы-
// словаря, наружу отдаётся человекочитаемое data_name.
// Соглашение простого словаря: имя таблицы-словаря == имя поля
// (voc_mr в таблице main ссылается на таблицу voc_mr).
// Варианты выбора — через lookup_labels() (унификация 2026-07-13:
// один вход для любого словаря, простого и составного одинаково;
// request-level кэш внутри решает N+1).
// ============================================================
function ent_voc(): array
{
    return [
        'id'    => 'voc',
        'label' => 'Словарь / подстановка',

        'db' => [
            'kind'          => 'column_with_table', // поле + собственная таблица-словарь
            'default_type'  => 'int',
            'allowed_types' => ['int', 'smallint'], // mediumint не существует в Postgres (2026-07-16)
            'nullable'      => true,
        ],

        'create' => [
            'params' => ['name', 'label'],
        ],

        'handlers' => [
            'new'      => 'voc_handler',
            'edit'     => 'voc_handler',
            'read'     => 'voc_handler',
            'validate' => 'voc_handler',
        ],
    ];
}

function voc_handler(array $data, string $mode): array
{
    $field = $data['field'];
    // Словарная запись СКОМПИЛИРОВАНА сборкой снапшота (§16.1): склад,
    // адрес или проекция с планом сборки подписи — хендлер разницы
    // не видит и из имени ничего не выводит: lookup_labels исполняет
    // запись как есть. Доверенное: из пакета field_data, не из request;
    // неразрешимый адрес / кривой шаблон / цикл до рантайма не
    // доживают — сборка падает раньше (fail-fast в snapshot_build).
    $dict = $field['dict'];

    if ($mode === 'read') {
        $value   = (int) ($data['value'] ?? 0);
        $options = $value !== 0 ? lookup_labels($data['db'], $dict) : [];

        return [
            'type'      => 'value',
            'name'      => $field['raw'],
            'value'     => $options[$value] ?? '',
            'raw_value' => $value,
            'subscr'    => $field['subscr'],
        ];
    }

    if ($mode === 'new' || $mode === 'edit') {
        $current = $mode === 'new' ? 0 : (int) ($data['value'] ?? 0);
        $options = [['value' => 0, 'label' => '']]; // пустой выбор

        foreach (lookup_labels($data['db'], $dict) as $id => $label) {
            $options[] = ['value' => $id, 'label' => $label];
        }

        return [
            'type'    => 'choice',
            'widget'  => 'select',
            'name'    => $field['raw'],
            'value'   => $current,
            'options' => $options,
            'subscr'  => $field['subscr'],
        ];
    }

    if ($mode === 'validate') {
        $value = (int) ($data['value'] ?? 0);

        if ($value !== 0 && !isset(lookup_labels($data['db'], $dict)[$value])) {
            return [
                'valid'  => false,
                'value'  => null,
                'errors' => ['Выбранного значения нет в словаре'],
            ];
        }

        return ['valid' => true, 'value' => $value, 'errors' => []];
    }

    return ['type' => 'error', 'errors' => ["Неподдерживаемый режим '$mode'"]];
}

// ============================================================
// ltext — длинный текст без ограничения по смыслу (объяснительная
// записка и подобное). Отличие от data — не длина как параметр,
// а поведение: многострочный ввод — семантическая роль поля,
// не следствие физического типа колонки (см. решение STATE.md:
// виджет по db_type отклонён — нарушает §1, вторая ось поведения
// помимо имени). Ранее в этом черновике называлась note_ —
// переименована по явному выбору названия для проекта.
// ============================================================
function ent_ltext(): array
{
    return [
        'id'    => 'ltext',
        'label' => 'Длинный текст',

        'db' => [
            'kind'          => 'column',
            'default_type'  => 'text',
            'allowed_types' => ['text'], // единый TEXT в Postgres, нет вариантов по длине (2026-07-16)
            'nullable'      => true,
        ],

        'create' => [
            'params' => ['name', 'label'],
        ],

        'handlers' => [
            'new'      => 'ltext_handler',
            'edit'     => 'ltext_handler',
            'read'     => 'ltext_handler',
            'validate' => 'ltext_handler',
        ],
    ];
}

function ltext_handler(array $data, string $mode): array
{
    $field = $data['field'];

    if ($mode === 'read') {
        return [
            'type'   => 'value',
            'name'   => $field['raw'],
            'value'  => (string) ($data['value'] ?? ''),
            'subscr' => $field['subscr'],
        ];
    }

    if ($mode === 'new' || $mode === 'edit') {
        return [
            'type'   => 'input',
            'widget' => 'textarea',
            'name'   => $field['raw'],
            'value'  => $mode === 'new' ? '' : (string) ($data['value'] ?? ''),
            'subscr' => $field['subscr'],
            'meta'   => [],
        ];
    }

    if ($mode === 'validate') {
        return [
            'valid'  => true,
            'value'  => trim((string) ($data['value'] ?? '')),
            'errors' => [],
        ];
    }

    return ['type' => 'error', 'errors' => ["Неподдерживаемый режим '$mode'"]];
}

// ============================================================
// footnote — краткая пометка, до одного предложения. Легаси-
// наследие footnotes/footnote_ — по СМЫСЛУ роли, не по формату
// (ARCHITECTURE.md: legacy — археология смыслов, не контракт).
// Настоящее поведенческое отличие от data, не косметика: жёсткий
// потолок длины — часть контракта самой роли ("это пометка, не
// текст"), проверяется в validate, а не только ограничением
// колонки БД (тихое обрезание MySQL — не наш стиль). Потолок
// зашит константой, не вынесен в create-параметр: гибкость длины
// не входит в определение роли; понадобится другой потолок —
// это будет другая сущность, не параметр этой.
// ============================================================
const FOOTNOTE_MAX_LENGTH = 50;

function ent_footnote(): array
{
    return [
        'id'    => 'footnote',
        'label' => 'Краткая пометка',

        'db' => [
            'kind'          => 'column',
            'default_type'  => 'varchar(50)',
            'allowed_types' => ['varchar'],
            'nullable'      => true,
        ],

        'create' => [
            'params' => ['name', 'label'],
        ],

        'handlers' => [
            'new'      => 'footnote_handler',
            'edit'     => 'footnote_handler',
            'read'     => 'footnote_handler',
            'validate' => 'footnote_handler',
        ],
    ];
}

function footnote_handler(array $data, string $mode): array
{
    $field = $data['field'];

    if ($mode === 'read') {
        return [
            'type'   => 'value',
            'name'   => $field['raw'],
            'value'  => (string) ($data['value'] ?? ''),
            'subscr' => $field['subscr'],
        ];
    }

    if ($mode === 'new' || $mode === 'edit') {
        return [
            'type'   => 'input',
            'widget' => 'text',
            'name'   => $field['raw'],
            'value'  => $mode === 'new' ? '' : (string) ($data['value'] ?? ''),
            'subscr' => $field['subscr'],
            'meta'   => ['maxlength' => FOOTNOTE_MAX_LENGTH],
        ];
    }

    if ($mode === 'validate') {
        $value = trim((string) ($data['value'] ?? ''));

        if (mb_strlen($value) > FOOTNOTE_MAX_LENGTH) {
            return [
                'valid'  => false,
                'value'  => null,
                'errors' => ['Слишком длинно: максимум ' . FOOTNOTE_MAX_LENGTH . ' символов'],
            ];
        }

        return ['valid' => true, 'value' => $value, 'errors' => []];
    }

    return ['type' => 'error', 'errors' => ["Неподдерживаемый режим '$mode'"]];
}
// ============================================================
// date — календарная дата. Единственное нативное значение
// (браузерный <input type="date"> отдаёт готовую строку
// YYYY-MM-DD) — composite-сборка (день+месяц+год отдельными
// ключами) сюда не нужна: у даты есть родной HTML-виджет.
// Механизм сборки составных значений откладывается до реальной
// потребности — ent_dur (сроки схватывания «6-40»), у которой
// родного виджета нет и не будет.
// ============================================================
function ent_date(): array
{
    return [
        'id'    => 'date',
        'label' => 'Дата',

        'db' => [
            'kind'          => 'column',
            'default_type'  => 'date',
            'allowed_types' => ['date'],
            'nullable'      => true,
        ],

        'create' => [
            'params' => ['name', 'label'],
        ],

        'handlers' => [
            'new'      => 'date_handler',
            'edit'     => 'date_handler',
            'read'     => 'date_handler',
            'validate' => 'date_handler',
        ],
    ];
}

function date_handler(array $data, string $mode): array
{
    $field = $data['field'];

    if ($mode === 'read') {
        return [
            'type'   => 'value',
            'name'   => $field['raw'],
            'value'  => (string) ($data['value'] ?? ''),
            'subscr' => $field['subscr'],
        ];
    }

    if ($mode === 'new' || $mode === 'edit') {
        return [
            'type'   => 'input',
            'widget' => 'date',
            'name'   => $field['raw'],
            'value'  => $mode === 'new' ? '' : (string) ($data['value'] ?? ''),
            'subscr' => $field['subscr'],
            'meta'   => [],
        ];
    }

    if ($mode === 'validate') {
        $value = trim((string) ($data['value'] ?? ''));

        // Пустая строка физически невозможна в DATE (не как у varchar,
        // где '' — законное значение) — если колонка nullable, это NULL,
        // а не ошибка формата. Источник признака — ЖИВАЯ схема колонки
        // (field_data берёт её из реальной БД), не дефолт паспорта:
        // паспортный nullable — только подсказка конфигуратору при
        // создании колонки, а не источник истины в рантайме.
        if ($value === '') {
            $nullable = (bool) ($field['schema']['nullable'] ?? true);
            return $nullable
            ? ['valid' => true, 'value' => null, 'errors' => []]
            : ['valid' => false, 'value' => null, 'errors' => ['Поле обязательно']];
        }

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return ['valid' => false, 'value' => null, 'errors' => ['Формат даты: ГГГГ-ММ-ДД']];
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return ['valid' => false, 'value' => null, 'errors' => ['Такой даты не существует']];
        }

        return ['valid' => true, 'value' => $value, 'errors' => []];
    }

    return ['type' => 'error', 'errors' => ["Неподдерживаемый режим '$mode'"]];
}

// ============================================================
// year — только год. Отдельная сущность, не параметр date: под
// неё пока нет ни одного реального поля (Лист1 требует полную
// дату, не голый год) — регистрируется по прямой директиве
// «типы под всё, что поддерживает БД», не под текущую предметную
// нужду (в отличие от date). Хранение — smallint, не MySQL YEAR:
// последний физически годится только для 1901–2155, что было бы
// чужим ограничением, привязанным без реальной причины — у нас
// нет ни одного поля, которое вообще требует диапазон. validate
// проверяет ровно то, что гарантирует ВЫБРАННЫЙ тип (границы
// SMALLINT), не выдуманную календарную семантику.
// ============================================================
function ent_year(): array
{
    return [
        'id'    => 'year',
        'label' => 'Год',

        'db' => [
            'kind'          => 'column',
            'default_type'  => 'smallint',
            'allowed_types' => ['smallint', 'year', 'int'],
            'nullable'      => true,
        ],

        'create' => [
            'params' => ['name', 'label'],
        ],

        'handlers' => [
            'new'      => 'year_handler',
            'edit'     => 'year_handler',
            'read'     => 'year_handler',
            'validate' => 'year_handler',
        ],
    ];
}

const YEAR_SMALLINT_MIN = -32768;
const YEAR_SMALLINT_MAX = 32767;

function year_handler(array $data, string $mode): array
{
    $field = $data['field'];

    if ($mode === 'read') {
        return [
            'type'   => 'value',
            'name'   => $field['raw'],
            'value'  => (string) ($data['value'] ?? ''),
            'subscr' => $field['subscr'],
        ];
    }

    if ($mode === 'new' || $mode === 'edit') {
        return [
            'type'   => 'input',
            'widget' => 'number',
            'name'   => $field['raw'],
            'value'  => $mode === 'new' ? '' : (string) ($data['value'] ?? ''),
            'subscr' => $field['subscr'],
            'meta'   => [],
        ];
    }

    if ($mode === 'validate') {
        $raw = trim((string) ($data['value'] ?? ''));

        if ($raw === '') {
            $nullable = (bool) ($field['schema']['nullable'] ?? true);
            return $nullable
            ? ['valid' => true, 'value' => null, 'errors' => []]
            : ['valid' => false, 'value' => null, 'errors' => ['Поле обязательно']];
        }

        if (!preg_match('/^-?\d{1,5}$/', $raw)) {
            return ['valid' => false, 'value' => null, 'errors' => ['Год — целое число']];
        }
        $value = (int) $raw;
        if ($value < YEAR_SMALLINT_MIN || $value > YEAR_SMALLINT_MAX) {
            return ['valid' => false, 'value' => null, 'errors' => ['Значение вне допустимого диапазона']];
        }

        return ['valid' => true, 'value' => $value, 'errors' => []];
    }

    return ['type' => 'error', 'errors' => ["Неподдерживаемый режим '$mode'"]];
}

// ============================================================
// time — время суток (не длительность!). Единственное нативное
// значение (<input type="time"> отдаёт HH:MM). Для длительностей
// вида «6-40» (может быть >24ч — сроки схватывания) эта сущность
// не подходит: MySQL TIME физически годится (диапазон до 838
// часов), но HTML type="time" — только 00:00–23:59, "время суток",
// не "сколько прошло". Длительность — отдельная сущность (ent_dur,
// не строим сейчас), composite-механика откладывается до неё.
//
// MySQL всегда отдаёт TIME с секундами (HH:MM:SS), браузерный
// виджет без step отдаёт/ожидает HH:MM — нормализация в обе
// стороны на границе, БД по-прежнему видит только HH:MM:SS.
// ============================================================
function ent_time(): array
{
    return [
        'id'    => 'time',
        'label' => 'Время суток',

        'db' => [
            'kind'          => 'column',
            'default_type'  => 'time',
            'allowed_types' => ['time'],
            'nullable'      => true,
        ],

        'create' => [
            'params' => ['name', 'label'],
        ],

        'handlers' => [
            'new'      => 'time_handler',
            'edit'     => 'time_handler',
            'read'     => 'time_handler',
            'validate' => 'time_handler',
        ],
    ];
}

function time_handler(array $data, string $mode): array
{
    $field = $data['field'];

    if ($mode === 'read') {
        return [
            'type'   => 'value',
            'name'   => $field['raw'],
            'value'  => (string) ($data['value'] ?? ''),
            'subscr' => $field['subscr'],
        ];
    }

    if ($mode === 'new' || $mode === 'edit') {
        $value = $mode === 'new' ? '' : (string) ($data['value'] ?? '');
        // HH:MM:SS из БД → HH:MM для виджета без step="1".
        if (preg_match('/^(\d{2}:\d{2}):\d{2}$/', $value, $m)) {
            $value = $m[1];
        }

        return [
            'type'   => 'input',
            'widget' => 'time',
            'name'   => $field['raw'],
            'value'  => $value,
            'subscr' => $field['subscr'],
            'meta'   => [],
        ];
    }

    if ($mode === 'validate') {
        $value = trim((string) ($data['value'] ?? ''));

        if ($value === '') {
            $nullable = (bool) ($field['schema']['nullable'] ?? true);
            return $nullable
            ? ['valid' => true, 'value' => null, 'errors' => []]
            : ['valid' => false, 'value' => null, 'errors' => ['Поле обязательно']];
        }

        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value)) {
            return ['valid' => false, 'value' => null, 'errors' => ['Формат времени: ЧЧ:ММ, 00:00–23:59']];
        }

        // HH:MM с формы → HH:MM:SS для БД, канонический TIME.
        return ['valid' => true, 'value' => $value . ':00', 'errors' => []];
    }

    return ['type' => 'error', 'errors' => ["Неподдерживаемый режим '$mode'"]];
}
// ============================================================
// int — целое число. Родной widget уже существует ('number',
// добавлен для year) — render.php не меняется вообще. Отдельно
// от data: там validate только trim(), число как текст ломает
// численное сравнение в БД (WHERE/ORDER BY сравнивали бы строки).
// ============================================================
function ent_int(): array
{
    return [
        'id'    => 'int',
        'label' => 'Целое число',

        'db' => [
            'kind'          => 'column',
            'default_type'  => 'int',
            'allowed_types' => ['int', 'smallint', 'bigint'], // mediumint не существует в Postgres (2026-07-16)
            'nullable'      => true,
        ],

        'create' => [
            'params' => ['name', 'label'],
        ],

        'handlers' => [
            'new'      => 'int_handler',
            'edit'     => 'int_handler',
            'read'     => 'int_handler',
            'validate' => 'int_handler',
        ],
    ];
}

function int_handler(array $data, string $mode): array
{
    $field = $data['field'];

    if ($mode === 'read') {
        return [
            'type'   => 'value',
            'name'   => $field['raw'],
            'value'  => (string) ($data['value'] ?? ''),
            'subscr' => $field['subscr'],
        ];
    }

    if ($mode === 'new' || $mode === 'edit') {
        return [
            'type'   => 'input',
            'widget' => 'number',
            'name'   => $field['raw'],
            'value'  => $mode === 'new' ? '' : (string) ($data['value'] ?? ''),
            'subscr' => $field['subscr'],
            'meta'   => [],
        ];
    }

    if ($mode === 'validate') {
        $raw = trim((string) ($data['value'] ?? ''));

        if ($raw === '') {
            $nullable = (bool) ($field['schema']['nullable'] ?? true);
            return $nullable
            ? ['valid' => true, 'value' => null, 'errors' => []]
            : ['valid' => false, 'value' => null, 'errors' => ['Поле обязательно']];
        }

        // Точка/запятая — явная ошибка, не молчаливое усечение до целого:
        // пользователь ввёл дробное туда, где его быть не может.
        if (!preg_match('/^-?\d+$/', $raw)) {
            return ['valid' => false, 'value' => null, 'errors' => ['Целое число, без дробной части']];
        }

        return ['valid' => true, 'value' => (int) $raw, 'errors' => []];
    }

    return ['type' => 'error', 'errors' => ["Неподдерживаемый режим '$mode'"]];
}

// ============================================================
// bul — простое булево поле (да/нет). Чекбокс в форме, TINYINT(1) в
// БД. Найдено в стресс-тесте Chat (2026-07-12, «главная фотография»,
// «показывать возраст» и т.п.) — отсутствовало полностью, дешёвая
// сущность того же калибра, что int/dec в своё время.
//
// НЕ nullable: булево по природе не имеет состояния «не заполнено» —
// оно либо да, либо нет. Непроверенный чекбокс = нет (0), не ошибка
// валидации никогда — сама природа чекбокса делает «неверное значение»
// невозможным.
//
// Страховка от классической ловушки чекбоксов — в render.php
// (render_input, ветка 'checkbox'): непроверенный чекбокс браузер не
// отправляет вовсе, а record_save() трактует отсутствие ключа как
// «поле не трогали» (частичное обновление законно) — без скрытого
// поля-страховки перед чекбоксом «снять галочку» было бы неотличимо
// от «не сохранять это поле», и старое значение тихо оставалось бы
// висеть в БД.
// ============================================================
function ent_bul(): array
{
    return [
        'id'    => 'bul',
        'label' => 'Да/нет',

        'db' => [
            'kind'          => 'column',
            // 2026-07-16: Postgres — tinyint(1) не существует. НЕ boolean:
            // bul_handler читает значение как литеральное целое
            // ((int)$value === 1), а php-pgsql возвращает boolean-колонки
            // строками 't'/'f' (не '1'/'0'), (int)'t' === 0 — читалось бы
            // как "нет" всегда, тихий баг того же класса, что уже поймали
            // на model_registry.active (STATE.md «Сейчас» п.9, шаг 1).
            // smallint избегает этой ловушки — значение туда-обратно
            // остаётся текстовым '1'/'0', как и было под MySQL.
            'default_type'  => 'smallint',
            'allowed_types' => ['smallint'],
            'nullable'      => false,
        ],

        'create' => [
            'params' => ['name', 'label'],
        ],

        'handlers' => [
            'new'      => 'bul_handler',
            'edit'     => 'bul_handler',
            'read'     => 'bul_handler',
            'validate' => 'bul_handler',
        ],
    ];
}

function bul_handler(array $data, string $mode): array
{
    $field = $data['field'];

    if ($mode === 'read') {
        return [
            'type'   => 'value',
            'name'   => $field['raw'],
            'value'  => ((int) ($data['value'] ?? 0)) === 1 ? 'Да' : 'Нет',
            'subscr' => $field['subscr'],
        ];
    }

    if ($mode === 'new' || $mode === 'edit') {
        return [
            'type'   => 'input',
            'widget' => 'checkbox',
            'name'   => $field['raw'],
            'value'  => $mode === 'new' ? 0 : ((int) ($data['value'] ?? 0) === 1 ? 1 : 0),
            'subscr' => $field['subscr'],
            'meta'   => [],
        ];
    }

    if ($mode === 'validate') {
        // Скрытая страховка в render.php гарантирует, что ключ ВСЕГДА
        // присутствует ('1' от чекбокса, '0' от скрытого поля) — но
        // проверяем без слепого доверия к чужому вводу: чужой источник
        // (не наша форма) мог не прислать вообще ничего.
        $raw = (string) ($data['value'] ?? '0');
        return ['valid' => true, 'value' => $raw === '1' ? 1 : 0, 'errors' => []];
    }

    return ['type' => 'error', 'errors' => ["Неподдерживаемый режим '$mode'"]];
}

// ============================================================
// dec — точное дробное число (измерения: глубина, температура,
// давление). DECIMAL, не FLOAT: последний — приближённое двоичное
// (0.1+0.2 ≠ 0.3 в самом MySQL), DECIMAL — точное фиксированное.
// Для измерений, тем более под будущую выборку/сравнение, это не
// теория — это разница между "работает" и "тонкий баг сравнения".
// Значение остаётся строкой до самой записи в БД: приведение к PHP
// float здесь вернуло бы то самое двоичное приближение, которого
// DECIMAL как раз избегает, — конвертация происходит НИГДЕ, кроме
// самого MySQL при вставке строки в DECIMAL-колонку.
// Точность/масштаб — фиксированные (10,2) на v0: конкретной нужды
// в ином масштабе нет ни у одного поля Лист1; появится — отдельное
// решение, не превентивный параметр создания.
// ============================================================
function ent_dec(): array
{
    return [
        'id'    => 'dec',
        'label' => 'Дробное число',

        'db' => [
            'kind'          => 'column',
            'default_type'  => 'decimal(10,2)',
            'allowed_types' => ['decimal'],
            'nullable'      => true,
        ],

        'create' => [
            'params' => ['name', 'label'],
        ],

        'handlers' => [
            'new'      => 'dec_handler',
            'edit'     => 'dec_handler',
            'read'     => 'dec_handler',
            'validate' => 'dec_handler',
        ],
    ];
}

function dec_handler(array $data, string $mode): array
{
    $field = $data['field'];

    if ($mode === 'read') {
        return [
            'type'   => 'value',
            'name'   => $field['raw'],
            'value'  => (string) ($data['value'] ?? ''),
            'subscr' => $field['subscr'],
        ];
    }

    if ($mode === 'new' || $mode === 'edit') {
        return [
            'type'   => 'input',
            'widget' => 'number',
            'name'   => $field['raw'],
            'value'  => $mode === 'new' ? '' : (string) ($data['value'] ?? ''),
            'subscr' => $field['subscr'],
            // Браузерный <input type=number> без step считает поле
            // целочисленным и режет дробные значения на границе
            // ("введите 1 или 2"). DECIMAL — дробный по определению.
            'meta'   => ['step' => 'any'],
        ];
    }

    if ($mode === 'validate') {
        $raw = trim((string) ($data['value'] ?? ''));

        if ($raw === '') {
            $nullable = (bool) ($field['schema']['nullable'] ?? true);
            return $nullable
            ? ['valid' => true, 'value' => null, 'errors' => []]
            : ['valid' => false, 'value' => null, 'errors' => ['Поле обязательно']];
        }

        if (!preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
            return ['valid' => false, 'value' => null, 'errors' => ['Число, разделитель — точка']];
        }

        // Строка, не (float) — см. докблок: приведение к float внесло бы
        // как раз ту двоичную неточность, ради ухода от которой выбран DECIMAL.
        return ['valid' => true, 'value' => $raw, 'errors' => []];
    }

    return ['type' => 'error', 'errors' => ["Неподдерживаемый режим '$mode'"]];
}
// ============================================================
// calc — вычисляемое поле, шаг 2 архивного плана (STATE.md «Позже»,
// согласовано 2026-07-14). КАРАНТИННЫЙ СПАЙК (журнал 07-07/08,
// commit-история) свою работу выполнил — доказал, что handler в
// режиме read читает соседние поля строки ($data['row']) без правки
// core.php — и заменяется здесь продакшн-версией: формула больше не
// зашита в PHP, а читается из скомпилированного плана
// (model.formulas → snapshot_build_formulas(), core.php), который
// field_data() кладёт в $data['field']['formula'] тем же способом,
// что и словарный 'dict'.
//
// Поле по-прежнему не редактируется пользователем (new/edit → null,
// field_exec молча пропускает его в форме) — значение всегда
// вычисляется заново при чтении, физический столбец в БД служит
// только пропуском поля через field_parse (Naming-Driven Behavior,
// §1), хранимое значение не используется и не доверяется.
//
// Область первого шага — только поля СВОЕЙ ЖЕ таблицы (whitelist в
// snapshot_build_formulas); родительская цепочка (rel_/dep_) и
// сложные операторы с приоритетом — дальше по архивному плану, не
// сейчас (§15.8, решение 07-14: абстракция по размеру реальной
// формулы, не заранее).
// ============================================================
function ent_calc(): array
{
    return [
        'id'    => 'calc',
        'label' => 'Вычисляемое (формула)',

        'db' => [
            'kind'          => 'column',
            'default_type'  => 'decimal(10,2)',
            'allowed_types' => ['decimal'],
            'nullable'      => true,
        ],

        'create' => [
            'params' => ['name', 'label'],
        ],

        'handlers' => [
            'new'      => 'calc_handler',
            'edit'     => 'calc_handler',
            'read'     => 'calc_handler',
            'validate' => 'calc_handler',
        ],
    ];
}

/**
 * Исполнение плана formula_parse() (core.php) над строкой записи —
 * строго слева направо, план одноуровневый, приоритет не нужен (§15.8,
 * решение 07-14). null — хотя бы одна переменная пуста в этой строке
 * (нет данных для расчёта) или деление на ноль — не роняем чтение,
 * просто нет результата.
 */
function formula_eval(array $plan, array $row): ?float
{
    $result     = null;
    $pending_op = null;

    foreach ($plan as $step) {
        if ($step['type'] === 'op') {
            $pending_op = $step['value'];
            continue;
        }

        $value = $row[$step['name']] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        $value = (float) $value;

        if ($result === null) {
            $result = $value;
            continue;
        }

        $result = match ($pending_op) {
            '+'     => $result + $value,
            '-'     => $result - $value,
            '*'     => $result * $value,
            '/'     => $value == 0.0 ? null : $result / $value,
            default => null,
        };
        if ($result === null) {
            return null;
        }
    }

    return $result;
}

function calc_handler(array $data, string $mode): ?array
{
    $field = $data['field'];

    if ($mode === 'new' || $mode === 'edit') {
        return null;
    }

    if ($mode === 'validate') {
        // Никогда не должен вызваться (input не рисуется, POST не придёт),
        // но на всякий случай — не роняем запись, просто ничего не пишем.
        return ['valid' => true, 'value' => null, 'errors' => []];
    }

    if ($mode !== 'read') {
        return ['type' => 'error', 'errors' => ["Неподдерживаемый режим '$mode'"]];
    }

    $formula = $field['formula'] ?? null;
    if ($formula === null) {
        return [
            'type'   => 'value',
            'name'   => $field['raw'],
            'value'  => '— (формула не задана)',
            'subscr' => $field['subscr'],
        ];
    }

    $result = formula_eval($formula['plan'], $data['row'] ?? []);

    return [
        'type'   => 'value',
        'name'   => $field['raw'],
        'value'  => $result === null
            ? '— (нет данных для расчёта)'
            : number_format($result, 2, '.', ' '),
        'subscr' => $field['subscr'],
    ];
}

// ============================================================
// link — ссылка на запись с ЯВНЫМ адресом (журнал 2026-07-12).
// Ведёт себя как voc_ (выпадающий список, подстановка подписи) —
// переиспользует voc_handler буквально, без изменений: хендлер
// словаря не видит и не спрашивает, КАК разрешился адрес источника,
// он просто использует уже скомпилированную запись (см. комментарий
// в voc_handler). Разница целиком на уровне сборки снапшота: адрес
// НЕ вычисляется из имени поля (лестница §16.1), а читается из явной
// записи в model_links — потому что двум полям на одной таблице
// нужен один и тот же источник под разными именами и ролями
// («любимый цвет» / «нелюбимый цвет» → оба voc_color), а имя может
// адресовать только одно место.
//
// Идея А (реализовано): ссылка на ЦЕЛУЮ запись другой таблицы — тот
// же круг целей, что у voc_ (склад с data_name, адресная таблица,
// таблица с шаблоном подписи). Одно имя = один смысл, глобально —
// та же конвенция, что у словарей (§16.1): link_favorite_color везде
// ведёт на один и тот же адрес, независимо от таблицы-владельца.
// Адрес не переопределяется после создания поля (задаётся один раз
// в конфигураторе).
//
// Идея Б (сознательно НЕ реализовано): ссылка на конкретное ПОЛЕ
// конкретной строки — кирпичик для будущего слоя отчётов/проекций
// (STATE.md → Позже). Другая по природе задача, не эта сущность.
//
// Известное ограничение v0: link_-поле нельзя вложить ТОКЕНОМ внутрь
// чужого шаблона составной подписи (`{link_x}` в чужом template) —
// компилятор шаблонов узнаёт вложенный словарь только по entity==='voc'
// (core.php, snapshot_build_dictionaries, проход 2). Однострочная
// правка, когда понадобится — не понадобилось для сегодняшней задачи.
// ============================================================
function ent_link(): array
{
    return [
        'id'    => 'link',
        'label' => 'Ссылка (адрес задан явно, не по имени)',

        'db' => [
            'kind'          => 'column_with_table',
            'default_type'  => 'int',
            'allowed_types' => ['int', 'smallint'], // mediumint не существует в Postgres (2026-07-16)
            'nullable'      => true,
        ],

        'create' => [
            'params' => ['name', 'label', 'target_table'], // цель — обязательный параметр создания
        ],

        'handlers' => [
            'new'      => 'voc_handler',
            'edit'     => 'voc_handler',
            'read'     => 'voc_handler',
            'validate' => 'voc_handler',
        ],
    ];
}
