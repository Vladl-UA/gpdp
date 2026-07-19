# ARCHITECTURE_SNAPSHOT_AI.md

source_main_commit: 2c3d95c
rebuild_type: full
status: verified against current PHP main, ARCHITECTURE.md, STATE.md and current/HANDOFF_2026-07-19T23-00.md
freshness_rule: compare actual file history after this document's latest commit; metadata fields are hints, never the freshness test

## 1. System criterion
GPDP is a model-driven PostgreSQL runtime. Client applications are database structure plus model metadata; generic code compiles them into one trusted snapshot and runs the same entity, record, relation, view, import and administration mechanisms across domains.

## 2. Public entry and contexts
`index.php` is the only public execution entry. `request_context()` resolves the closed `REQUEST_CONTEXTS` map before `snapshot_init()`. `data` requires a healthy runtime snapshot; `configurator` and `labels` remain reachable when the snapshot is broken because they repair model state.
`configurator.php` and `labels.php` are libraries exposing `configurator_dispatch()` and `labels_dispatch()`. Direct file access redirects to `index.php?_context=...`. The caller owns the DB connection lifecycle.

## 3. Layer map
- `config.php`: environment and paths.
- `db.php`: PostgreSQL connection, placeholders, execution, query count and transaction primitives.
- `index.php`: HTTP boundary, context routing, prepared tasks, PRG and renderer handoff.
- `core.php`: entity registry, field packages, snapshot compiler/storage/lock, CRUD, relations, reparent and render-neutral views.
- `entities.php`: entity passports and handlers.
- `helpers.php`: dictionary execution and reverse lookup.
- `configurator.php`: closed structural specifications, DDL and registry facts; no HTML construction.
- `labels.php`: label/template and dictionary-row administration; no private CSS.
- `render.php`: sole PHP source of HTML, including option tags and admin screens.
- `style.css`: shared presentation.
- `tools_bulk_import.php`: hierarchical JSON adapter over normal snapshot/dictionary/record paths.
- `smoke_test.php`: executable contract evidence.

## 4. DB boundary
PostgreSQL is the sole target. `db_select()`/`db_execute()` own query execution, `db_placeholders()` preserves project `?` syntax, and inserted ids require explicit `RETURNING`.
`db_transaction_begin/commit/rollback()` are deliberately thin. They provide honest results but no nesting, SAVEPOINT or automatic rollback policy. Transaction meaning and ordering remain in named callers.

## 5. Structural transaction invariant
Every configurator structural operation is one unit:
`schema lock → BEGIN → body(DDL + model_registry/model_labels/model_links/model_formulas) → snapshot_build inside transaction → COMMIT → snapshot_save → unlock`.
`configurator_with_lock(..., array $application)` owns this frame. The nine operation bodies do not rebuild or publish snapshots. Any body/compiler failure rolls DB changes back and leaves the old file untouched. The only residual failure after COMMIT is an old/unwritten snapshot file; DB facts remain internally consistent and cold rebuild repairs the file.
`configurator_refresh()` was removed as duplicate ownership.
PostgreSQL transactional DDL and visibility of own uncommitted catalog changes are required facts. IDENTITY sequences are non-transactional; gaps are valid.

## 6. Honest model registration
`configurator_register_element()` returns `{ok,id,errors}`, not a bare int. It checks both registry and label inserts; all seven callers stop on failure. This closes the previous false-success path where a failed registry insert could still attempt a label with id 0.

## 7. Snapshot and lock
There is one compiler, `snapshot_build()`. Cached/live modes differ in storage, not compilation semantics.
Normal release is owner-checked. Administrative stale release is `snapshot_lock_release_stale(db, application)`: rebuild and save first, delete the lock only after a healthy model is published. Failure leaves the lock in place. There is no age-based automatic release and no `snapshot_lock_force_release()`.
The 503 response and configurator diagnosis expose lock age from `started_at`.

## 8. Rendering boundary
All HTML tags originate in `render.php`. `render_options()` is the single generator of `<option>` and accepts prepared `value/label` items plus scalar or array current values. Configurator supplies arrays, never prebuilt option HTML.
`render_configurator_new_table()` and `render_configurator_edit_table()` therefore accept item arrays. `render_choice()` and other field rendering reuse the same option primitive.
`render_duration()` formats lock age; `render_lock_state()` renders the diagnostic block and safe release form.
Labels-specific styling lives in `style.css`, not `labels.php`.

## 9. Model mechanisms retained
Entity fields remain `<entity>_<name>` and structural fields remain `id`, `dep_*`, `rel_main`, `active`.
Current entities include `data, voc, link, links, ltext, footnote, date, year, time, int, bul, dec, calc`.
`link_` and `links_` share `model_links` addressing and one dictionary compiler/executor. `links_` stores PostgreSQL `integer[]`; handlers validate every id.
Filtered dictionary VIEWs remain closed specifications: existing source with `id/data_name`, one existing `bul_` field, fixed `=1`, no arbitrary WHERE.
`calc_` is row-local; report aggregates remain a future report layer.
Reparent remains a separate protected operation.
Dictionary deletion calls `record_delete_check_usage()` before deletion, including scalar equality and `ANY()` array references.

## 10. Import
Bulk import resolves human labels through compiled dictionaries and writes only through `record_save()`. Dry-run uses the shared transaction primitives and checks BEGIN failure; no raw `pg_query('BEGIN'/'ROLLBACK')` remains.

## 11. Verification
Smoke now includes:
- section 0: structural transaction behavior;
- section 6a: `render_options()` scalar/multiple selection, escaping and static ownership check;
- section 8a: stale-lock safe release.
Live regression checks recorded in STATE.md cover structural transactions, option-layer ownership and lock recovery.

## 12. Forbidden regressions
- HTML, including `<option>`, outside render.php;
- structural operation body rebuilding/publishing its own snapshot;
- DDL/model facts outside the transaction frame;
- bare integer success from multi-write registration;
- stale lock deletion before successful rebuild/save;
- arbitrary SQL/WHERE from request or metadata;
- importer direct writes bypassing record_save;
- entity handlers emitting HTML or writing domain rows;
- a second snapshot compiler or dictionary executor.

## 13. Open work
The current `STATE.md` “Сейчас” queue is empty. Deferred work remains: semantic report plans, true m2m with payload, constraints/index/FK configuration, authorization, snapshot versioning/local degradation, richer closed VIEW operations only after concrete consumers, and multi-writer heartbeat/ownership if the writer perimeter expands.