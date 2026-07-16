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
    active       BOOLEAN      NOT NULL DEFAULT TRUE,
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
