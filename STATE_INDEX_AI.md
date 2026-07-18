# STATE_INDEX_AI.md

source_main_commit: 1691b43
source_state_blob: 802469e
rebuild_type: full
source_scope: STATE.md only; code commit references included where STATE.md records them
format: date | tags | decision/status | ref
freshness_rule: compare STATE.md history after this baseline; do not infer staleness from unrelated commits

## Foundational decisions

07-01 | configurator, model | Normal data-model changes belong to the native configurator, not direct DB editing | STATE.md architectural rule
07-01 | diagnosis, debt | Two primary problem classes: insufficient kernel and incorrectly described models, not ordinary broad technical debt | STATE.md diagnosis
07-01 | snapshot | One compiled snapshot path; live introspection restricted to bootstrap, explicit rebuild and admin/diagnostic contour | STATE.md/ARCHITECTURE.md
07-04 | registry, labels | Model address and presentation split into `model_registry` plus 1:1 `model_labels`; service tables use `model_*` | STATE.md a0
07-07 | dictionary | Conventional dictionary addressing accepted as temporary resolver under configurator guarantees; future local degradation remains open | STATE.md journal
07-08 | dictionary, templates | Simple and composite labels converge on one compiled plan/executor | STATE.md a2
07-08 | relation | `dep_` is direct ownership; `rel_main` is broader root-dossier membership | STATE.md relation decision
07-09 | object_tree | Object card traverses the full dependency tree without an arbitrary model-depth cap | STATE.md object-tree milestone
07-10 | view, render | Core builds render-neutral data; renderer lays it out | STATE.md view-layer decision
07-11 | repair | Configurator diagnoses DB↔registry drift and exposes explicit repair choices | STATE.md repair contour
07-12 | link | `link_` provides an explicit target independent of field name | STATE.md link decision
07-13 | DB, boundary | Mechanical DB execution and semantic selection/report planning are different layers | STATE.md DB/query-plan decision
07-13 | query_plan | No subject-specific SELECT function before identifying a reusable operation form | STATE.md accepted rule

## AI collaboration and document trust

07-14 | AI context | Compact collaboration set: function passports, architecture snapshot, state index and timestamped handoff | AI_CONTEXT_PROTOCOL.md
07-14 | delegation | Chat handles bounded documentation/mechanical work; Claude+Vlad own architectural decisions | AI_CONTEXT_PROTOCOL.md
07-14 | freshness | Each AI document is checked against its declared source scope | STATE.md workflow
07-14 | trust, lines | Passport `✓` requires exact current range, real provenance and caller review; connector offsets must be independently checked | accepted passport reviews
07-18 | handoff, current | Active handoffs live under `current/`; obsolete handoff tickets are removed after completion | current/HANDOFF and repository housekeeping

## 2026-07-14 closure

07-14 | DB, wrapper | MySQL-era execution calls consolidated behind `db_select/db_execute` | STATE.md item 0
07-14 | reparent | Parent change shipped as a separate protected operation | STATE.md reparent item
07-14 | CSS | Shared admin styling moved to `style.css` | STATE.md presentation item
07-14 | reports, grouping | Global field weight/importance rejected; importance belongs to report/presentation context | STATE.md decision
07-14 | calc | `calc_` retained for row-local formulas, not report statistics | STATE.md calc decision
07-14 | calc, production | Formula parser/compiler/evaluator and production calc handler shipped | STATE.md calc closure
07-14 | calc, live | `cementing_interval.calc_volume_deviation` verified live | STATE.md live verification

## 2026-07-15 configurator and bulk import

07-15 | calc, configurator | Existing-table editor accepts formulas, reuses parser and enforces same-table variables | STATE.md formula UI closure
07-15 | import, reverse | Exact human-label→id resolver added; absent/duplicate labels fail explicitly | STATE.md import plan
07-15 | import, format | Hierarchical JSON fixed: entity fields plus nested child arrays; dictionary values are human labels | STATE.md import format
07-15 | import, architecture | Import is an adapter over snapshot, dictionary plans and `record_save`, not a second write system | STATE.md acceptance
07-15 | import, hierarchy | Child dep_ ids come only from successful parent inserts; failed branches stop | STATE.md rule

## 2026-07-16 PostgreSQL cutover

07-16 | DB, decision | Full MySQL→PostgreSQL cutover; no dual-driver mode | STATE.md current plan
07-16 | DB, lifecycle | `db_connect/db_close` became the shared connection lifecycle | STATE.md step 1
07-16 | DB, placeholders | Caller `?` SQL retained through positional conversion | STATE.md step 1
07-16 | DB, execute | `db_execute.id` meaningful only with explicit `RETURNING` | STATE.md step 1
07-16 | schema | Live structure moved to PostgreSQL catalogs/information_schema | STATE.md step 1
07-16 | labels | Label save moved to `ON CONFLICT` | STATE.md migration
07-16 | boolean | `bul_` and model active remain numeric smallint because consumers expect 0/1 | STATE.md live bug lesson
07-16 | model, TZ2 | Full Sheet1–4 reference model constructed on PostgreSQL | STATE.md closure
07-16 | import, live | Real ТЗ-2 dictionaries and hierarchy loaded without importer failures | STATE.md live load
07-16 | acceptance | Well tree matched source and calc results remained correct | STATE.md visual verification

