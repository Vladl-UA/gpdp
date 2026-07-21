-- GPDP/RNA — системные таблицы модели (model_*), синтаксис Postgres.
-- 2026-07-16, переход на Postgres (STATE.md «Сейчас» п.9).
--
-- ВАЖНО: в репозитории никогда не было версионированного SQL-файла
-- для этих таблиц (они передавались Владу ad-hoc текстом по ходу
-- сессий — журнал 07-05/07-08/07-14/07-15). Это РЕКОНСТРУКЦИЯ по
-- документации STATE.md + фактическому использованию колонок в коде
-- (grep по core.php/configurator.php/labels.php), не копия оригинала.
-- Перед запуском на gpdp_test — Влад сверяет со своей текущей боевой
-- схемой (`\d model_registry` и т.п. на старой MySQL-базе), если
-- какая-то колонка разошлась — поправить здесь до запуска, не после.
--
-- Выполнять от роли gpdp, в базе gpdp_test:
--   psql -h 127.0.0.1 -U gpdp -d gpdp_test -f bootstrap_postgres.sql

CREATE TABLE model_registry (
    id           INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    data_kind    VARCHAR(16)  NOT NULL,   -- 'table' | 'field'
    data_owner   VARCHAR(64)  NULL,       -- NULL для kind='table'
    data_element VARCHAR(64)  NOT NULL,
    -- НЕ boolean: весь код (core.php/configurator.php/labels.php) пишет
    -- и сравнивает active как литеральное целое (`VALUES (...,1)`,
    -- `WHERE active = 1`), не как PHP true/false — приведение типов
    -- integer↔boolean в Postgres неявное не работает, при boolean эти
    -- INSERT/WHERE падали бы ошибкой типа (найдено живьём 2026-07-16,
    -- первая же тестовая таблица через конфигуратор — DDL прошёл,
    -- регистрация в реестре тихо не выполнилась, т.к. `configurator_
    -- create_table` не проверяет ошибку этого INSERT). Простой фикс —
    -- оставить тип числовым, не трогать ~10 мест кода ради формы.
    active       SMALLINT     NOT NULL DEFAULT 1,
    CONSTRAINT uq_registry_address UNIQUE (data_kind, data_owner, data_element)
);

-- 1:1 с реестром (решение 07-05 «Сейчас» п.1): dep_model_registry сам
-- PRIMARY KEY, суррогатный id не нужен — инвариант «не более одной
-- подписи на элемент» обеспечен схемой.
CREATE TABLE model_labels (
    dep_model_registry   INTEGER      NOT NULL PRIMARY KEY,
    data_short           VARCHAR(64)  NULL,
    data_full            VARCHAR(255) NULL,
    data_label_template  VARCHAR(255) NULL,  -- (а2), составная подпись словаря
    CONSTRAINT fk_labels_registry FOREIGN KEY (dep_model_registry)
        REFERENCES model_registry(id) ON DELETE CASCADE
);

-- calc_-поля (07-14): формула поверх полей ВЛАДЕЮЩЕЙ строки реестра.
-- Тот же 1:1 паттерн, что у model_labels (журнал 07-14, "другая рука/
-- ритм, чем подпись" — своя таблица, не колонка в model_labels).
CREATE TABLE model_formulas (
    dep_model_registry INTEGER NOT NULL PRIMARY KEY,
    data_formula        VARCHAR(255) NOT NULL,
    CONSTRAINT fk_formulas_registry FOREIGN KEY (dep_model_registry)
        REFERENCES model_registry(id) ON DELETE CASCADE
);

-- link_ (07-12/13, заморожено): адрес цели ссылки — какую таблицу
-- показывает выпадающий список этого поля. data_element — имя поля
-- (уникально на всю модель, §1 — «одно имя = один смысл»), поэтому
-- само выступает первичным ключом; суррогатный id не заводился и
-- в MySQL-версии (snapshot_build_links читает пару колонок без id).
CREATE TABLE model_links (
    data_element       VARCHAR(64) NOT NULL PRIMARY KEY,
    data_target_table  VARCHAR(64) NOT NULL
);

-- ============================================================================
-- НОВОЕ 2026-07-21 — не трогает ничего выше. На боевом gpdp_test уже
-- есть model_registry/model_labels/model_formulas/model_links из
-- прошлых сессий; выполнять на нём нужно ТОЛЬКО этот кусок, не файл
-- целиком (CREATE TABLE без IF NOT EXISTS на уже существующих
-- таблицах упадёт ошибкой «relation already exists» — ровно это и
-- произошло при первой попытке 2026-07-21, отсюда и IF NOT EXISTS
-- ниже, и отдельный файл current/add_presentation_selections.sql
-- с тем же куском — им безопаснее пользоваться напрямую, не выделять
-- вручную нужные строки из файла целиком).
--
-- presentation_selections — паспорт select_ (слой представления,
-- решение 2026-07-21, ARCHITECTURE.md §15 пройден по всем десяти
-- пунктам явно). ПРЕЗЕНТАЦИОННЫЙ этаж, не базовый — сознательно НЕ
-- model_*, свой префикс `presentation_` (core.php,
-- PRESENTATION_TABLE_PREFIX), чтобы не смешивать ДНК-слой
-- (model_labels/model_formulas — свойства САМОЙ модели) с РНК-слоем
-- (select_ — способ НА модель посмотреть). Тот же 1:1-паттерн через
-- dep_model_registry, что у model_labels/model_formulas выше:
-- dep_model_registry указывает на СТРОКУ РЕЕСТРА САМОГО select_-
-- представления (data_kind='table', data_element='select_<code>') —
-- не на строку source_table. Инвариант «не более одного паспорта на
-- select_» держит схема (PRIMARY KEY), не код.
--
-- Паспорт называет ИСТОЧНИК И РОЛИ, не SQL (§16 п.3 — тот же принцип,
-- что у паспорта проекции словаря, просто на этаж выше): текст SQL
-- нигде не хранится и из данных не исполняется, VIEW собирает код
-- (configurator_create_selection) по этим ролям, ровно один раз, при
-- создании/пересборке.
CREATE TABLE IF NOT EXISTS presentation_selections (
    dep_model_registry INTEGER      NOT NULL PRIMARY KEY,
    source_table        VARCHAR(64) NOT NULL,
    -- Список полей source_table через запятую — тот же приём, что
    -- model_labels.data_label_template (компактный текст-шаблон, а не
    -- дочерние строки «одна строка — одна колонка», §16 п.6 того же
    -- духа). TEXT, не VARCHAR(255) — единственное сознательное
    -- отступление от принятого в этом файле стиля колонок: список
    -- полей у широкой выборки может превысить 255 байт, а обрезать
    -- его молча — то самое тихое искажение факта, которого архитектура
    -- избегает везде (§13, ошибка отказом, не приближением).
    columns              TEXT        NOT NULL,
    -- Фильтр — v1: одно поле, одно значение (человекочитаемая метка,
    -- не id — резолвится при компиляции через lookup_id_by_label(),
    -- helpers.php, уже существует). NULL — выборка без фильтра, все
    -- строки source_table. Множественный фильтр, диапазоны (BETWEEN),
    -- каскадные словари — сознательно вне охвата v1 (WORKLOG.md,
    -- 2026-07-21 — расписан по шести пунктам возрастающей сложности).
    filter_field         VARCHAR(64),
    filter_value         VARCHAR(255),
    CONSTRAINT fk_selections_registry FOREIGN KEY (dep_model_registry)
        REFERENCES model_registry(id) ON DELETE CASCADE
);
