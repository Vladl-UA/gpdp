# ARCHITECTURE_SNAPSHOT_AI.md

source_main_commit: 3d1af2c
rebuild_type: full
status: verified against current main code and current operational documents
freshness_rule: compare source-file history after this baseline; do not trust self-reported dates alone

## 1. Purpose and kernel criterion

GPDP is a model-driven runtime. A client application is a database model plus model metadata; generic code compiles those facts into a trusted snapshot and performs the same field, record, view and import operations for unrelated domains.

A mechanism belongs to the kernel only when another client model can use it without editing the mechanism. Domain names and one-off business procedures do not belong in `core.php`, entity handlers, renderer or DB executor.

## 2. Runtime formula

`request or trusted adapter input → boundary validation → trusted task/package → generic record/view operation → field_data → field_exec → entity handler → structured result → renderer or record_save/db_execute`

Entity modes are exactly `new | edit | read | validate`. Physical writes are not an entity mode. `record_save()`, `record_delete()` and `record_reparent()` are generic record operations with honest result contracts.

## 3. Layers and files

### Environment and low-level execution

- `config.php` — environment, DB credentials, paths, snapshot mode and temporary application settings.
- `db.php` — mechanical SQL execution only: `db_select()` and `db_execute()`.
- Connection open/charset/close remains outside the wrappers by design.
- Live schema introspection retains direct `mysqli` because `SHOW TABLES/SHOW COLUMNS` has a different result shape and is restricted to compiler/admin paths.

### Runtime and model compiler

- `index.php` — HTTP conductor: boot, request boundary, operation routing, PRG and renderer handoff.
- `core.php` — entity registry, naming parser, trusted field package, snapshot compiler, generic CRUD, relation/reparent operations and render-neutral views.
- `entities.php` — zero-argument `ent_<id>()` passports and handlers.
- `helpers.php` — reusable entity helpers, currently dictionary label execution and reverse label lookup.

### Presentation and administration

- `render.php` — the only PHP layer that creates HTML. It receives structured results and compiled snapshot views; it does not query the DB or infer model facts.
- `style.css` — shared static admin stylesheet. `render_admin_styles()` now returns only a `<link>`.
- `configurator.php` — separate DDL/model-repair perimeter.
- `labels.php` — labels/templates and dictionary-row administration.

### Data-loading adapter

- `tools_bulk_import.php` — CLI adapter for hierarchical JSON import. It is not a second kernel and does not bypass the runtime contracts.
- `BULK_IMPORT_FORMAT.md` — authoritative input-format description for humans and AI preparing import files.

Allowed core direction remains: `entrypoint/adapter → core → entities → helpers`; SQL execution passes through `db.php`; structured views pass to `render.php`.

## 4. Naming-driven model

Domain entity fields use `<entity>_<local_name>`. Structural fields are kernel facts rather than entities: `id`, `dep_*`, `rel_main`, `active`.

`field_parse()` classifies names using the entity registry and structural rules. Unknown fields stop snapshot compilation. Runtime code does not rediscover behavior from DB types after the entity passport has supplied it.

## 5. Entity registry and field package

`entity_registry_load()` discovers trusted zero-argument `ent_<id>()` passports. The callable handler name comes only from this registry, never from request or model data.

Current entities:

`data, voc, link, ltext, footnote, date, year, time, int, bul, dec, calc`

`field_data()` is the main trust-boundary product. It carries:

- confirmed table and field identity;
- entity id and local name;
- compiled labels and schema facts;
- compiled dictionary entry when applicable;
- compiled table-scoped formula when applicable;
- current value and optional complete row;
- DB handle for trusted helper calls.

Handlers consume this package and return structured data. They do not query model tables, derive source addresses, emit HTML or write domain rows.

## 6. Snapshot format and compiler

Required snapshot sections now include:

- `structure.tables`;
- `model.registry`;
- `model.dictionaries`;
- `model.relations`;
- `model.relations_root`;
- `model.formulas`;
- `presentation`;
- `application`;
- diagnostic `registry_orphans`.

`snapshot_validate()` intentionally invalidates pre-formula snapshots that lack `model.formulas`, forcing a rebuild rather than silently giving calc_ fields no plan.

There is one full build path: `snapshot_build()`. Cached and live modes differ only in storage, not in compilation semantics.

- cached: load validated PHP snapshot; bootstrap rebuild under lock when absent/invalid;
- live: execute the same `snapshot_build()` on each request without reading/writing the cache file.

