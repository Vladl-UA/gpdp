# ARCHITECTURE_SNAPSHOT_AI.md

source_main_commit: eaae58b
rebuild_type: full
status: verified against current PostgreSQL main, STATE.md and HANDOFF_2026-07-16T18-00.md
freshness_rule: compare source-file history after this baseline; do not trust self-reported dates alone

## 1. Purpose and kernel criterion

GPDP is a model-driven runtime. A client application is a database model plus model metadata; generic code compiles those facts into a trusted snapshot and performs the same field, record, view and import operations for unrelated domains.

A mechanism belongs to the kernel only when another client model can use it without editing the mechanism. Domain names and one-off business procedures do not belong in `core.php`, entity handlers, renderer or DB executor.

## 2. Runtime formula

`request or trusted adapter input → boundary validation → trusted task/package → generic record/view operation → field_data → field_exec → entity handler → structured result → renderer or record_save/db_execute`

Entity modes are exactly `new | edit | read | validate`. Physical writes are not an entity mode. `record_save()`, `record_delete()` and `record_reparent()` are generic record operations with honest result contracts.

## 3. Current platform and DB boundary

The current production target is PostgreSQL. The project is no longer dual-driver and no MySQL compatibility layer is being maintained.

`db.php` is the single low-level DB boundary:

- `db_connect()` opens one UTF-8 PostgreSQL connection and throws on failure;
- `db_close()` closes it;
- `db_placeholders()` converts the project’s existing `?` placeholders to PostgreSQL `$1, $2, ...` positions;
- `db_select()` executes reads and returns associative rows;
- `db_execute()` executes writes and returns `ok`, `affected_rows`, `id`, `error`.

The legacy `$types` argument remains in `db_select/db_execute` only to avoid changing all callers. Its letters are no longer PostgreSQL type declarations; empty versus non-empty selects direct query versus parameterized query.

`db_execute()` never invents an inserted id. `id` is populated only when the caller’s SQL explicitly contains `RETURNING`; otherwise it is `0`.

`db.php` is not a SQL builder, semantic query planner or model interpreter. SQL text remains owned by the calling record/configurator/lookup/compiler function.

## 4. Layers and files

### Environment and low-level execution

- `config.php` — environment, PostgreSQL credentials, paths, snapshot mode and temporary application settings.
- `db.php` — connection lifecycle, placeholder conversion and mechanical query execution.
- `helpers.php::admin_db_connect()` — web-only wrapper over `db_connect()`; converts connection failure to HTTP 500.

### Runtime and model compiler

- `index.php` — HTTP conductor: boot, request boundary, operation routing, PRG and renderer handoff.
- `core.php` — entity registry, naming parser, trusted field package, snapshot compiler, generic CRUD, relation/reparent operations and render-neutral views.
- `entities.php` — zero-argument `ent_<id>()` passports and handlers.
- `helpers.php` — reusable entity helpers, currently dictionary label execution and reverse label lookup.

### Presentation and administration

- `render.php` — the only PHP layer that creates HTML. It receives structured results and compiled snapshot views; it does not query the DB or infer model facts.
- `style.css` — shared static admin stylesheet. `render_admin_styles()` returns only a `<link>`.
- `configurator.php` — separate DDL/model-repair perimeter using PostgreSQL DDL and `RETURNING id` where metadata ids are needed.
- `labels.php` — labels/templates and dictionary-row administration; label upsert uses PostgreSQL `ON CONFLICT`.

### Data-loading adapter

- `tools_bulk_import.php` — CLI adapter for hierarchical JSON import. It is not a second kernel and does not bypass runtime contracts.
- `BULK_IMPORT_FORMAT.md` — authoritative input-format description for humans and AI preparing import files.

Allowed direction remains: `entrypoint/adapter → core → entities → helpers`; SQL execution passes through `db.php`; structured views pass to `render.php`.

## 5. Naming-driven model

Domain entity fields use `<entity>_<local_name>`. Structural fields are kernel facts rather than entities: `id`, `dep_*`, `rel_main`, `active`.

`field_parse()` classifies names using the entity registry and structural rules. Unknown fields stop snapshot compilation. Runtime code does not rediscover behavior from DB types after the entity passport has supplied it.

## 6. Entity registry and field package

`entity_registry_load()` discovers trusted zero-argument `ent_<id>()` passports. The callable handler name comes only from this registry, never from request or model data.

Current entities:

`data, voc, link, ltext, footnote, date, year, time, int, bul, dec, calc`

