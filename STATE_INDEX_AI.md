# STATE_INDEX_AI.md

source_main_commit: 2d31d70
source_state_commit: e210079
rebuild_type: full
source_scope: STATE.md only; code refs included only where STATE.md records them
format: date | tags | decision/status | ref
freshness_rule: compare actual STATE.md history after this document's latest commit; metadata fields are hints, never the freshness test

## Foundational
07-01 | model, configurator | Normal model changes go through the native configurator, not direct DB editing | architectural rule
07-01 | diagnosis | Primary problems are kernel design and incorrectly described models, not generic accumulated debt | diagnosis
07-01 | snapshot | One compiler; introspection only at bootstrap/rebuild/admin boundaries | runtime rule
07-04 | registry, presentation | model_registry and 1:1 model_labels split address from presentation | a0
07-08 | dictionary | simple/composite/link labels converge on compiled plans and one executor | a1/a2
07-08 | relation | dep_ is immediate ownership; rel_main is broader dossier membership | relation decision
07-10 | render | core creates render-neutral structures; render.php owns HTML | view-layer decision
07-12 | link | link_ has explicit target independent of field name | link decision
07-13 | DB, reports | mechanical DB execution and future semantic selection/report planning are separate layers | query-plan decision
07-14 | AI trust | passport ✓ requires exact current range, real provenance and caller review; line offsets must be independently checked | collaboration rule

## 07-14–07-18 closure
07-14 | calc | calc_ is row-local, not report statistics; parser/compiler/evaluator shipped and live field verified | calc closure
07-14 | reparent | parent change is a separate protected action | reparent closure
07-15 | calc, configurator | formula UI reuses formula_parse and same-table whitelist | formula UI
07-15 | import | hierarchical JSON import reuses dictionary plans and record_save; exact reverse lookup forbids fuzzy/first match | import closure
07-16 | PostgreSQL | sole DB target; centralized lifecycle/placeholders/execution; RETURNING required for ids | cutover
07-16 | smoke | PostgreSQL smoke migrated; db_query_count replaces MySQL Questions | smoke migration
07-17 | links | links_ uses integer[], model_links and existing dictionary executor; multi-select UI verified | links closure
07-17 | hybrid VIEW | closed filtered VIEW enters normal dictionary compiler; create/use/delete verified | hybrid closure
07-17 | delete integrity | dictionary value usage checked before deletion; scalar path live-tested, links ANY awaited natural live case | delete closure
07-17 | context | index.php became sole public entry; configurator/labels became dispatch libraries | context roadmap
07-18 | system menu | REQUEST_CONTEXTS became source for whitelist and system menu; 73/73 smoke | menu closure
07-18 evening | review | Chat review separated confirmed PostgreSQL quoting, CSS-layer and registration-result defects from larger deferred work | review entry

## 2026-07-19 structural transactions
07-19 | DB, transaction | db_transaction_begin/commit/rollback introduced as thin honest primitives; no nesting/SAVEPOINT | b1b3f3e
07-19 | configurator, atomicity | configurator_with_lock gained application parameter and owns BEGIN/body/build/COMMIT/save/unlock | d08396f
07-19 | configurator, ownership | nine structural bodies own only DDL+registry; configurator_refresh removed | d08396f
07-19 | rollback | body or snapshot-build failure rolls back whole DB operation; half-created state eliminated | d08396f/08f2dfd
07-19 | snapshot | snapshot file publishes only after COMMIT; file-write failure leaves consistent DB and recoverable stale file | transaction decision
07-19 | import | bulk dry-run uses shared transaction primitives and checks BEGIN failure; IDENTITY gaps accepted | b1b3f3e
07-19 | testing | transaction regression passed live; smoke gained section 0 | 08f2dfd

## 2026-07-19 option ownership
07-19 | render, option | render_options introduced as the intended single PHP source of `<option>` markup | 0b1c711
07-19 | boundary | configurator prepares value/label arrays; new/edit renderers accept arrays rather than HTML strings | 0b1c711
07-19 | reuse | render_choice and configurator option loops converge on render_options; scalar and links-array current values supported | 0b1c711
07-19 | testing | smoke section 6a verifies selected state, escaping and absence of option construction in configurator | d02a2fc
07-19 | live | option-layer regression passed | e242281

