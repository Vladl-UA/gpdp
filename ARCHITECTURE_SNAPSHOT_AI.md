# ARCHITECTURE_SNAPSHOT_AI.md

source_main_commit: 843f89d
rebuild_type: full
status: verified against current PHP main and HANDOFF_2026-07-17T00-00.md
freshness_rule: compare `*.php` history after this baseline; self-reported source commit is only a hint

## 1. System criterion

GPDP is a model-driven runtime. A client application is a database structure plus model metadata; generic code compiles those facts into a trusted snapshot and applies the same entity, record, view, relation and import mechanisms to unrelated domains.

A mechanism belongs to the kernel only when another client model can use it without editing the mechanism. Domain-specific tables, fields, forms and reports do not belong in the kernel.

## 2. Runtime formula

`request or trusted adapter input → boundary validation → trusted task/package → generic record/view operation → field_data → field_exec → entity handler → structured result → renderer or record_save/db_execute`

Entity modes are exactly `new | edit | read | validate`. Database writes are record operations, not entity modes.

## 3. Current platform and DB boundary

The sole current DB target is PostgreSQL; there is no dual-driver mode.

`db.php` is the low-level connection/execution boundary:

- `db_connect()` and `db_close()` own connection lifecycle;
- `db_placeholders()` converts the project’s retained `?` placeholders to PostgreSQL positions;
- `db_select()` and `db_execute()` execute trusted caller-owned SQL;
- `db_query_count()` counts attempts executed through db.php for smoke/N+1 checks;
- the legacy `$types` letters have no PostgreSQL typing meaning; empty/non-empty only chooses direct versus parameterized execution;
- an inserted id exists only when caller SQL explicitly contains `RETURNING`.

`db.php` is not a SQL builder, model interpreter or semantic query planner. SQL text remains in the named record/configurator/lookup/compiler operation that owns its meaning.

## 4. File and layer map

- `config.php` — environment, PostgreSQL credentials, paths, snapshot mode and temporary application settings.
- `db.php` — connection lifecycle, placeholders, query execution and query counter.
- `index.php` — HTTP conductor, request boundary, routing, PRG and renderer handoff.
- `core.php` — entity registry, naming parser, field package, snapshot compiler, generic CRUD, relations, reparent and render-neutral views.
- `entities.php` — `ent_<id>()` passports and entity handlers.
- `helpers.php` — reusable entity helpers, currently dictionary label execution and reverse lookup.
- `render.php` — the only PHP layer that creates HTML.
- `style.css` — shared static admin stylesheet.
- `configurator.php` — DDL/model-repair perimeter, including table and filtered-view creation.
- `labels.php` — labels/templates and dictionary-row administration.
- `tools_bulk_import.php` — hierarchical JSON adapter over the normal snapshot/dictionary/record pipeline.
- `smoke_test.php` — executable contract test, not production runtime.

Allowed direction remains `entrypoint/adapter → core → entities → helpers`; DB execution passes through `db.php`; structured output passes to `render.php`.

## 5. Naming-driven model

Entity fields use `<entity>_<local_name>`. Structural facts are `id`, `dep_*`, `rel_main`, `active`.

`field_parse()` classifies names against the live entity registry. Unknown fields reject snapshot compilation. New entities therefore become naming-visible without adding literal cases to the parser.

Current entities:

`data, voc, link, links, ltext, footnote, date, year, time, int, bul, dec, calc`

## 6. Trusted field package

`field_data()` is the main runtime trust product. It contains confirmed table/field identity, entity id, labels/schema, compiled dictionary metadata, table-scoped formula, current value/row and a trusted PostgreSQL connection for helpers.

Handlers do not derive model addresses, write arbitrary rows or emit HTML. They consume the package and return a structured value/input/choice/validation result.

## 7. Snapshot structure and compiler

Required sections include:

- `structure.tables`;
- per-object `object_type` (`table` or `view`);
- `model.registry`;
- `model.dictionaries`;
- `model.relations` and `model.relations_root`;
- `model.formulas`;
- `presentation`;
- `application`;
- `registry_orphans` diagnostics.

`snapshot_build_structure()` reads `information_schema.tables`, so PostgreSQL VIEW objects are visible alongside base tables. Columns come from `information_schema.columns`; primary keys come from PostgreSQL catalogs. The normalized field shape remains `name/kind/entity/db_type/nullable/key`.

There is one full compiler path: `snapshot_build()`. Cached and live modes differ only in storage behavior. Structural introspection is limited to bootstrap, explicit rebuild and admin/diagnostic operations. Presentation/model refreshes reuse compiled structure and fail safely without overwriting the last good snapshot.

## 8. Dictionaries and explicit addressing

`snapshot_build_dictionaries()` compiles one executable label format for:

- conventional `voc_` fields;
- simple `data_name` dictionaries;
- composite label templates;
- scalar explicit `link_` fields;
- multivalue explicit `links_` fields;
- PostgreSQL VIEW sources that expose the required dictionary projection.

`link_` and `links_` differ in cardinality/storage, not address resolution. Both read their target from `model_links` and use the same compiled dictionary plan.

`lookup_labels()` performs a flat source read and assembles labels from embedded literal/field/dictionary steps with request-local caching. `lookup_id_by_label()` is the exact reverse used by import and rejects absent or duplicate human labels.

## 9. `links_`: multivalue dictionary reference

`links_` is a first-class entity for 0..N references to one explicitly addressed dictionary.

- storage is PostgreSQL `integer[]`;
- `links_array_parse()` converts PostgreSQL text array literals to unique PHP integer ids;
- `links_array_build()` serializes normalized ids back to an integer-array literal;
- `links_handler()` implements read/new/edit/validate;
- read returns a list of human labels, not a preformatted HTML string;
- new/edit returns `select_multiple` plus selected-id array;
- validate verifies every id through the compiled target dictionary;
- element-level SQL FK constraints are unavailable for array members, so integrity is enforced at the GPDP validation boundary.