PostgreSQL-relevant storage decisions include `bul` using `smallint`, not PostgreSQL boolean: handlers expect numeric `0/1`, while PHP’s pgsql extension returns boolean columns as `t/f` strings.

`field_data()` is the main trust-boundary product. It carries confirmed table/field identity, entity id, compiled labels/schema, dictionary metadata, table-scoped formula, value/row and a trusted `PgSql\Connection` for helper calls.

Handlers consume this package and return structured data. They do not query model tables, derive source addresses, emit HTML or write domain rows.

## 7. Snapshot format and compiler

Required snapshot sections include:

- `structure.tables`;
- `model.registry`;
- `model.dictionaries`;
- `model.relations`;
- `model.relations_root`;
- `model.formulas`;
- `presentation`;
- `application`;
- diagnostic `registry_orphans`.

`snapshot_build_structure()` is PostgreSQL-specific live introspection: table enumeration through `pg_catalog.pg_tables`, columns through `information_schema.columns`, and primary keys through `pg_index/pg_attribute`. It preserves the established runtime field shape (`name/kind/entity/db_type/nullable/key`).

There is one full build path: `snapshot_build()`. Cached and live modes differ only in storage, not compilation semantics.

- cached: load validated PHP snapshot; bootstrap rebuild under lock when absent/invalid;
- live: execute the same `snapshot_build()` on each request without reading/writing the cache file.

Structural introspection is permitted only during bootstrap, explicit rebuild and admin operations. Presentation/model refreshes reuse trusted structural state and are fail-safe: a rejected refresh leaves the old snapshot intact.

## 8. Dictionaries, labels and explicit links

`snapshot_build_dictionaries()` compiles one execution format for conventional `voc_` sources, simple `data_name` labels, composite table-label templates and explicit `link_` targets from `model_links`.

The difference between `voc_` and `link_` is address resolution, not rendering or validation. Both execute through `lookup_labels()`.

`lookup_labels()` performs one flat source SELECT and constructs labels in PHP from compiled literal/field/dict steps. Nested plans are embedded during compilation; request-local caching prevents repeated SELECTs.

`lookup_id_by_label()` is the exact reverse map used by bulk import. It rejects absent or ambiguous labels and never chooses the first duplicate silently.

## 9. Formula subsystem

The quarantined hard-coded calc spike has been replaced by production metadata-driven formulas.

- `formula_parse()` accepts a deliberately small grammar, evaluated strictly left-to-right, with no precedence or parentheses.
- `snapshot_build_formulas()` reads `model_formulas JOIN model_registry`, scopes plans by owning table and whitelists variables against entity fields of that table.
- `field_data()` injects `model.formulas[table][field]` into the calc package.
- `formula_eval()` evaluates over the current row; missing operands and division by zero yield `null`.
- `calc_handler()` is non-editable in new/edit and computes during read.
- configurator calc input reuses `formula_parse()` and registers validated formulas through `configurator_register_formula()`.

Formula syntax and label-template syntax are separate semantic consumers. `formula_parse()` is not `template_parse()` and is not a general expression engine.

## 10. Relation graph, object tree and reparent

`dep_<parent>` is the immediate ownership edge. `rel_main` is broader root-dossier membership. `snapshot_build_relations()` compiles both without runtime table scanning.

`record_children()` reads direct children from the compiled graph. `record_tree()` recursively builds the complete render-neutral object tree. `render_object_tree()` only lays out that prepared tree.

Changing a parent is a separate protected operation:

- `record_parent_relation()` resolves the unique `dep_` relation;
- `record_reparent()` validates record and parent, then updates one resolved column;
- `record_parent_candidates()` prepares parent choices;
- `record_reparent_view()` and `render_reparent_form()` prepare and render the dedicated form.

The FK column name never comes from request.

## 11. Generic writes and PostgreSQL id semantics

`record_save()` confirms table/fields against snapshot, validates entity values, accepts structural values only through the trusted INSERT channel, then performs one generic INSERT/UPDATE through `db_execute()`.

PostgreSQL INSERT SQL explicitly includes `RETURNING id`; update uses the supplied id. Dynamic identifiers come only from trusted snapshot data. Values remain parameterized.

`record_delete()` and `record_reparent()` use the same honest result family. View builders return arrays, not HTML.

## 12. Bulk import architecture

The input is hierarchical JSON: entity fields plus nested child-table arrays. Human labels, not internal ids, are used for `voc_` and `link_` values.

Pipeline:

