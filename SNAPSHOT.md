# GPDP/RNA — Форма снапшота (SNAPSHOT)

Справочник скомпилированного снапшота: слои, ключи, потребители. Выведён из
`snapshot_build()` и семейства `snapshot_build_*` (core.php) — они авторитет по
форме; smoke_test.php её проверяет. Не источник решений (ARCHITECTURE.md) и не
источник состояния (STATE.md) — карта одной структуры данных.

Снапшот — скомпилированный артефакт, не источник (§8). Источник: DDL + реестр
(`model_registry`) + подписи (`model_labels`) + паспорта. Удаление снапшота и
запрос дают эквивалентную пересборку; в бэкапы снапшот не входит.

---

## Верхний уровень

Что возвращает `snapshot_build()` и что лежит в `state/…snapshot`:

| Ключ | Тип | Слой | Кто пишет | Кто читает |
|---|---|---|---|---|
| `generated_at` | string `Y-m-d H:i:s` | — | любая сборка/refresh | диагностика |
| `structure` | `['tables'=>…]` | structural | `snapshot_build_structure` | field_data, record_* |
| `model` | `['registry'=>…]` | model | `snapshot_build_registry` | адрес элемента (§17) |
| `presentation` | `['labels'=>…]` | presentation | `snapshot_build_presentation` | field_data → подпись, render |
| `application` | array | — | из `config()['application']` | index/render (`root_table`) |
| `registry_orphans` | array строк | диагностика | build / refresh_model | аудит конфигуратором |

Валидность (`snapshot_validate`): `structure.tables` — непустой массив,
`presentation` — массив.

## structural — `structure.tables`

Живое чтение схемы БД (`SHOW TABLES` / `SHOW COLUMNS`). Таблицы `model_*`
исключены (`SYSTEM_TABLE_PREFIX`): служебные, не предметные.

```
structure.tables[<table>] = [
    'name'   => <table>,
    'fields' => [
        <field> => [
            'name'     => <field>,
            'kind'     => 'entity_field' | 'structural',   // 'unknown' роняет сборку
            'entity'   => <entity_id> | null,              // напр. 'voc' для voc_mr
            'db_type'  => <сырой SQL-тип, напр. int(11)>,
            'nullable' => bool,                            // Null === 'YES'
            'key'      => <Key: '' | PRI | MUL | UNI>,
        ],
    ],
]
```

Потребители: `field_data()` берёт `fields[<field>]` в `$data['field']['schema']`;
`record_save/fetch/delete/list` — проверка существования таблицы и whitelist
entity-полей. `unknown`-поле до сохранённого снапшота не доживает: `snapshot_build`
роняется с причиной в `snapshot_last_error()` (fail-fast на границе).

## model — `model.registry`

Адресное пространство модели (§17), только `active = 1`. Ключ — полный адрес
реестра, значение — вся строка `model_registry` (`id, data_kind, data_owner,
data_element`).

```
model.registry = [
    'table' => [ <table>            => {id,data_kind,data_owner,data_element}, … ],
    'field' => [ <owner> => [ <element> => {…}, … ], … ],
]
```

Сирота реестра (ссылка на несуществующую таблицу/поле) сборку не блокирует —
инертна (§17): уходит в `registry_orphans` для аудита, не в fail-fast.

## presentation — `presentation.labels`

Подписи (§17, `model_labels`). JOIN с реестром даёт полный адрес для ключа
(labels хранит только FK `dep_model_registry`). Форма ключей — как у реестра,
только `active = 1`.

```
presentation.labels = [
    'table' => [ <table>            => {data_kind,data_owner,data_element,data_short,data_full}, … ],
    'field' => [ <owner> => [ <element> => {…}, … ], … ],
]
```

Потребитель: `field_data()` кладёт `presentation.labels.field[<table>][<field>]`
в пакет как `$data['field']['subscr']`. Имя ключа пакета — `subscr`,
историческое; источник — слой `labels`. Какую колонку подписи показать, решает
потребитель (render), не ядро.

## Пакет `$data` — мост снапшот → сущность

Не часть снапшота; собирается `field_data()` на границе и есть всё, что видит
сущность (контракт тонкой трубы):

```
$data = [
    'table' => <table>,
    'field' => [
        'raw'    => <field>,
        'entity' => <entity_id>,
        'name'   => <остаток имени после entity_>,
        'subscr' => presentation.labels.field[table][field] | [],
        'schema' => ['db_type'=>…, 'nullable'=>…, 'key'=>…],   // из structure
    ],
    'value' => <mixed>,
    'row'   => <?array — соседние поля строки, для процедурных сущностей>,
    'db'    => <mysqli — только для lookup_*; сырой SQL из request запрещён>,
]
```

Исполнение: `field_exec($data,$mode)` → `($passport['handlers'][$mode])($data,$mode)`.

## Хранение и пересборка

- Файл: `config()['paths']['snapshot']` в `state/`. Формат — `<?php return […];`
  (include + opcache, без json_decode). Запись атомарна: temp → контрольный
  include → rename.
- Тяжёлый путь: `snapshot_rebuild_structure()` — под DDL-lock, пересобирает всё.
- Лёгкие пути без lock: `snapshot_refresh_presentation()` (только `presentation`),
  `snapshot_refresh_model()` (только `model.registry`); под чужим lock отступают.
- Проверить форму вживую: удалить снапшот и дёрнуть запрос (пересоберётся), либо
  `var_export(snapshot_load())`. Контракты формы держит smoke_test.php.

## Границы этой формы

- **Слоёв реляций и словарей в текущем билдере НЕТ.** `snapshot_build()` строит
  ровно `structure / model.registry / presentation.labels / application /
  registry_orphans`. Ни `model.relations`, ни `model.dictionaries`, ни
  `data_label_template` в коде не существует (встречаются в legacy `voc_utf8.php`
  и в черновике `schema_runtime_draft.php` — археология, не образец). Появятся —
  вносить сюда тогда, не впрок (§12).
- **⚠ Рассогласование core ↔ smoke (на момент составления).**
  `snapshot_build_presentation()` кладёт подписи в `presentation.labels` (из
  `model_labels`), а smoke_test.php §1/§8 ещё проверяет
  `presentation.subscr[…]['data_fild']` и правит таблицу `subscr`. Это два разных
  облика presentation: `labels`/`model_labels` — текущий билдер, `subscr` —
  прежний. Смоук против этого core по §1 упадёт. Требует решения (привести смоук
  к `labels`, если эти файлы — актуальное дерево). До разрешения форму
  presentation считать по билдеру: `labels`.
