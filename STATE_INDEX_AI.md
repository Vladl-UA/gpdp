# STATE_INDEX_AI.md

source_main_commit: 843f89d
source_state_blob: 3c77138
rebuild_type: full
source_scope: STATE.md only; code commit references are included where STATE.md records them
format: date | tags | decision/status | ref
freshness_rule: compare STATE.md history after this baseline; do not infer staleness from unrelated repository commits

## Foundational decisions

07-01 | configurator, model | Normal data-model changes belong to the native configurator, not direct DB editing | STATE.md architectural rule
07-01 | diagnosis, debt | Two primary problem classes: insufficient kernel and incorrectly described models, not ordinary broad technical debt | STATE.md diagnosis
07-01 | snapshot, runtime | One compiled snapshot path; live introspection is restricted to bootstrap, explicit rebuild and admin contour | STATE.md/ARCHITECTURE.md
07-04 | registry, labels | Model address and presentation split into `model_registry` plus 1:1 `model_labels`; service tables use `model_*` | STATE.md a0
07-07 | dictionary, resolver | Conventional dictionary addressing accepted as a temporary resolver under configurator guarantees; local degradation remains deferred | STATE.md journal
07-08 | dictionary, templates | Simple and composite dictionary labels converge on one compiled plan and one executor | STATE.md a2
07-08 | relation | `dep_` means direct ownership; `rel_main` means broader root-dossier membership | STATE.md relation decision
07-09 | object_tree | Object card traverses the full dependency tree without an arbitrary depth cap | STATE.md object-tree milestone
07-10 | view, render | Core builds render-neutral lists/forms/schema/tree data; renderer only lays out prepared structures | STATE.md view-layer decision
07-11 | registry, repair | Configurator diagnoses DB↔registry drift and exposes explicit repair operations | STATE.md diagnosis/repair contour
07-12 | link | `link_` provides one explicit target address independent of field name; projection-to-specific-field remains deferred | STATE.md link decision
07-13 | DB, boundary | Mechanical DB execution and future semantic selection/report planning are separate layers | STATE.md DB/query-plan decision
07-13 | query_plan | No subject-specific SELECT function: first identify a reusable operation form, then implement it once | STATE.md accepted rule

## AI collaboration and document trust

07-14 | AI context | Compact collaboration set: function passports, architecture snapshot, state index and timestamped handoff | AI_CONTEXT_PROTOCOL.md
07-14 | delegation | Chat handles bounded documentation/mechanical tasks; Claude+Vlad own architectural choices | AI_CONTEXT_PROTOCOL.md
07-14 | freshness | Each AI document is checked against its declared source scope, not every repository commit | STATE.md workflow
07-14 | trust, lines | Passport `✓` requires an exact current range, real code provenance and reviewed callers; known connector offsets must be countered by an independent count, not by deleting line numbers | accepted passport reviews
07-14 | handoff | Timestamped HANDOFF files are operational transfer artifacts and may occur several times per day | STATE.md collaboration workflow

## 2026-07-14 implementation closure

07-14 | DB, wrapper | MySQL-era calls were consolidated behind `db_select/db_execute`, enabling later DB migration without rewriting every caller contract | STATE.md item 0
07-14 | reparent | Parent change shipped as a separate protected operation, not ordinary edit input | STATE.md reparent item
07-14 | reparent, smoke | Relation resolution and reparent contour verified green live | STATE.md smoke closure
07-14 | CSS, presentation | Shared admin styling moved to `style.css`; PHP returns a stylesheet link | STATE.md presentation item
07-14 | reports, grouping | Global field importance/weight model rejected; importance belongs to report/presentation context | STATE.md decision
07-14 | calc, scope | `calc_` retained for row-local computed fields; not promoted into a general report/statistics engine | STATE.md calc decision
07-14 | calc, production | Formula parser/compiler/evaluator and production calc handler shipped with table-scoped whitelist | STATE.md calc closure
07-14 | calc, live | `cementing_interval.calc_volume_deviation` verified live | STATE.md live verification

## 2026-07-15 configurator and bulk import

07-15 | calc, configurator | Existing-table editor accepts calc formulas, reuses formula parser, checks same-table variables and registers metadata | STATE.md formula UI closure
07-15 | import, reverse_lookup | Exact human-label→id resolver added; absence and duplicate labels are explicit errors | STATE.md import plan
07-15 | import, format | Hierarchical JSON format fixed: entity fields plus nested child-table arrays; voc/link values are human labels | BULK_IMPORT_FORMAT milestone in STATE.md
07-15 | import, CLI | Bulk importer split into parent-field, record-split, label-resolution and recursive-insert functions | STATE.md import contour
07-15 | import, architecture | Import is an adapter over snapshot, dictionary plans and `record_save`, not a second validation/write system | STATE.md architectural acceptance
07-15 | import, hierarchy | Child dep_ ids come only from successful parent inserts; failed branch stops | STATE.md import rule
07-15 | import, ambiguity | Fuzzy or first-match dictionary lookup forbidden | STATE.md accepted rule