## 2026-07-16/17 configurator refactor and smoke

07-16 | configurator, audit | Repeated lock frame, registry+label insertion and managed-address checks identified before VIEW work | STATE.md modularity audit
07-16 | configurator, refactor | `configurator_with_lock`, `configurator_register_element`, `configurator_is_managed` extracted and reused | STATE.md milestone
07-16 | smoke, PostgreSQL | Smoke fully migrated: mysqli removed, fixtures isolated, UPDATE JOIN replaced, query counter introduced | STATE.md smoke migration
07-16 | DB, query_count | `db_query_count()` accepted as per-process N+1 evidence | STATE.md decision
07-17 | delete, SQL | MySQL-only LIMIT removed from DELETE after smoke exposed PostgreSQL syntax error | STATE.md lesson
07-17 | smoke | PostgreSQL suite reached 70/70 before unified-entry additions | STATE.md closure

## 2026-07-17 multivalue links

07-17 | links, decision | `links_` introduced for 0..N references to one explicitly addressed dictionary | STATE.md links item
07-17 | links, storage | PostgreSQL `integer[]`; pgsql text literals parsed/built explicitly | STATE.md implementation
07-17 | links, integrity | Array elements cannot carry normal REFERENCES; GPDP validate checks every selected id | STATE.md integrity decision
07-17 | links, reuse | `links_` reuses `model_links` and the existing dictionary compiler/executor | STATE.md reuse
07-17 | links, UI | Multi-select with empty sentinel; read/list values remain lists for renderer | STATE.md UI decision
07-17 | links, bugs | Literal `link` assumptions in compiler/configurator/UI exposed live and expanded to link+links | STATE.md live lessons
07-17 | links, live | Entity verified end-to-end after three integration bugs were closed | STATE.md closure

## 2026-07-17 hybrid dictionary VIEW

07-17 | hybrid, structure | Introspection includes VIEW and preserves `object_type` | STATE.md step 0
07-17 | hybrid, architecture | VIEW enters the existing dictionary compiler, not a new runtime path | STATE.md design
07-17 | hybrid, validation | `view_filtered` is closed: source id/data_name plus one bul_ filter fixed to `= 1` | STATE.md scope
07-17 | hybrid, safety | Arbitrary WHERE text forbidden | STATE.md §12 application
07-17 | hybrid, create | `configurator_create_view()` reuses lock/registry/compiler bricks | STATE.md implementation
07-17 | hybrid, registration | First live run exposed missing data_name registration; fixed | STATE.md lesson
07-17 | hybrid, delete | Delete chooses DROP TABLE/VIEW from object_type | STATE.md implementation
07-17 | hybrid, live | Complete create/use/delete flow verified live | STATE.md closure

## 2026-07-17 safe dictionary-value deletion

07-17 | delete, usage | `record_delete_check_usage()` built into the sole generic delete path | STATE.md journal
07-17 | delete, scalar | voc_/link_ references searched by equality | STATE.md implementation
07-17 | delete, array | links_ references searched through `id = ANY(field)` | STATE.md implementation
07-17 | delete, fast_path | Non-dictionary tables incur zero additional usage queries | STATE.md implementation
07-17 | delete, live | Used ТЗ-2 material blocked with exact table/record/field message; unused temporary material deleted | STATE.md live verification
07-17 | delete, caveat | Active links_/ANY branch not separately exercised live because reference model had no active links field | STATE.md explicit limitation

## 2026-07-17 object-card rendering

07-17 | render, actions | Record actions moved into one CSS hover menu; `<select>` rejected because it cannot open on hover | STATE.md first pass
07-17 | render, siblings | Leaf siblings merge into one table/group; records with their own children remain separate | STATE.md corrected branch
07-17 | render, compact | Records with at most four columns render as compact labelled lines | STATE.md second pass
07-17 | render, depth | Visual indentation capped to one step; vertical-line “wiring” removed | STATE.md second pass
07-17 | render, width | Tables use content width with max-width/overflow ceiling | STATE.md browser measurements
07-17 | render, cache | Stylesheet URL gains filemtime cache-busting | STATE.md incident lesson
07-17 | render, unresolved | Universal right edge not achieved because nested wrappers narrow descendant containing blocks; requires structural render redesign, not another CSS tweak | STATE.md explicit unfinished item

## 2026-07-17/18 unified entry through `_context`

