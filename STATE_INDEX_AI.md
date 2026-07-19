# STATE_INDEX_AI.md

source_main_commit: 2c3d95c
source_state_blob: current at handoff 2c3d95c
rebuild_type: full
source_scope: STATE.md only; code refs included only where STATE.md records them
format: date | tags | decision/status | ref
freshness_rule: compare STATE.md history after this document's latest commit

## Foundational
07-01 | model, configurator | Normal model changes go through the native configurator, not direct DB editing | architectural rule
07-01 | diagnosis | Primary problems are kernel design and incorrectly described models, not generic accumulated debt | diagnosis
07-01 | snapshot | One compiler; introspection only at bootstrap/rebuild/admin boundaries | runtime rule
07-04 | registry, presentation | model_registry and 1:1 model_labels split address from presentation | a0
07-08 | dictionary | simple/composite/link labels converge on compiled plans and one executor | a1/a2
07-08 | relation | dep_ is immediate ownership; rel_main is broader dossier membership | relation decision
07-10 | render | core creates render-neutral structures; render.php alone creates HTML | view-layer decision
07-12 | link | link_ has explicit target independent of field name | link decision
07-13 | DB, reports | mechanical DB execution and future semantic selection/report planning are separate layers | query-plan decision
07-14 | AI trust | passport ✓ requires exact current range, real provenance and caller review; connector offsets must be independently corrected | collaboration rule

## 07-14–07-18 closure
07-14 | calc | calc_ is a row-local field, not report statistics; parser/compiler/evaluator shipped and live field verified | calc closure
07-14 | reparent | parent change is a separate protected action | reparent closure
07-15 | calc, configurator | formula UI reuses formula_parse and same-table whitelist | formula UI
07-15 | import | hierarchical JSON import reuses dictionary plans and record_save; exact reverse lookup forbids fuzzy/first match | import closure
07-16 | PostgreSQL | sole DB target; centralized lifecycle/placeholders/execution; RETURNING required for ids | cutover
07-16 | smoke | PostgreSQL smoke migrated; db_query_count replaces MySQL Questions | smoke migration
07-17 | links | links_ uses integer[], model_links and existing dictionary executor; multi-select UI; end-to-end verified | links closure
07-17 | hybrid VIEW | closed filtered VIEW enters normal dictionary compiler; create/use/delete verified | hybrid closure
07-17 | delete integrity | dictionary value usage checked before deletion; scalar path live-tested, links ANY branch logically covered but awaited a natural live case | delete closure
07-17 | context | index.php became sole public entry; configurator/labels became dispatch libraries; HTML moved to render.php | context roadmap
07-18 | system menu | REQUEST_CONTEXTS became the single source for whitelist and system menu; 73/73 smoke | menu closure
07-18 evening | review | Chat review found confirmed PostgreSQL quoting, CSS-layer and registration-result defects; accepted fixes separated from larger deferred work | review entry

## 2026-07-19 structural transactions
07-19 | DB, transaction | db_transaction_begin/commit/rollback introduced as thin honest primitives; no nesting/SAVEPOINT | b1b3f3e
07-19 | configurator, atomicity | configurator_with_lock gained application parameter and owns BEGIN/body/build/COMMIT/save/unlock | d08396f
07-19 | configurator, ownership | nine structural bodies now own only DDL+registry; configurator_refresh removed | d08396f
07-19 | rollback | body or snapshot-build failure rolls back whole DB operation; half-created DB/unregistered state eliminated | d08396f/08f2dfd
07-19 | snapshot | snapshot file is published only after COMMIT; file-write failure leaves consistent DB and recoverable stale file | transaction decision
07-19 | import | bulk dry-run uses shared transaction primitives and checks BEGIN failure; IDENTITY gaps explicitly accepted | b1b3f3e
07-19 | testing | transaction regression passed live; smoke gained section 0 | 08f2dfd

## 2026-07-19 option ownership
07-19 | render, option | render_options became the only project source of `<option>` markup | 0b1c711
07-19 | boundary | configurator prepares value/label arrays; new-table and edit-table renderers accept arrays rather than HTML strings | 0b1c711
07-19 | reuse | render_choice and duplicate option loops converge on render_options; scalar and links-array current values supported | 0b1c711
07-19 | testing | smoke section 6a verifies scalar/multiple selected state, escaping and absence of option construction in configurator | d02a2fc
07-19 | live | option-layer regression passed and raw tag removed from smoke labels | e242281

## 2026-07-19 stale lock
07-19 | lock, removal | unsafe snapshot_lock_force_release removed | 90b7184
07-19 | lock, recovery | snapshot_lock_release_stale rebuilds and saves before unlink; any model/file failure preserves lock | 90b7184
07-19 | UX | index 503 and configurator diagnosis show lock age; render_duration and render_lock_state added | 90b7184
07-19 | policy | no automatic age release; distinguishing slow vs dead writer requires future heartbeat/ownership when writer perimeter expands | decision
07-19 | testing | smoke section 8a and live stale-lock recovery passed | 90b7184/51cc61c

## 2026-07-18/19 confirmed review fixes
07-18/19 | registry | configurator_register_element now returns honest `{ok,id,errors}` and all seven call sites check it | c9a34ec
07-18/19 | PostgreSQL | MySQL backticks removed from COUNT expressions | fd3842f
07-18/19 | CSS | labels-specific CSS moved from labels.php to style.css | fd3842f
07-18/19 | status | all items in the current “Сейчас” queue are closed; section is empty | current STATE.md

## Current state
current | entry | index.php is sole public entry; data/configurator/labels are closed contexts | current
current | DB | PostgreSQL sole target; execution, query count and transaction primitives centralized in db.php | current
current | structural writes | configurator operations are DB-transactional and snapshot publication ordered after commit | current
current | model | one snapshot compiler; dictionary/link/links/VIEW/formula/relation/reparent mechanisms present | current
current | render | all HTML and option construction belong to render.php; shared CSS external | current
current | lock | stale lock can be removed only through healthy rebuild/save-first recovery | current
current | testing | smoke contains transaction, option and lock sections; live regressions recorded green | current
current | now | “Сейчас” queue empty | STATE.md

## Deferred
deferred | reports | semantic selection/report layer only from demonstrated reusable cases
deferred | m2m | true m2m with payload after classification against contextual tables/links_
deferred | constraints | NOT NULL, UNIQUE, indexes and FK configuration
deferred | auth | roles and permissions
deferred | snapshot | format versioning and local degradation
deferred | locks | multi-writer heartbeat/process ownership only when writer perimeter expands
deferred | hybrid | richer VIEW filters only as new closed operations with concrete consumers
deferred | identity | full table+field identity when global field-name identity becomes insufficient

## Fast lookup
transaction | db_transaction_* → configurator_with_lock → structural bodies → snapshot_build → COMMIT → snapshot_save
option | prepared item arrays → render_options → select/configurator renderers
stale_lock | snapshot_lock_read.started_at → render_duration/render_lock_state → release_lock route → snapshot_lock_release_stale
registration | configurator_register_element honest result → seven checked call sites
freshness | git history of each AI document against its declared source scope