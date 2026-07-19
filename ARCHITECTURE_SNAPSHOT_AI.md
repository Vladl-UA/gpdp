# ARCHITECTURE_SNAPSHOT_AI.md

source_main_commit: 2d31d70
rebuild_type: full
status: verified against current PHP main, ARCHITECTURE.md, STATE.md and current/HANDOFF_2026-07-20T01-00.md
freshness_rule: compare actual `*.php` history after this document's latest commit; metadata fields are hints, never the freshness test

## 1. System criterion
GPDP is a model-driven PostgreSQL runtime. A client application is database structure plus model metadata; generic code compiles those facts into one trusted snapshot and applies the same entity, record, relation, view, import and administration mechanisms across domains.
A mechanism belongs to the kernel only when another client model can use it without editing that mechanism. Domain-specific tables, fields, reports and screens do not belong in the kernel.

## 2. Public entry and contexts
`index.php` is the only public execution entry. `request_context()` resolves the closed `REQUEST_CONTEXTS` map before `snapshot_init()`.
`data` requires a healthy runtime snapshot. `configurator` and `labels` are routed before strict runtime initialization so they remain available to repair a broken model.
`configurator.php` and `labels.php` are libraries exposing `configurator_dispatch()` and `labels_dispatch()`. Direct access redirects to the corresponding `index.php?_context=...` route. The caller owns DB connection lifecycle.

## 3. Layer map
- `config.php`: environment and paths.
- `db.php`: PostgreSQL connection, placeholders, execution, query count and transaction primitives.
- `index.php`: request boundary, context routing, prepared tasks, PRG and renderer handoff; no HTML construction.
- `core.php`: entity registry, field packages, snapshot compiler/storage/lock, CRUD, relations, reparent and render-neutral views.
- `entities.php`: entity passports and handlers; no HTML and no domain writes.
- `helpers.php`: dictionary execution and reverse lookup.
- `configurator.php`: closed structural specifications, DDL and registry facts; no HTML construction.
- `labels.php`: label/template and dictionary-row administration; no HTML construction and no private CSS.
- `render.php`: the only PHP file that creates HTML, including all `<option>` tags and all three UI contours.
- `style.css`: shared presentation.
- `tools_bulk_import.php`: hierarchical JSON adapter over normal snapshot/dictionary/record paths.
- `smoke_test.php`: executable contract evidence and static architecture guards.

## 4. DB boundary
PostgreSQL is the sole target. `db_select()` and `db_execute()` own normal query execution; `db_placeholders()` preserves project `?` syntax; inserted ids require explicit `RETURNING`.
`db_transaction_begin/commit/rollback()` are thin honest primitives with no nesting, SAVEPOINT or automatic policy. Transaction meaning and ordering remain in named callers.
`snapshot_build_structure()` still uses raw PostgreSQL catalog calls; the 07-19 code review records this as the last undeclared DB-boundary exception awaiting either explicit acceptance or migration.

## 5. Structural transaction invariant
Every configurator structural operation is one unit:
`schema lock → BEGIN → body(DDL + model_registry/model_labels/model_links/model_formulas) → snapshot_build inside transaction → COMMIT → snapshot_save → unlock`.
`configurator_with_lock(db, reason, body, application)` owns the whole frame. Nine operation bodies own only DDL and registry facts. Any body/compiler failure rolls back DB changes and leaves the old snapshot file untouched.
The only residual failure after COMMIT is an old or unwritable snapshot file; DB facts remain consistent and cold rebuild repairs the file. `configurator_refresh()` was removed as duplicate ownership.
PostgreSQL transactional DDL and visibility of own uncommitted catalog changes are required facts. IDENTITY sequences are not rolled back; gaps are valid.

## 6. Honest registration
`configurator_register_element()` returns `{ok,id,errors}`, not a bare integer. It checks both registry and label inserts; all seven callers stop on failure. This closes the former false-success path where a failed registry insert could still attempt a label with id 0.

## 7. Snapshot and lock
There is one compiler, `snapshot_build()`. Cached and live modes differ in storage, not compilation semantics.
Normal release is owner-checked. Administrative stale release is `snapshot_lock_release_stale(db, application)`: rebuild and save first, delete the lock only after a healthy model is published. Failure leaves the lock in place. There is no age-based automatic release and no `snapshot_lock_force_release()`.
The 503 response and configurator diagnosis expose lock age from `started_at`; `render_duration()` formats it and `render_lock_state()` presents it.