## 2026-07-16 PostgreSQL cutover

07-16 | DB, decision | Full MySQL→PostgreSQL cutover chosen; no dual-driver mode | STATE.md current plan
07-16 | DB, lifecycle | `db_connect/db_close` became the single connection lifecycle for web and CLI | STATE.md step 1
07-16 | DB, placeholders | Caller `?` SQL retained through `db_placeholders()` conversion to PostgreSQL positions | STATE.md step 1
07-16 | DB, execute | `db_execute()['id']` is meaningful only with explicit `RETURNING`; otherwise zero | STATE.md step 1
07-16 | schema, introspection | Live structure moved from SHOW commands to PostgreSQL catalogs/information_schema | STATE.md step 1
07-16 | write, returning | Generic insert and configurator metadata inserts that need ids use explicit `RETURNING id` | STATE.md migration notes
07-16 | labels, upsert | Label save moved to `ON CONFLICT` | STATE.md migration notes
07-16 | boolean | `bul_` and `model_registry.active` remain numeric smallint rather than PostgreSQL boolean because consumers expect 0/1 | STATE.md live bug lesson
07-16 | import, transaction | Dry-run now uses PostgreSQL BEGIN/ROLLBACK | STATE.md import migration
07-16 | model, TZ2 | Full Sheet1–4 reference model constructed on PostgreSQL | STATE.md reference-model closure
07-16 | import, live | Real ТЗ-2 dictionaries and hierarchy loaded with zero importer failures | STATE.md live load
07-16 | acceptance, visual | Well tree matched source across stages, intervals, materials, buffers, reagents, lab tests and АКЦ; calc results remained correct | STATE.md visual verification

## 2026-07-16/17 configurator refactor and PostgreSQL smoke

07-16 | configurator, modularity | Audit found repeated lock frame, registry+label insertion and managed-address checks before hybrid-view work | STATE.md modularity audit
07-16 | configurator, refactor | `configurator_with_lock`, `configurator_register_element`, `configurator_is_managed` extracted; eight consumers migrated without intended behavior change | STATE.md refactor milestone
07-16 | configurator, live | Create table, add field and delete operations verified after refactor | STATE.md live verification
07-16 | smoke, PostgreSQL | `smoke_test.php` fully migrated: mysqli removed, fixtures isolated, UPDATE JOIN replaced by UPDATE FROM, DB query counter introduced | STATE.md smoke migration
07-16 | DB, query_count | `db_query_count()` accepted as per-process replacement for MySQL Questions status in N+1 checks | STATE.md smoke decision
07-17 | delete, PostgreSQL | MySQL-only `LIMIT` removed from DELETE after smoke exposed the PostgreSQL syntax error | STATE.md lesson
07-17 | smoke, green | Complete PostgreSQL smoke suite reached 70/70 live | STATE.md smoke closure

## 2026-07-17 multivalue links

07-17 | links, decision | `links_` introduced for 0..N references to one explicitly addressed dictionary | STATE.md links item
07-17 | links, storage | Storage is PostgreSQL `integer[]`; pgsql text array literals are parsed/built explicitly | STATE.md implementation notes
07-17 | links, integrity | PostgreSQL cannot attach REFERENCES to array elements; GPDP validate checks every selected id | STATE.md integrity decision
07-17 | links, addressing | `links_` reuses `model_links` and the same dictionary compiler/executor as scalar `link_` | STATE.md reuse decision
07-17 | links, UI | New/edit uses multi-select with empty sentinel; read/list values are rendered vertically, not comma-flattened | STATE.md direct UI requirement
07-17 | links, boundary | Core preserves list values; HTML list layout remains exclusively in render.php | STATE.md layer decision
07-17 | links, bug | First live form exposed dictionary compiler’s literal `link` check; pass 1b expanded to `link|links` | STATE.md live bug lesson
07-17 | links, configurator | Target-required checks and both JS visibility paths expanded from link to link+links | STATE.md live bug lesson
07-17 | links, live | Entity built and verified end-to-end; three real integration bugs found and closed | STATE.md closure

## 2026-07-17 hybrid dictionaries through PostgreSQL VIEW