1. CLI boots the same config, `db_connect()` and `snapshot_init()`.
2. `bulk_import_split_record()` classifies fields, child tables and unknown keys.
3. `bulk_import_resolve_fields()` converts labels through `lookup_id_by_label()`.
4. `bulk_import_dep_field()` resolves the structural parent field.
5. `bulk_import_insert()` calls the same `record_save()` and recursively inserts children using the returned parent id.

A branch stops after parent/field failure. Dry-run executes the real importer between PostgreSQL `BEGIN` and `ROLLBACK`.

The real ТЗ-2 dataset was loaded successfully on PostgreSQL: dictionary batches and complete four-sheet well hierarchy produced zero importer failures; visual comparison matched the source, including calculated interval deviations.

## 13. Admin/DDL perimeter

Configurator validates before DDL, writes model metadata and rebuilds the snapshot under structural lock.

PostgreSQL table creation uses identity primary keys and PostgreSQL-valid structural/entity types. Metadata INSERTs that immediately need ids contain explicit `RETURNING id`. Label upsert uses `ON CONFLICT`.

Current configurator operations are not yet grouped into one explicit DB transaction/compensation protocol. Partial-success paths are reported honestly; operation journaling/compensation remains an engineering risk even though PostgreSQL itself supports transactional DDL.

## 14. Change-impact map

- DB connection/execution → `db.php`, `admin_db_connect`, CLI boots, caller error/id contracts;
- entity contract → passport, handler, field-package keys, renderer widget and configurator parser;
- snapshot format/introspection → validate/build/save/load/init/refresh and all consumers;
- dictionary/link → compiler, package, handler, helpers and configurator target UI;
- formula → schema, parser/compiler/evaluator, handler, refresh paths and configurator;
- relation/reparent → structural parser, relation compiler, record operations, index and renderer;
- write pipeline → record operations, validate handlers, adapters and `db_execute` contract;
- bulk-import format → format doc, split/resolve/insert logic and label uniqueness assumptions;
- CSS shell → `style.css` plus `render_admin_styles()`.

## 15. Forbidden dependencies

- raw request or unvalidated JSON → handler/callable/SQL identifier;
- model data → executable SQL text;
- entity → HTML or domain write;
- renderer → DB, handler dispatch or model compilation;
- helper → request/index;
- normal runtime → DDL;
- core → concrete client-table special case;
- importer → direct domain INSERT bypassing `record_save()`;
- DB executor → model semantics or automatic SQL rewriting beyond placeholders/RETURNING result reading;
- ordinary edit → hidden reparenting;
- production code → new direct MySQLi APIs;
- PostgreSQL query execution → duplicated connection/prepare/error machinery outside the declared low-level/introspection boundaries.

## 16. Regression signals

1. A second snapshot compiler path appears.
2. A second dictionary executor/reverse resolver appears.
3. Bulk import validates or writes differently from `record_save()`.
4. Renderer starts reading DB/model internals.
5. Formula scope becomes global by field name.
6. Reparent accepts a column name from request.
7. `db.php` starts interpreting model semantics or composing domain SQL.
8. A caller expects `db_execute()['id']` without explicit `RETURNING`.
9. PostgreSQL connection code is copied into another entrypoint.
10. MySQL-only DDL, backticks, `ON DUPLICATE KEY`, `AUTO_INCREMENT` or `mysqli_*` return to production paths.
11. A malformed snapshot silently degrades instead of invalidating/rejecting refresh.
12. Human labels are resolved by fuzzy/first-match behavior.

## 17. Minimum context by task

- DB layer: `db.php`, caller SQL, `RETURNING` need, failure contract and entrypoint lifecycle;
- scalar entity: entity passport/handler, `field_data`, renderer widget, configurator parser, smoke;
- dictionary/link: compiler, package, handler, helpers and target registration;
- formula: parser/compiler/package/evaluator/handler/configurator/refresh/tests;
- object tree/reparent: relation compiler, parent resolver, builders, index and renderer;
- bulk import: format doc, importer, reverse lookup, `record_save`, transaction behavior;
- configurator: validator/parser, DDL operation, metadata writes, lock/rebuild and partial-failure path.

## 18. Open risks and deferred work

- semantic query/report layer based on demonstrated reusable forms;
- explicit transaction/compensation or operation journal for multi-step configurator changes;
- cleanup of `model_links` and formula metadata on field/table deletion;
- authorization replacing file-level admin separation;
- local degradation for isolated model faults;
- stable full-address identity where global field-name identity is insufficient;
- explicit snapshot format version;
- m2m with payload, report plans and multi-root models;
- dictionary-label uniqueness or explicit import disambiguation;
- PostgreSQL adaptation of legacy smoke fixtures/queries and a complete automated green smoke run.