## 2026-07-19 stale lock
07-19 | lock, removal | unsafe snapshot_lock_force_release removed | 90b7184
07-19 | lock, recovery | snapshot_lock_release_stale rebuilds and saves before unlink; failures preserve lock | 90b7184
07-19 | UX | index 503 and configurator diagnosis show lock age; render_duration and render_lock_state added | 90b7184
07-19 | policy | no automatic age release; heartbeat/ownership deferred until writer perimeter expands | decision
07-19 | testing | smoke section 8a and live stale-lock recovery passed | 90b7184/51cc61c

## 2026-07-19 night review
07-19 night 2 | architecture audit | Full code review ran thirteen mechanical checks against §§1,3,10,12,13; request isolation, handler boundaries, naming and runtime introspection placement were checked | STATE journal
07-19 night 2 | presentation findings | Three display choices remain hard-coded: compact threshold 4, table-width threshold 7, sibling merge based on grandchildren | STATE journal
07-19 night 2 | placeholder findings | table_group documented unreachable `report`; directories draw an empty Reports shelf before a report entity exists | STATE journal
07-19 night 2 | option finding | render_reparent_form still had a fourth private option loop at review time | STATE journal
07-19 night 2 | JS finding | Two configurator forms duplicate option-building JavaScript with behavioral drift | STATE journal
07-19 night 2 | security finding | render_admin_flash delegated escaping inconsistently, creating a future XSS seam | STATE journal
07-19 night 2 | DB finding | snapshot_build_structure uses raw pg_query/pg_query_params, the last undeclared bypass of db.php | STATE journal

## 2026-07-19 stage 5 rendering closure
07-19 night 3 | render boundary | HTML no longer originates outside render.php; index.php was the final contour with thirteen direct HTML-producing echo sites | d9b6e00
07-19 night 3 | new renderers | render_data_home, render_save_errors, render_page_heading, render_link_line and render_parent_context added | d9b6e00
07-19 night 3 | page close | render_admin_page_close is now used instead of manual closing markup; final labels.php HTML tail removed | d9b6e00
07-19 night 3 | smoke guard | project-wide static guard fails on HTML-producing echo/heredoc outside render.php and names the violating file | d9b6e00
07-19 night 3 | live verification | home, list, object tree, new/edit, reparent, 422, 404 and 400 paths checked without PHP warnings; full smoke green | e210079

## 2026-07-18/19 confirmed review fixes
07-18/19 | registry | configurator_register_element returns honest `{ok,id,errors}` and all seven call sites check it | c9a34ec
07-18/19 | PostgreSQL | MySQL backticks removed from COUNT expressions | fd3842f
07-18/19 | CSS | labels-specific CSS moved from labels.php to style.css | fd3842f
07-19 | status | all items in current “Сейчас” queue are closed; section is empty | current STATE.md

## Current state recorded in STATE.md
current | entry | index.php is sole public entry; data/configurator/labels are closed contexts | current
current | DB | PostgreSQL sole target; execution, query count and transaction primitives centralized in db.php | current
current | structural writes | configurator operations are transactional and snapshot publication follows commit | current
current | model | one snapshot compiler; dictionary/link/links/VIEW/formula/relation/reparent mechanisms present | current
current | render | stage 5 records HTML only in render.php, enforced by a project-wide smoke guard | current
current | lock | stale lock removal requires healthy rebuild/save first | current
current | testing | transaction, option, stale-lock and HTML-boundary checks recorded green | current
current | now | “Сейчас” queue empty | STATE.md

## Deferred/open findings recorded in STATE.md
- semantic report plans and true m2m with payload;
- constraints, indexes, FK configuration and authorization;
- snapshot versioning/local degradation;
- multi-writer heartbeat/process ownership only when writer perimeter expands;
- richer VIEW filters only as closed operations with concrete consumers;
- hard-coded renderer thresholds and sibling-layout decision;
- empty report shelves before report semantics exist;
- duplicated configurator JavaScript;
- flash escaping contract as recorded by the night-2 review;
- raw catalog queries in snapshot_build_structure as the remaining db.php bypass.

## Fast lookup
transaction | db_transaction_* → configurator_with_lock → structural bodies → snapshot_build → COMMIT → snapshot_save
option | prepared item arrays → render_options → select/configurator renderers
stale_lock | snapshot_lock_read.started_at → render_duration/render_lock_state → release_lock route → snapshot_lock_release_stale
stage5 | index prepared data → render_data_home/save_errors/page_heading/link_line/parent_context → render_admin_page_close
registration | configurator_register_element honest result → seven checked call sites
freshness | git history of each AI document against its declared source scope
