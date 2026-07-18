# ARCHITECTURE_SNAPSHOT_AI.md

source_main_commit: 1691b43
rebuild_type: full
status: verified against current PHP main, ARCHITECTURE.md and current/HANDOFF_2026-07-18T00-00.md
freshness_rule: compare `*.php` history after this baseline; self-reported source commit is only a hint

## 1. System criterion

GPDP is a model-driven runtime. A client application is a database structure plus model metadata; generic code compiles these facts into one trusted snapshot and applies the same entity, record, relation, view, import and administration mechanisms to unrelated domains.

A mechanism belongs to the kernel only when another client model can use it without editing the mechanism. Domain-specific tables, fields, reports and screens do not belong in the kernel.

## 2. Public entry and request contexts

`index.php` is the sole public execution entry.

The request contour is resolved before the data snapshot:

`boot → DB connection → request_context(GET, POST) → data | configurator_dispatch | labels_dispatch`

`REQUEST_CONTEXTS` is a closed associative map:

- `data`;
- `configurator`;
- `labels`.

Each entry carries `icon`, `label` and `href`. The same map is the whitelist for `request_context()` and the source for `render_context_menu()`. An unknown context returns `null` and becomes HTTP 400; absence means `data` for backward compatibility.

This early decision is structural: the configurator must remain accessible when the data snapshot is broken or schema-locked. Only the `data` contour passes through strict `snapshot_init()` before work.

The system-context menu is not the future model-driven presentation/report menu. They have different sources and semantics.

## 3. Library contours

`configurator.php` and `labels.php` are libraries, not competing entrypoints.

- `index.php` conditionally `require_once`s the selected library and calls `configurator_dispatch()` or `labels_dispatch()`;
- the caller owns `db_close()`;
- direct access to either library performs only a redirect to the matching `index.php?_context=...` URL;
- internal links, forms and PRG redirects carry `_context` explicitly;
- no dispatcher or DB lifecycle runs outside the dispatch function.

This replaced the former file-per-interface routing without merging the distinct responsibilities of data, structure and presentation metadata.

## 4. Runtime formula

Data contour:

`request → boundary validation → trusted task → record/view operation → field_data → field_exec → entity handler → structured result → render or record_save/db_execute`

Entity modes remain exactly `new | edit | read | validate`. Writes, deletes and reparenting are record operations, not entity modes.

## 5. Current platform and DB boundary

PostgreSQL is the sole current DB target; no dual-driver mode exists.

`db.php` owns:

- `db_connect()` / `db_close()`;
- `db_placeholders()` for retained `?` placeholders;
- `db_select()` / `db_execute()` for trusted caller-owned SQL;
- `db_query_count()` for per-process query-attempt accounting.

The legacy `$types` letters carry no PostgreSQL typing semantics; empty versus non-empty only chooses direct versus parameterized execution. Insert ids exist only when caller SQL explicitly contains `RETURNING`.

`db.php` is not a SQL builder, model interpreter or report planner. SQL meaning remains in the named record/configurator/lookup/compiler operation that owns it.

## 6. File and layer map

- `config.php` — environment, PostgreSQL credentials, paths and snapshot mode.
- `db.php` — connection, placeholders, mechanical execution and query counter.
- `index.php` — sole HTTP conductor and context router.
- `core.php` — request-context whitelist, entity registry, naming parser, field package, snapshot compiler, generic record operations and render-neutral views.
- `entities.php` — `ent_<id>()` passports and entity handlers.
- `helpers.php` — reusable dictionary execution and reverse lookup.
- `render.php` — the only PHP layer that creates HTML, including data, configurator and labels interfaces.
- `style.css` — shared presentation CSS.
- `configurator.php` — structure/model operations plus `configurator_dispatch()`.
- `labels.php` — presentation metadata and dictionary-row operations plus `labels_dispatch()`.
- `tools_bulk_import.php` — hierarchical JSON adapter over the normal runtime pipeline.
- `smoke_test.php` — executable contract evidence, not production runtime.

Function namespaces include `entity_*`, `field_*`, `snapshot_*`, `action_*`, `record_*`, `lookup_*`, `render_*`, `configurator_*`, `model_label_*`, `ent_<id>` and entity-specific `<id>_*`. `request_context()` is an intentional single-function exception, not a new growing family.