Structural introspection is permitted only during bootstrap, explicit rebuild and admin operations. Presentation/model refreshes reuse trusted structural state and are fail-safe: a rejected refresh leaves the old snapshot intact.

## 7. Dictionaries, labels and explicit links

`snapshot_build_dictionaries()` compiles one execution format for:

- conventional `voc_` sources;
- simple `data_name` labels;
- composite table-label templates;
- explicit `link_` targets from `model_links`.

The difference between `voc_` and `link_` is address resolution, not rendering or validation. Both execute through `lookup_labels()`.

`lookup_labels()` performs one flat source SELECT and constructs labels in PHP from compiled literal/field/dict steps. Nested dictionary plans are embedded during compilation, and request-local caching prevents repeated SELECTs.

`lookup_id_by_label()` is the exact reverse map used by bulk import. It returns an honest result and rejects absent or ambiguous labels; it never picks the first duplicate silently.

## 8. Formula subsystem

The quarantined hard-coded calc spike has been replaced by production metadata-driven formulas.

- `formula_parse()` accepts a deliberately small grammar: `{field} operator {field}`, evaluated strictly left-to-right, with no precedence or parentheses.
- `snapshot_build_formulas()` reads `model_formulas JOIN model_registry`, scopes every plan by owning table, and whitelists variables against entity fields of that table.
- syntax errors, missing tables and foreign/non-entity operands produce compiler `unresolved` diagnostics and reject build/refresh.
- `field_data()` injects `model.formulas[table][field]` into the calc_ package.
- `formula_eval()` evaluates the compiled plan over the current row; missing operands and division by zero yield `null`, not a fatal error.
- `calc_handler()` remains non-editable in new/edit and computes only during read.

Formula syntax and label-template syntax are separate semantic consumers. `formula_parse()` is not `template_parse()` and is not a general expression engine.

## 9. Relation graph and object tree

`dep_<parent>` is the immediate ownership edge. `rel_main` is broader root-dossier membership. `snapshot_build_relations()` compiles both without runtime table scanning.

`record_children()` reads direct children from the compiled graph. `record_tree()` recursively builds the complete render-neutral object tree without an arbitrary depth cap. `render_object_tree()` only lays out that prepared tree.

## 10. Reparent as a separate protected operation

Changing a parent is not ordinary edit input and is not a hidden channel inside `record_save()`.

- `record_parent_relation()` resolves the unique dep_ relation from the table’s own structural fields.
- `record_reparent()` validates the record and proposed parent, then updates exactly one resolved dep_ column.
- `record_parent_candidates()` builds id→label options for the parent table.
- `record_reparent_view()` prepares the form view.
- `render_reparent_form()` lays it out.
- `record_tree()` includes `reparentable`; `render_object_tree()` shows ⇄ only when true.

The FK column name never comes from request. Server smoke verification for this contour is green.

## 11. Generic writes and views

`record_save()`:

1. confirms table and fields against snapshot;
2. sends each entity field through `validate`;
3. collects normalized values;
4. accepts structural values only through a separate trusted INSERT channel;
5. performs one generic INSERT/UPDATE through `db_execute()`;
6. returns the canonical result shape.

`record_delete()` and `record_reparent()` use the same honest result family.

View builders (`record_table_view`, `record_form_view`, `record_reparent_view`, record-tree node views, schema views) return arrays, not HTML. Renderer functions only arrange those arrays.

## 12. Bulk import architecture

The import format is hierarchical JSON:

`{"table": [{entity fields..., "child_table": [{...}]}]}`

Human labels, not internal ids, are used for `voc_`/`link_` values.

Pipeline:

1. CLI boots the same config, DB connection and `snapshot_init()`.
2. `bulk_import_split_record()` classifies entity fields, nested child tables and unknown keys from snapshot structure.
3. `bulk_import_resolve_fields()` converts dictionary labels through `lookup_id_by_label()`.
4. `bulk_import_dep_field()` resolves the child’s structural parent field.
5. `bulk_import_insert()` calls the same `record_save()` as the web runtime and recursively inserts children using the returned parent id.

A branch stops when its parent or fields fail; children are never inserted “into nowhere”. `--dry-run` executes the real path inside a transaction and rolls back. InnoDB auto-increment gaps after rollback are explicitly documented.

Bulk import is an adapter over the kernel, not a competing write implementation.

## 13. Admin/DDL perimeter

