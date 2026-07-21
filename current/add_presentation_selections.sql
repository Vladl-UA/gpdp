-- Отдельный, самостоятельный файл — только то, что реально новое.
-- Безопасно перезапускать: IF NOT EXISTS — если presentation_selections
-- уже создалась при прошлой попытке (файл целиком запускался и упал на
-- model_registry, но неизвестно, дошло ли исполнение до конца или нет),
-- повтор не упадёт ошибкой «relation already exists».
--
-- Выполнять от роли gpdp, в базе gpdp_test:
--   psql -h 127.0.0.1 -U gpdp -d gpdp_test -f add_presentation_selections.sql
--
-- Подробное обоснование каждого поля — current/bootstrap_postgres.sql,
-- секция «НОВОЕ 2026-07-21» (этот файл — просто её копия для удобного
-- отдельного запуска, не самостоятельная редакция).

CREATE TABLE IF NOT EXISTS presentation_selections (
    dep_model_registry   INTEGER      NOT NULL PRIMARY KEY,
    source_table         VARCHAR(64)  NOT NULL,
    columns               TEXT         NOT NULL,
    filter_field          VARCHAR(64),
    filter_value          VARCHAR(255),
    CONSTRAINT fk_selections_registry FOREIGN KEY (dep_model_registry)
        REFERENCES model_registry(id) ON DELETE CASCADE
);