## 7. Rendering boundary

All PHP HTML is born in `render.php`.

`configurator.php` and `labels.php` retain validation, DB work, live model reads and dispatch decisions, but hand prepared values to:

- `render_configurator_*`;
- `render_labels_*`;
- common `render_admin_*`, `render_table_directory`, `render_schema_card` and diagnosis functions.

Renderer functions do not query the DB, compile model facts or execute entity handlers.

`render_admin_page_open(title, current_context, extra_head)` builds the system menu itself through `render_context_menu()`. Callers no longer assemble free-form navigation strings. Page-local breadcrumbs remain separate.

`render_admin_styles()` appends `style.css?v=<filemtime>` so each stylesheet change receives a new URL instead of relying on browser hard refresh.

## 8. Naming-driven model and field package

Entity fields use `<entity>_<local_name>`. Structural facts are `id`, `dep_*`, `rel_main` and `active`.

`field_parse()` classifies names against the discovered entity registry. Unknown fields reject snapshot compilation. Current entities:

`data, voc, link, links, ltext, footnote, date, year, time, int, bul, dec, calc`

`field_data()` is the trusted runtime package: confirmed table/field identity, entity id, schema, labels, compiled dictionary/formula metadata, value/row and trusted PostgreSQL connection. Handlers consume it and return structured results; they do not emit HTML or write domain rows.

## 9. Snapshot compiler

Required snapshot facts include:

- `structure.tables` and per-object `object_type`;
- `model.registry`;
- `model.dictionaries`;
- `model.relations` / `relations_root`;
- `model.formulas`;
- `presentation`;
- `application`;
- registry-orphan diagnostics.

`snapshot_build_structure()` reads PostgreSQL base tables and VIEWs through `information_schema.tables`, columns through `information_schema.columns`, and primary keys through PostgreSQL catalogs.

There is one full compiler path: `snapshot_build()`. Cached/live modes differ only in storage. Structural introspection is limited to bootstrap, explicit rebuild and admin/diagnostic work. Presentation/model refreshes reuse trusted structure and fail safely, retaining the last good snapshot.

## 10. Dictionaries, explicit links and hybrid VIEWs

`snapshot_build_dictionaries()` produces one executable label format for:

- conventional `voc_` fields;
- simple `data_name` dictionaries;
- composite label templates;
- scalar explicit `link_` fields;
- multivalue explicit `links_` fields;
- PostgreSQL VIEW sources exposing a valid dictionary projection.

`link_` and `links_` share `model_links`, address resolution and label execution. They differ only in cardinality/storage.

`configurator_validate_spec()` accepts a deliberately closed `view_filtered` specification: existing source with `id` and `data_name`, one existing `bul_` filter, fixed predicate `= 1`. Arbitrary WHERE/SQL is forbidden.

`configurator_create_view()` creates and registers the VIEW plus its `data_name` field, then rebuilds the snapshot through the same lock/registry/compiler bricks as table creation. `configurator_delete_table()` chooses `DROP TABLE` or `DROP VIEW` from trusted `object_type`.

A VIEW is therefore another source object for the existing dictionary compiler, not a second dictionary runtime.

## 11. `links_` multivalue entity

`links_` represents 0..N references to one explicitly addressed dictionary.

- PostgreSQL storage: `integer[]`;
- `links_array_parse()` / `links_array_build()` bridge PostgreSQL text literals and PHP integer lists;
- `links_handler()` implements read/new/edit/validate;
- read returns a list of labels;
- new/edit uses `select_multiple` with an empty sentinel;
- validate checks every id through the compiled dictionary.

PostgreSQL cannot apply a normal FK to each array element, so integrity is enforced at the GPDP validation boundary.

`record_view_row()` preserves lists. Renderer functions decide their vertical or compact layout.

## 12. Generic writes, reparent and safe deletion

`record_save()` validates only snapshot-whitelisted entity fields. Structural values use a separate trusted INSERT channel. INSERT uses explicit `RETURNING id`.

Parent change remains a separate protected operation: the dep_ field comes from trusted structure, record and new parent are verified, and one column is updated. Ordinary edit cannot reparent.

`record_delete_check_usage()` now protects dictionary values before deletion:

