# ARCHITECTURE_SNAPSHOT_AI.md

generated_at_commit: 5ef443b
sections_verified: §1, §2, §3, §4.1-4.13, §6, §7, §8, §11, Open risks
status: content verified against current main; exact per-range blame remains partial
source_scope: PHP architecture files; freshness must be checked by file history, not this self-reported field

## 1. Purpose

GPDP is a model-driven runtime. A client application is a DB model plus metadata; generic code compiles that model into a snapshot and executes the same record/field operations for any domain.

Kernel criterion: code belongs to the kernel only if it can serve an unrelated client model without being edited.

## 2. Execution formula

`request → boundary validation → prepared task → record/view builder → field_data → field_exec → entity handler → structured result → renderer or record_save`

Entity modes: `new | edit | read | validate`. Entities never own a `save` mode; `record_save()` performs physical writes after validation.

## 3. Layers

- `config.php` → environment and paths. loc `config.php:13-54`, commit `ddcdebe`, status ✓.
- `index.php` → runtime orchestration; request must stop here as raw input. current blob `d40a1e3`, status ✓ for current file content.
- `core.php` → entity registry, field boundary, snapshot compiler, generic record/view pipeline. current blob `c0063ab`, status ⚠ for exact per-function blame.
- `entities.php` → `ent_<id>` passports and handlers. current blob `39719cd`, status ✓ for current file content.
- `helpers.php` → entity helpers; kernel must not depend on it directly. `lookup_labels()` now executes through `db_select`; commit `afa0cab`, status ✓.
- `db.php` → low-level SQL executor only: `db_select`, `db_execute`. loc `db.php:43-105`, commit `40e40a0`, status ✓.
- `render.php` → layout of structured views; no DB/model compilation.
- `configurator.php` → separate admin/DDL/repair perimeter. current blob `c77978c`, status ⚠ for exact per-function blame.
- `labels.php` → presentation/model metadata editor; registry reads and label UPSERT execute through `db_select/db_execute`; commit `2c15076`, status ✓.

Allowed direction: `index → core → entities → helpers`; SQL execution goes through `db.php`; view results go to `render.php`. Admin entry files remain separate until real role/permission filtering replaces file-level access separation.

## 4. Nodes

### 4.1 Configuration

`config()` loc `config.php:13-54`, commit `ddcdebe`, status ✓.

Owns DB credentials, entity/snapshot paths, snapshot mode, temporary application parameters. It is environment, not client model.

### 4.2 Runtime conductor

`index.php`, current blob `d40a1e3`, status ✓.

Owns boot, DB connection, snapshot init, request validation, prepared task construction, generic operation selection, PRG and renderer handoff. Must contain no entity behavior, DDL or domain SQL.

### 4.3 Entity registry

`entity_registry_load`, `entities`, `field_exec`; loc/calls in `FUNCTION_PASSPORTS_AI.md`; exact per-function blame remains ⚠.

Passport invariants: zero-arg `ent_<id>()`; passport id equals suffix; handler names come only from trusted passport; handler returns data, not HTML; handler never writes domain rows.

Current entities: `data voc link ltext footnote date year time int dec bul calc`. `calc` is quarantine, not precedent.

### 4.4 Field naming and structural fields

`field_parse`, `field_data`, `field_exec`; exact per-function blame remains ⚠.

Domain field: `<entity>_<local_name>`. Structural fields: `id`, `dep_*`, `rel_main`, `active`; they are kernel structure, not entities. Unknown fields fail snapshot compilation.

### 4.5 Trusted field package

`field_data()` is the boundary product. It carries trusted table/field identity, entity, labels, schema, compiled dictionary entry, value, optional row and DB handle. Handlers do not rediscover model facts.

Contract changes here require reading all handlers using the changed key and all snapshot builders producing it.

### 4.6 Snapshot compiler

`snapshot_*`; exact per-function blame remains ⚠.

Runtime snapshot sections: `structure`, `model.registry`, `model.dictionaries`, `model.relations`, `model.relations_root`, `presentation`, `application`.

Live schema introspection is allowed only during bootstrap, explicit rebuild and admin operations. Cached runtime reads compiled state.

### 4.7 Dictionaries and explicit links

`snapshot_build_dictionaries`, `snapshot_build_links`, `lookup_labels`; execution path verified through `db_select`.

`voc_` resolves source by convention; `link_` reads explicit target from `model_links`. Both compile to one label-plan format and execute through `lookup_labels()`.

Difference between `voc` and `link` is address resolution, not rendering/validation.

### 4.8 Structural relation graph

`snapshot_build_relations`, `record_children`, `record_tree`; exact per-function blame remains ⚠.

`dep_<parent>` compiles direct ownership edges. `rel_main` records root dossier membership separately. Runtime traversal reads compiled graph; no table scan per node.

### 4.9 Generic write

`record_save`, `record_delete`; execution through `db_execute`; exact loc/blame remains ⚠.

Whitelist comes from snapshot. Each submitted entity field runs `validate`; normalized values feed one generic INSERT/UPDATE through `db_execute`. Structural INSERT values use a separate trusted channel. Update reparenting is not ordinary field input.