## 8. Rendering boundary — now enforced
The old architectural statement “HTML is born only in `render.php`” is now a verified invariant, not an aspiration. Stage 5 moved the last thirteen HTML-producing `echo` sites out of `index.php` and the last remaining HTML tail out of `labels.php`.
New data-contour layout functions are:
- `render_data_home()`;
- `render_save_errors()`;
- `render_page_heading()`;
- `render_link_line(href, label, optional_class)`;
- `render_parent_context()`.
`render_admin_page_close()` now closes all three contours instead of leaving manual `</body></html>` fragments in entrypoints.
A project-wide smoke guard scans every PHP file and fails on `echo '<'` or HTML heredoc outside `render.php`. The invariant currently holds across all thirteen PHP files.

## 9. Option and escaping ownership
`render_options()` is the sole server-side generator of `<option>`. It accepts prepared `value/label` items and scalar or array current values.
Configurator dispatch prepares arrays; `render_configurator_new_table()` and `render_configurator_edit_table()` accept arrays, not prebuilt HTML. `render_choice()` and `render_reparent_form()` both reuse `render_options()`.
`render_admin_flash()` now escapes its own message. Callers pass raw flash text; escaping responsibility is no longer duplicated or forgettable.
Client-side configurator JavaScript still builds some option markup in the browser and remains a recorded review finding, separate from the PHP-layer invariant.

## 10. Model mechanisms retained
Entity fields remain `<entity>_<name>`; structural fields remain `id`, `dep_*`, `rel_main`, `active`.
Current entities: `data, voc, link, links, ltext, footnote, date, year, time, int, bul, dec, calc`.
`link_` and `links_` share `model_links` addressing and one dictionary compiler/executor. `links_` stores PostgreSQL `integer[]`; handlers validate every id.
Filtered dictionary VIEWs remain closed specifications: existing source with `id/data_name`, one existing `bul_` field, fixed `=1`, no arbitrary WHERE.
`calc_` is row-local; report aggregates remain a future report layer. Reparent remains a separate protected operation.
Dictionary deletion calls `record_delete_check_usage()` before deletion, including scalar equality and `ANY()` array references.

## 11. Rendering decisions still embedded in code
The 07-19 architecture review records three presentation decisions still hard-coded in renderer logic:
- `RENDER_COMPACT_MAX_COLS = 4`;
- table-width threshold `<= 7`;
- sibling merge versus separate cards based on whether grandchildren exist.
They are working behavior, not yet model metadata. The proposed direction is caller-supplied presentation context when a concrete reusable need appears, not premature report design.
The review also records an empty reports shelf in directory renderers and duplicated configurator JavaScript as open presentation-layer work.

## 12. Import
Bulk import resolves human labels through compiled dictionaries and writes only through `record_save()`. Dry-run uses shared transaction primitives and checks BEGIN failure; no raw transaction control remains in the importer.

## 13. Verification
Smoke includes:
- section 0: structural transaction behavior;
- section 6a: `render_options()` scalar/multiple selection, escaping and ownership;
- section 8a: stale-lock safe release;
- a project-wide static guard that HTML originates only in `render.php`.
Stage 5 was also checked live across home, list, object card, new/edit, reparent, 422 failure, 404 and 400 paths. No PHP warnings were observed; the full smoke suite was green.

## 14. Forbidden regressions
- HTML or server-side `<option>` generation outside `render.php`;
- structural operation body rebuilding or publishing its own snapshot;
- DDL/model facts outside the transaction frame;
- bare integer success from multi-write registration;
- stale lock deletion before successful rebuild/save;
- arbitrary SQL/WHERE from request or metadata;
- importer direct writes bypassing `record_save()`;
- entity handlers emitting HTML or writing domain rows;
- a second snapshot compiler or dictionary executor.

## 15. Open work
The current `STATE.md` “Сейчас” queue is empty. Deferred work includes semantic report plans, true m2m with payload, constraints/index/FK configuration, authorization, snapshot versioning/local degradation, richer closed VIEW operations only after concrete consumers, multi-writer heartbeat/ownership if writer perimeter expands, the remaining raw catalog-query exception, hard-coded renderer decisions, empty report shelves and duplicated configurator JavaScript.