07-17 | hybrid, step0 | Structure introspection changed from base tables only to `information_schema.tables`, including VIEW and preserving `object_type` | STATE.md hybrid step 0
07-17 | hybrid, architecture | VIEW is a source object for the existing dictionary compiler, not a new runtime dictionary mechanism | STATE.md design
07-17 | hybrid, validation | `view_filtered` is a closed specification: existing source with id/data_name plus one existing bul_ filter, fixed `= 1` | STATE.md scope decision
07-17 | hybrid, SQL safety | Arbitrary WHERE text is not accepted; configurator emits the only allowed VIEW form | STATE.md §12 application
07-17 | hybrid, create | `configurator_create_view()` reuses lock and registry bricks, creates VIEW, registers metadata and rebuilds snapshot | STATE.md implementation
07-17 | hybrid, registration | VIEW’s `data_name` field must be registered; first live run exposed it as an orphan and the omission was fixed | STATE.md live bug lesson
07-17 | hybrid, delete | Delete path selects DROP TABLE or DROP VIEW from trusted `object_type` | STATE.md implementation
07-17 | hybrid, UI | New-table form gained a fourth mode for dictionary representation with closed source/filter selectors | STATE.md implementation
07-17 | hybrid, live | Complete create/use/delete flow verified live; three implementation bugs found and closed | STATE.md closure

## Current status

current | kernel | Generic entity registry, trusted field package, one snapshot compiler, CRUD/views, dictionary/link/links resolution, relation tree, protected reparent and row-local formulas are present | STATE.md current state
current | DB | PostgreSQL is the sole target; connection/execution/query counting centralized in db.php; SQL semantics remain in named callers | STATE.md current state
current | dictionary | Base tables and filtered VIEW objects enter one dictionary compiler/executor | STATE.md hybrid closure
current | multivalue | `links_` is production-tested for 0..N dictionary references using integer arrays | STATE.md links closure
current | configurator | Common lock/registration/managed-address bricks are shared by table/view/repair/alter operations | STATE.md refactor closure
current | testing | PostgreSQL smoke is 70/70 green; live ТЗ-2 model/import/visual verification is green | STATE.md verification
current | AI docs | All three compact AI documents rebuilt at handoff `843f89d`; passport exactness remains per-entry status, not implied globally | this rebuild

## Deferred and open work

deferred | query_plan, reports | Build semantic selection/report layer only from demonstrated reusable cases; never store arbitrary executable SQL in model metadata | STATE.md query-plan rule
deferred | m2m | True m2m with payload remains unimplemented and must be classified against contextual tables and links_ first | STATE.md roadmap
deferred | hybrid, filters | Broader VIEW predicates require separate closed operations and real consumers; do not grow `view_filtered` into arbitrary SQL | STATE.md hybrid scope
deferred | dictionary, deletion | Check references/usage before deleting dictionary values; design deferred, explicitly remembered | STATE.md 07-17 note
deferred | cleanup | Automatic cleanup of model_links/formula/view metadata on field/object deletion needs verification/design | STATE.md risk
deferred | constraints | NOT NULL, UNIQUE, indexes and FK constraints remain configurator work | STATE.md roadmap
deferred | auth | Roles/permissions should replace file-level admin separation | STATE.md roadmap
deferred | snapshot | Explicit snapshot format version and local degradation remain open | STATE.md resilience notes
deferred | identity | Full table+field identity may replace global field-name identity where collisions become real | STATE.md risk
deferred | files, workflow | File/image entities and model-level workflow/operations remain separate future layers | STATE.md stress-test findings

## Fast lookup

entity | entities.php passport/handler → field_data → renderer widget → configurator parser → smoke
links | ent_links → links_array_parse/build → links_handler → record_view_row → render_choice/value/record_table → dictionary compiler/model_links
hybrid_view | snapshot_build_structure.object_type → configurator_validate_spec(view_filtered) → configurator_create_view → dictionary compiler → configurator_delete_table
configurator_refactor | configurator_with_lock → configurator_register_element → configurator_is_managed → eight consumers
snapshot | validate/build/save/load/init/refresh + all changed model sections
formula | formula_parse → snapshot_build_formulas → field_data.formula → formula_eval/calc_handler → configurator formula UI
relation | snapshot_build_relations → record_children/tree → render_object_tree
reparent | record_parent_relation → record_reparent/candidates/view → render_reparent_form
bulk_import | BULK_IMPORT_FORMAT → lookup_id_by_label → bulk_import_* → record_save → transaction behavior
DB audit | db.php contract/query count → callers → smoke
AI freshness | file history of each AI document within its declared source scope