### 4.10 Generic reads and views

`record_fetch`, `record_list`, `record_children`, `record_tree`, `record_label`, `record_table_view`, `record_form_view`.

Reads are semantic generic operations, not domain queries. `db.php` only executes their SQL; it does not define selection semantics.

### 4.11 View boundary

View builders produce render-neutral arrays. Renderer lays them out. Renderer must not read DB, resolve dictionaries, parse fields or build relation trees.

### 4.12 Admin/DDL perimeter

`configurator_*`; current blob `c77978c`, exact loc/blame remains ⚠.

Admin request is validated before DDL. Operations mutate schema plus model metadata, then rebuild snapshot under schema lock. MySQL DDL is not transactionally atomic with metadata; partial-state repair remains a design risk.

### 4.13 DB execution layer

`db_select` loc `db.php:43-69`; `db_execute` loc `db.php:77-105`; commit `40e40a0`, status ✓.

Migration is complete for production runtime/admin query paths in `core.php`, `configurator.php`, `helpers.php` and `labels.php`. Direct `mysqli_*` remains only for intentionally out-of-scope connection management (`mysqli_connect`, `mysqli_set_charset`, `mysqli_close`) and the explicitly retained schema-introspection case.

This is a mechanical `mysqli` wrapper, not SQL builder, query-plan compiler or dialect adapter. SQL text remains in calling code. Future `record_select($plan)` is a separate semantic layer.

Failure contract: `db_select()` maps both query failure and no rows to `[]`; callers whose prior failure behavior differed must document that difference locally. `db_execute()` returns `ok`, `affected_rows`, `id`, `error`.

## 6. Change impact map

- Handler logic → passport + handler + used field-package keys + matching renderer widget.
- Handler result shape → all producers of that type + view builders + renderer.
- Field package → `field_data`, consuming handlers, producing snapshot entries.
- Dictionary/link → `ent_voc`, `ent_link`, `voc_handler`, dictionary/link builders, `lookup_labels`, configurator target UI.
- Relations → structural parser, relation compiler, child/tree readers, parent binding, dependent-table creation.
- Write pipeline → `record_save`, validation handlers, index save branch, `db_execute` contract.
- Snapshot format → validate/build/save/load/init + all consumers of changed section.
- DDL → configurator validators, operation, registry/labels/link writes, lock/rebuild and compensation path.
- DB wrapper → `db.php` contract + every caller whose error path distinguishes failure from empty result.

## 7. Forbidden dependencies

- raw request → handler
- request value → callable name
- unverified request identifier → SQL
- entity → HTML
- entity → domain INSERT/UPDATE/DELETE
- renderer → DB or snapshot compiler
- helper → request/index
- normal runtime → DDL
- core → domain table/field names
- model data → executable SQL text
- `db.php` → model semantics
- new production query path → direct `mysqli_query/prepare/stmt_*` instead of `db_select/db_execute`

## 8. Regression signals

1. Adding one client field/type requires kernel edit without a new generic entity contract.
2. SQL contains a concrete client table name as a new special case.
3. A table gets its own form instead of a generic view builder.
4. A second dictionary executor appears beside `lookup_labels`.
5. Behavior is inferred again from DB type when entity already owns it.
6. Raw request crosses preparation boundary.
7. Same invariant is rechecked on several layers without a boundary reason.
8. A second snapshot build path appears.
9. A domain SELECT is added before classifying a reusable selection form.
10. Renderer learns DB/model structure.
11. `db.php` starts building SQL or interpreting model plans.
12. A production caller reintroduces direct query-execution `mysqli_*` calls.

## 11. Minimum context by task

### Scalar entity
Read: this snapshot §4.3-4.5; nearest handler/passport; renderer widget; configurator field parser; entity smoke tests.

### New relation
Read: §4.4, §4.6-4.10; relation compiler; one relation executor; configurator; view builder.

### Form bug
Read: index action branch; `record_form_view`; affected handler; widget renderer.

### Write bug
Read: index save branch; `record_save`; affected validate handler; `db_execute`; result/error path.

### Dictionary/link bug
Read: link/dictionary builders; `field_data`; `voc_handler`; `lookup_labels`; configurator target registration.

### Object-tree bug
Read: relation compiler; `record_children`; `record_tree`; `render_object_tree`.

### Configurator change
Read: exact configurator passport; validator/parser; one DDL operation; metadata writes; snapshot rebuild/lock.

### DB-call migration or audit
Read: `db.php`; target caller; its previous failure behavior; HANDOFF constraints. Do not redesign SQL or selection semantics.

## Open risks

- complete exact blame/range refresh for `core.php` and `configurator.php` passports;
- semantic query-plan layer (`record_select`) without storing SQL in model;
- MySQL DDL compensation/operation journal;
- `model_links` cleanup on field/table deletion;
- admin authorization and eventual replacement of file-level access separation with role/context filtering;
- local degradation instead of whole-model 503;
- stable full-address identity versus global field-name identity;
- explicit snapshot format version;
- m2m with relation payload; formulas/templates; report plans; multi-root model.