- it first identifies dictionary source usage from compiled `model.dictionaries`;
- non-dictionary tables exit without an extra query;
- scalar `voc_`/`link_` references use equality;
- `links_` array references use `id = ANY(field)`;
- usages return table, field and record id;
- `record_delete()` rejects deletion through its existing generic errors contract.

The scalar path was verified live on ТЗ-2. The `links_`/ANY branch is structurally implemented but has not yet been independently exercised against an active production links field.

## 13. Formula subsystem

`calc_` is a row-local computed field, not a report/statistics engine.

`formula_parse()` implements a deliberately small left-to-right grammar. `snapshot_build_formulas()` scopes plans by owning table and whitelists variables against its entity fields. Configurator validation reuses that parser. `formula_eval()` returns `null` on missing operands or division by zero. `calc_handler()` is read-only for the user.

## 14. Configurator common bricks

Structural operations reuse:

- `configurator_with_lock()`;
- `configurator_register_element()`;
- `configurator_is_managed()`.

Table/VIEW creation, deletion, adoption, orphan cleanup and field ALTER paths reuse these bricks rather than copying lock/registry scaffolding.

## 15. Object-card renderer

The object tree remains render-neutral in core; presentation policy is in render.php.

Current renderer behavior:

- indentation is capped to one visual step rather than increasing indefinitely;
- records with at most four columns use `render_record_compact()`;
- `render_record_auto()` chooses compact line versus table by structure, not table name;
- actions share one hover menu built by `render_actions_dropdown()`;
- `render_object_tree_block()` merges leaf siblings into one table/compact group;
- siblings that own children remain separate so parent-child meaning is preserved;
- relation headers and “add” action share one row;
- tables are sized to content through CSS.

The desired universal right edge is not fully solved: nested wrapper structure still narrows descendant containing blocks. This is a known presentation-structure issue, not reported as completed.

## 16. Labels and presentation metadata

The former `labels_*` functions are now `model_label_registry_id()` and `model_label_save()`, reflecting that labels/templates belong to model/presentation rather than a separate application namespace.

`labels_dispatch()` owns PRG and prepared data; `render_labels_directory()` / `render_labels_editor()` own HTML.

## 17. Bulk import

The importer accepts hierarchical JSON with nested child arrays and human dictionary labels. It resolves labels through compiled plans and writes only through `record_save()`. Successful parent ids feed trusted child dep_ values; failed branches stop. Dry-run uses the real PostgreSQL path inside BEGIN/ROLLBACK.

## 18. Smoke contract

The PostgreSQL smoke suite covers the core runtime and the unified-entry contour. Current live result is 73/73 green.

The suite uses isolated fixtures, PostgreSQL syntax and `db_query_count()` for N+1 evidence. Live browser checks additionally confirmed all three contexts, direct-file redirects, menu consistency and full navigation cycles.

## 19. Forbidden regressions

Forbidden:

- request text → dynamic callable/SQL identifier without validation;
- model data → arbitrary executable SQL;
- entity → HTML or direct domain write;
- renderer → DB/model compiler;
- configurator/labels → public parallel lifecycle outside index;
- context routing after `snapshot_init()`;
- free-form system menus duplicated per page;
- importer → direct INSERT bypassing `record_save()`;
- VIEW metadata → arbitrary predicates;
- `links_` → second address table/executor;
- ordinary edit → hidden reparent;
- deletion of referenced dictionary values without the generic usage guard.

Regression signals:

1. another public entry lifecycle appears;
2. configurator/labels start closing the connection themselves;
3. HTML returns to configurator.php or labels.php;
4. system context/menu lists diverge;
5. VIEW dictionaries get a separate runtime executor;
6. list values are flattened before rendering;
7. lock/registry scaffolding is copied again;
8. direct production queries bypass query counting without an explicit exception;
9. smoke assumptions fall behind production behavior.

## 20. Open work

- finish semantic restructuring of the object tree if a common right edge is still required;
- exercise the `links_` branch of deletion-usage checking on a real active field;
- semantic report/selection plans from demonstrated reusable cases;
- true m2m with payload after classification against contextual tables and links_;
- constraints/index/FK configuration;
- cleanup of model_links/formula/view metadata on structural deletion;
- role/authorization model;
- snapshot format versioning and local degradation;
- richer hybrid-view operations only when concrete consumers justify additional closed forms.