07-17 | context, order | `request_context()` runs before `snapshot_init`; configurator must work while the data model is broken/locked | STATE.md roadmap stage 1
07-17 | context, whitelist | Context is a closed set, not dynamic function construction; missing means data, unknown means error | STATE.md decision
07-17 | configurator, dispatch | Former top-level configurator lifecycle wrapped in `configurator_dispatch()` | STATE.md stage 2
07-17 | render, configurator | Configurator HTML moved completely to `render_configurator_*`; configurator.php retains logic/data preparation | STATE.md stage 3
07-17 | labels, namespace | `labels_registry_id/labels_save_label` renamed to `model_label_registry_id/model_label_save` | STATE.md stage 4
07-17 | labels, dispatch | Labels lifecycle wrapped in `labels_dispatch()` and HTML moved to `render_labels_*` | STATE.md stage 4
07-17 | index, routing | `index.php` conditionally requires and calls the selected dispatch contour | STATE.md stage 5
07-17 | DB, ownership | Double-close bug found live; connection close belongs to index/caller, not dispatch functions | STATE.md live bug lesson
07-17 | context, links | Forms, redirects and internal links carry `_context` explicitly; data-content links explicitly use data | STATE.md stage 6
07-17 | architecture | Function namespaces and call diagram updated in ARCHITECTURE.md | STATE.md stage 8
07-18 | library, final | configurator.php and labels.php became true libraries; direct URLs only redirect to index context | STATE.md finalization
07-18 | entry, live | Three contexts, direct redirects, full click navigation and CRUD/admin cycles verified live | STATE.md closure
07-18 | smoke | Unified-entry additions brought live suite to 73/73 | STATE.md verification

## 2026-07-18 system menu

07-18 | menu, source | `REQUEST_CONTEXTS` became one source for whitelist and system menu; each entry has icon/label/href | STATE.md decision
07-18 | menu, render | `render_context_menu()` builds shared menu; current item is non-clickable | STATE.md implementation
07-18 | menu, page_open | `render_admin_page_open()` takes current context instead of free-form nav HTML | STATE.md implementation
07-18 | menu, symmetry | Per-page menu duplication/asymmetry structurally removed | STATE.md rationale
07-18 | menu, boundary | Page breadcrumbs remain separate; future report/presentation menu will be model-driven and must not reuse REQUEST_CONTEXTS | STATE.md explicit distinction
07-18 | menu, live | All contexts show identical menu; individual record pages gained it automatically; smoke 73/73 | STATE.md live verification

## Current status

current | entry | `index.php` is the sole public lifecycle; data/configurator/labels selected by closed context before snapshot | STATE.md current state
current | kernel | Entity registry, trusted field package, one snapshot compiler, CRUD/views, dictionary/link/links, relation tree, protected reparent, safe dictionary deletion and row formulas are present | STATE.md
current | DB | PostgreSQL sole target; connection/execution/query count centralized in db.php | STATE.md
current | dictionary | Base tables and filtered VIEW objects use one compiler/executor | STATE.md
current | configurator | Shared lock/registration/managed bricks support table/VIEW/repair/ALTER paths | STATE.md
current | presentation | All PHP HTML, including configurator and labels, lives in render.php | STATE.md
current | testing | Smoke 73/73; live context navigation, ТЗ-2 model/import and scalar deletion guard verified | STATE.md
current | AI docs | All three compact AI documents rebuilt at current handoff baseline; passport trust remains per-entry | this rebuild

## Deferred and open work

deferred | render, structure | Finish semantic object-tree restructuring if a common right edge is still required | STATE.md unresolved rendering item
deferred | delete, links | Exercise links_/ANY usage guard on a real active multivalue field | STATE.md caveat
deferred | query_plan, reports | Build semantic report/selection layer only from demonstrated reusable cases; never store arbitrary executable SQL | STATE.md rule
deferred | m2m | True m2m with payload remains unimplemented and must be classified against contextual tables and links_ | STATE.md roadmap
deferred | hybrid | Broader VIEW predicates require separate closed operations and real consumers | STATE.md scope
deferred | cleanup | Automatic cleanup of model_links/formula/view metadata on structural deletion needs design/verification | STATE.md risk
deferred | constraints | NOT NULL, UNIQUE, indexes and FK configuration | STATE.md roadmap
deferred | auth | Roles/permissions remain unimplemented; current contexts are routing, not security | STATE.md roadmap
deferred | snapshot | Explicit format version, local degradation and stale-lock resilience remain open | STATE.md resilience notes
deferred | identity | Full table+field identity may replace global field-name identity when collisions become real | STATE.md risk

## Fast lookup

context | REQUEST_CONTEXTS → request_context → index conditional require → configurator_dispatch/labels_dispatch → render_context_menu
labels | model_label_registry_id/model_label_save → labels_dispatch → render_labels_directory/editor
configurator | validate/parse → common lock/register/is_managed bricks → table/VIEW/repair/ALTER → configurator_dispatch → render_configurator_*
links | ent_links → array parse/build → links_handler → record_view_row → renderer → model_links/dictionary compiler
hybrid_view | snapshot object_type → validate_spec(view_filtered) → create_view → dictionary compiler → delete table/view
safe_delete | record_delete_check_usage → record_delete errors/used_by → labels/data callers
object_render | record_tree → render_record_auto → compact/table → object_tree_block → hover actions/style.css
snapshot | validate/build/save/load/init/refresh
formula | formula_parse → snapshot_build_formulas → field_data → formula_eval/calc_handler → configurator formula UI
bulk_import | format → reverse lookup → bulk_import_* → record_save → transaction
AI freshness | real file history within each document’s declared source scope