Configurator operations validate before DDL, write model metadata, and rebuild the snapshot under structural lock. Drift diagnosis exposes explicit human repair choices rather than guessing.

MySQL DDL and metadata writes are not transactionally atomic together. Compensation/operation journaling remains an open engineering risk.

## 14. DB abstraction boundary

The project-wide migration to `db_select/db_execute` is complete for production runtime/admin query paths. Direct query-execution `mysqli_*` must not reappear outside the declared connection and schema-introspection exceptions.

`db.php` is not:

- a SQL builder;
- a dialect abstraction;
- a semantic query planner;
- a place for model rules.

Future reusable selection plans (`record_select` or equivalent) are a separate semantic layer and must be justified by real filter/relation/projection/aggregate/report cases.

## 15. Change-impact map

- entity contract → passport, handler, field-package keys, renderer widget and configurator parser;
- field package → `field_data`, all consuming handlers and snapshot producer;
- snapshot format → validate/build/save/load/init/refresh plus all consumers;
- dictionary/link → compilers, `field_data`, `voc_handler`, `lookup_labels`, reverse lookup and configurator target UI;
- formula → model_formulas schema, formula compiler/evaluator, calc handler, refresh paths and tests;
- relation/reparent → structural parser, relation compiler, record operations, index branches and renderer;
- write pipeline → record operations, validate handlers, adapters and `db_execute` contract;
- bulk-import format → `BULK_IMPORT_FORMAT.md`, importer split/resolve/insert logic and dictionary-label uniqueness assumptions;
- renderer result shape → all producers and all consumers of that result type;
- CSS shell → `style.css` plus `render_admin_styles()` link contract.

## 16. Forbidden dependencies

- raw request or unvalidated JSON → handler/callable/SQL identifier;
- model data → executable SQL text;
- entity → HTML or domain write;
- renderer → DB, handler dispatch or model compilation;
- helper → request/index;
- normal runtime → DDL;
- core → concrete client table/field special case;
- importer → direct domain INSERT bypassing `record_save()`;
- DB executor → model semantics;
- formula/template plan → arbitrary PHP or SQL execution;
- ordinary edit → hidden reparenting;
- production query path → direct mysqli query/prepare APIs outside declared exceptions.

## 17. Regression signals

1. A second snapshot compiler path appears.
2. A second dictionary executor or reverse resolver appears.
3. Bulk import validates or writes differently from `record_save()`.
4. Renderer starts reading DB/model internals.
5. A calc_ handler receives hard-coded field names again.
6. Formula scope becomes global by field name instead of table+field.
7. Reparent accepts an FK column from request.
8. A domain-specific form/list appears beside generic view builders.
9. `db.php` starts constructing SQL or interpreting plans.
10. New direct query-execution mysqli calls appear.
11. A malformed snapshot section silently degrades instead of invalidating/rejecting refresh.
12. Human dictionary labels are resolved by fuzzy/first-match behavior.

## 18. Minimum context by task

- scalar entity: entity passport/handler, `field_data`, renderer widget, configurator parser, smoke tests;
- dictionary/link: dictionary/link compiler, `field_data`, `voc_handler`, lookup helpers, target registration;
- formula: `formula_parse`, `snapshot_build_formulas`, `field_data`, `formula_eval`, `calc_handler`, model refresh and tests;
- object tree/reparent: relation compiler, parent resolver, tree builders, index operation branch and render functions;
- bulk import: `BULK_IMPORT_FORMAT.md`, importer functions, lookup reverse resolver, `record_save`, transaction behavior;
- form/list bug: index branch, matching view builder, handler and renderer;
- write bug: record operation, validate handler, `db_execute`, PRG/error path;
- configurator change: validator/parser, exact DDL operation, metadata writes, lock/rebuild and repair path;
- DB-call audit: `db.php`, caller failure contract and declared exceptions.

## 19. Open risks and deferred work

- semantic query-plan/report layer based on demonstrated reusable forms;
- MySQL DDL compensation/operation journal;
- `model_links` and formula metadata cleanup on field/table deletion;
- authorization replacing file-level admin separation;
- local degradation for isolated model faults instead of whole-model unavailability;
- stable full-address identity where global field-name identity is insufficient;
- explicit snapshot format version;
- m2m with payload, report plans and multi-root models;
- dictionary-label uniqueness or an explicit import disambiguation mechanism;
- bulk importer completion beyond current steps, including broader live validation and operational UX.