`record_view_row()` preserves list values instead of forcing string conversion. `render_value()` and `render_record_table()` stack list entries vertically with `<br>`; `render_choice()` renders a multiple select with a hidden empty sentinel so clearing all choices is distinguishable from omitting the field.

## 10. Formula subsystem

`calc_` remains a row-local computed field, not a report/statistics engine.

- `formula_parse()` implements a deliberately small left-to-right grammar;
- `snapshot_build_formulas()` scopes formulas by owning table and whitelists variables against that table’s entity fields;
- configurator validation reuses `formula_parse()` rather than duplicating syntax logic;
- `formula_eval()` returns null on absent operands or division by zero;
- `calc_handler()` is read-only from the user’s perspective.

Formula syntax and label-template syntax remain separate semantic consumers.

## 11. Relation tree and protected reparent

`dep_<parent>` is immediate ownership; `rel_main` is broader root-dossier membership. Relations are compiled once into snapshot indexes.

`record_tree()` builds recursive render-neutral object data. Reparent is a separate protected operation: the FK name is resolved from trusted structural fields, the record and proposed parent are validated, and exactly one dep_ column is updated. Ordinary edit cannot smuggle a parent change through `record_save()`.

## 12. Generic writes and deletion

`record_save()` validates only snapshot-whitelisted entity fields, accepts structural values only through a separate trusted INSERT channel and uses explicit `RETURNING id` for inserts.

`record_delete()` deletes by trusted primary id without MySQL’s unsupported `LIMIT` extension. The missing PostgreSQL syntax was found by the live smoke suite, demonstrating why executable contract tests remain required even after a mechanical migration appears complete.

## 13. Configurator common bricks

The configurator’s repeated structural-operation frame was refactored into three reusable functions:

- `configurator_with_lock()` — acquire/release schema lock around a supplied operation;
- `configurator_register_element()` — register table/field metadata plus labels and return registry id;
- `configurator_is_managed()` — test an active registry address with NULL-safe owner comparison.

Create/delete/adopt/drop/add operations reuse these bricks. The refactor intentionally changed structure, not business behavior, and was verified live.

## 14. Hybrid dictionary VIEW

`configurator_validate_spec()` now recognizes `view_filtered`, a deliberately narrow dictionary-view specification:

- target name is a managed `voc_` name;
- source is an existing object exposing `id` and `data_name`;
- filter is one whitelisted `bul_` column;
- generated predicate is fixed to `= 1`;
- arbitrary WHERE text is forbidden.

`configurator_create_view()` creates `SELECT id, data_name FROM source WHERE bul_field = 1`, registers both the view and its `data_name` field, then rebuilds the snapshot through the same lock/registration/compiler bricks as table creation.

`configurator_delete_table()` reads trusted `object_type` and chooses `DROP TABLE` or `DROP VIEW`.

A VIEW is therefore not a parallel dictionary mechanism: once introspected, it enters the same dictionary compiler and lookup executor as a base table.

## 15. Bulk import

The importer accepts hierarchical JSON with nested child-table arrays. Dictionary/link values are human labels.

It resolves fields through compiled dictionary metadata and writes through `record_save()`. Successful parent ids feed trusted child dep_ values; a failed branch stops. Dry-run executes the real PostgreSQL path inside BEGIN/ROLLBACK.

The real four-sheet ТЗ-2 hierarchy was loaded on PostgreSQL with zero importer failures and visually matched the source, including calc results.

## 16. Rendering boundary

Renderer functions consume prepared arrays only. They do not read DB/model metadata or dispatch entity handlers.

Scalar and multivalue results remain structurally distinct until rendering. Vertical list layout is presentation policy, not an entity/core string-formatting rule.

## 17. Smoke contract

`smoke_test.php` has been fully migrated to PostgreSQL:

- no mysqli runtime calls;
- PostgreSQL fixture setup;
- `UPDATE ... FROM` instead of MySQL UPDATE JOIN;
- N+1 checks use `db_query_count()`;
- the delete path covers the PostgreSQL-compatible SQL;
- current live result is 70/70 green.

Smoke is not the production system, but it is part of the project’s contract evidence and must be migrated whenever its tested platform assumptions change.

## 18. Forbidden dependencies and regression signals

Forbidden:

- request/JSON → callable or SQL identifier without boundary validation;
- model data → executable arbitrary SQL text;
- entity → HTML or direct domain write;
- renderer → DB/model compiler;
- importer → direct INSERT bypassing record_save;
- config form → arbitrary VIEW predicate;
- links_ → second address table or second dictionary executor;
- ordinary edit → hidden reparent;
- db.php → model semantics or report planning.

Regression signals:

1. a second snapshot compiler appears;
2. VIEW dictionaries get a separate runtime executor;
3. link_ and links_ target resolution diverges;
4. list values are flattened in core/entities instead of renderer;
5. configurator operations copy lock/registration scaffolding again;
6. arbitrary WHERE/SQL enters view metadata;
7. db query counter is bypassed by new direct production query paths;
8. PostgreSQL smoke falls behind production SQL again;
9. a domain-specific report function appears before a reusable semantic selection form is identified.

## 19. Open work

- semantic selection/report plans from demonstrated reusable cases;
- true m2m with payload after classification against contextual-table and links_ mechanisms;
- constraints/index/FK configuration;
- cleanup and usage checks before deleting dictionary values, fields, views or target metadata;
- role/authorization model;
- snapshot format versioning and local degradation;
- full-address identity where global field-name identity becomes insufficient;
- richer hybrid-view filters only after a concrete consumer justifies each new closed operation.