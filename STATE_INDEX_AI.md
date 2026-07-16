# STATE_INDEX_AI.md

source_main_commit: eaae58b
source_state_blob: 7d4bb12
rebuild_type: full
source_scope: STATE.md, current PostgreSQL code, accepted live milestones and HANDOFF_2026-07-16T18-00.md
format: date | tags | decision/status | ref
freshness_rule: verify STATE.md and relevant code histories after this baseline; do not trust this header alone

## Architectural decisions and completed milestones

07-01 | configurator, model | Data-model changes belong to the native configurator; direct DB editing is outside the normal design path | STATE.md architectural rule
07-01 | diagnosis, debt | Two primary problem classes: insufficient kernel and incorrectly described models; not ordinary accumulated technical debt | STATE.md diagnosis
07-01 | snapshot, runtime | Runtime uses one compiled snapshot path; live introspection is restricted to bootstrap, explicit rebuild and admin contour | ARCHITECTURE.md §8
07-04 | registry, labels | Address space split into `model_registry` and 1:1 `model_labels`; service tables use `model_*` prefix | STATE.md a0; ARCHITECTURE.md §17
07-05 | calc, spike | Sibling-row computation proved possible; original hard-coded calc implementation was quarantined as concept-only | historical entities.php quarantine
07-07 | voc, resolver | Conventional dictionary resolver accepted as temporary mechanism; fail-fast used under configurator guarantees, local degradation deferred | STATE.md journal 07-07
07-08 | dictionaries, templates | Simple and composite dictionary labels converge on one compiled plan/executor | STATE.md a2; lookup_labels
07-08 | template, formula | Label-template and arithmetic-formula syntax remain different semantic consumers | STATE.md journal 07-08/07-14
07-08 | relations | `dep_` is direct ownership; `rel_main` is broader root-dossier membership | snapshot_build_relations
07-09 | object_tree | Object card shows the full dependency tree without arbitrary depth limit | record_tree
07-10 | view, render | Lists, forms, schema cards and object maps use render-neutral builders; renderer only lays out prepared structures | STATE.md view-layer decisions
07-11 | registry, repair | Configurator diagnoses DB↔registry drift and offers explicit human repair operations | model_diagnose/configurator
07-11 | labels, dictionary | `data_name` and composite labels unified into one compiled self-label mechanism | lookup_labels/record_label
07-12 | link | Explicit `link_` target via `model_links` shipped; field-level projection remains deferred | STATE.md journal 07-12
07-12 | configurator, alter | Existing managed tables support add/drop entity fields with explicit data-loss confirmation | configurator add/drop field
07-13 | db, abstraction | Thin `db_select/db_execute` layer accepted; not a semantic query planner or general SQL builder | STATE.md journal 07-13
07-13 | query_plan | Future selection/report abstraction must emerge from real reusable cases | STATE.md query-plan decision

## AI collaboration and document trust

07-14 | AI context | Compact protocol accepted: function passports + architecture snapshot + state index + timestamped handoff | AI_CONTEXT_PROTOCOL.md
07-14 | delegation | Chat handles bounded documentation/mechanical work; Claude+Vlad own architectural decisions | AI_CONTEXT_PROTOCOL.md
07-14 | trust, freshness | AI-document freshness is scoped to its source files, not every repository commit | STATE.md journal
07-14 | trust, git | Real file history is the freshness baseline; self-reported generated_at/source commit is only a hint | accepted workflow
07-14 | handoff | Timestamped HANDOFF files are operational transfer artifacts | AI_CONTEXT_PROTOCOL.md
07-14 | trust, line_refs | Passport `✓` requires exact range, blame/provenance and caller lines; known connector offsets must be checked independently, not avoided by removing line numbers | reviews after 6268f46/245768d/723b84b/ec16cfb

## 2026-07-14 implementation closure

07-14 | db, wrapper | MySQL-era calls were first consolidated behind `db_select/db_execute`; this later enabled the PostgreSQL cutover without rewriting caller contracts | commits 40e40a0..2c15076
07-14 | delegation, accepted | First delegated Chat code task accepted after independent diff and live smoke verification | STATE.md journal; commit 9726521
07-14 | db, boundary | Mechanical DB execution and future semantic record-selection plans are separate layers | STATE.md journal; commit 51b9848
07-14 | Sheet2, acceptance | Sheet2 data entry closed; live well card matched reconstructed source | STATE.md item 7; commit 5ef443b
07-14 | reparent | Reparent implemented as a separate protected operation, not ordinary edit | commit ebc51d3
07-14 | reparent, smoke | Server smoke for relation resolution and reparent contour confirmed green | commits 98b30b1, 584c294, 055157b
07-14 | CSS, presentation | Shared admin CSS moved to `style.css`; `render_admin_styles()` returns one link | commit da20150
07-14 | groups, reports | Global field grouping/weights rejected; importance belongs to report/presentation context | commit 4a46e04
07-14 | calc, decision | `math_` rejected as general statistics/report mechanism; `calc_` retained for row-local fields | commit 62b1dc0
07-14 | calc, production | Metadata formula parser/compiler/evaluator and production calc handler shipped | commit 5b1a737
07-14 | calc, live | Formula verified live on `cementing_interval.calc_volume_deviation` | commit 665f19a

## 2026-07-15 configurator and bulk import

07-15 | calc, configurator | Existing-table field editor accepts calc formulas, reuses `formula_parse`, checks table-scoped variables and registers `model_formulas` | commit a99c9e0
07-15 | import, reverse_lookup | `lookup_id_by_label()` added as exact reverse of compiled dictionary labels | commit ebf8e7e
07-15 | import, format | `BULK_IMPORT_FORMAT.md` defines hierarchical JSON with human dictionary/link labels | commit 3d1af2c
07-15 | import, CLI | `tools_bulk_import.php` added with split/resolve/parent/recursive-insert functions | commit b3c8cd9
07-15 | import, architecture | Importer is an adapter over snapshot, compiled dictionaries and `record_save`; no parallel write path | tools_bulk_import.php
07-15 | import, hierarchy | Successful parent insert ids feed trusted child `dep_` values; failed branches stop | bulk_import_insert
07-15 | import, ambiguity | Fuzzy/first-match label lookup forbidden; duplicates produce explicit failure | lookup_id_by_label

## 2026-07-16 PostgreSQL cutover

07-16 | DB, decision | Full MySQL→PostgreSQL cutover chosen; no dual-driver mode | STATE.md «Сейчас» p.9; HANDOFF_2026-07-16T18-00.md
07-16 | DB, connection | `db_connect`/`db_close` became the single connection lifecycle used by web and CLI entrypoints | commit 1fd71af
07-16 | DB, placeholders | `db_placeholders()` preserves caller `?` SQL while converting to PostgreSQL positional parameters | commit 1fd71af
07-16 | DB, select | `db_select()` now uses pg_query/pg_query_params; `$types` letters are compatibility-only and ignored semantically | commit 1fd71af
07-16 | DB, execute | `db_execute()['id']` is populated only for explicit `RETURNING`; without it id is 0 | commit 1fd71af
07-16 | schema, introspection | `snapshot_build_structure()` moved from SHOW TABLES/COLUMNS to pg_catalog + information_schema + pg_index | commit 1fd71af
07-16 | write, returning | `record_save()` uses PostgreSQL identifiers and `INSERT ... RETURNING id` | commit 1fd71af
07-16 | configurator, DDL | Identity keys, PostgreSQL types and removal of ENGINE/CHARSET/backticks shipped; metadata inserts needing ids use RETURNING | commit 1fd71af
07-16 | labels, upsert | Label save moved from ON DUPLICATE KEY to PostgreSQL ON CONFLICT | commit 1fd71af
07-16 | entity, boolean | `bul` storage changed from MySQL tinyint(1) to PostgreSQL smallint; boolean deliberately rejected because handlers consume numeric 0/1 | commit 05e6711
07-16 | schema, active | `model_registry.active` kept numeric/smallint rather than PostgreSQL boolean to preserve integer consumer semantics | commit ec5fc45
07-16 | import, transaction | Bulk-import dry-run changed to PostgreSQL BEGIN/ROLLBACK | commit bee896c
07-16 | DB, live | PostgreSQL step 1 connection/schema/DDL path confirmed live; diagnosed active boolean mismatch was fixed | commits aa1c218, 35e5213
07-16 | entity, live | PostgreSQL entity-type step confirmed live, including bul_ behavior | commit f71070d
07-16 | model, TZ2 | Full Sheet1–4 reference model built on PostgreSQL; deferred m2m classification remains explicit | commits db1016e, 12fe81d
07-16 | import, live | Real ТЗ-2 dictionaries and hierarchy loaded with zero importer failures (20/20 + 32/32) | commits 0a05156, b61a5fa
07-16 | acceptance, visual | Well object tree visually matched source across stages, intervals, materials, buffers, reagents, lab tests and АКЦ; calc deviations remained correct | STATE.md p.9 live verification
07-16 | testing, limitation | Full legacy `smoke_test.php` was not run in the migration session because it still contains MySQL-specific fixtures/queries; live browser/import verification is positive but not a substitute for future automated smoke adaptation | HANDOFF verification

## Current architectural status

current | kernel | Entity registry, trusted field package, compiled snapshot, generic CRUD/views, dictionaries/links, relation tree, protected reparent and row-local formulas are present | current main
current | DB | PostgreSQL is the sole current DB target; connection/execution is centralized in `db.php`; caller SQL remains explicit | current main
current | presentation | HTML is isolated in render.php; shared CSS is static in style.css | render.php/style.css
current | model | Registry, labels/templates, links and formulas compile into snapshot; structural drift has diagnosis/repair | core/configurator
current | import | Hierarchical importer is operational and loaded the complete ТЗ-2 reference dataset successfully | tools_bulk_import.php + STATE.md
current | testing | Live PostgreSQL application/import/visual checks are successful; legacy smoke requires PostgreSQL adaptation before claiming full automated green | HANDOFF/STATE.md
current | AI docs | Function passports updated for PostgreSQL in commit 17d249f; architecture snapshot rebuilt in commit 29f5df2; this state index is the matching decision summary | current documentation commits

## Deferred work and open questions

deferred | testing | Port MySQL-specific smoke fixtures/queries to PostgreSQL and run the complete automated suite | HANDOFF verification
deferred | query_plan, reports | Build semantic selection/report layer only from demonstrated reusable cases | STATE.md journal
deferred | m2m | Many-to-many relations with payload remain unimplemented/deferred | roadmap
deferred | snapshot | Explicit snapshot format version and migration policy remain open | architecture risk
deferred | resilience | Local degradation for isolated model faults remains strategic, not implemented | STATE.md notes
deferred | DDL | Add explicit transaction/compensation or operation journal for multi-step configurator changes | architecture risk
deferred | cleanup | Verify/design automatic cleanup of `model_links` and formulas on field/table deletion | architecture risk
deferred | auth | Role/permission model should replace file-level admin separation | roadmap
deferred | identity | Global field-name identity may need full table+field address in more subsystems | architecture risk
deferred | import | Define operational reporting/retry behavior and dictionary-label uniqueness/disambiguation policy | importer roadmap

## Fast lookup by task

DB layer | db.php → entrypoint connection lifecycle → caller SQL/RETURNING → error contract
entity | entities.php passport/handler → field_data → renderer/configurator → tests
snapshot | snapshot_validate/build_structure/build/save/load/init/refresh + consumers
formula | formula_parse → snapshot_build_formulas → field_data.formula → formula_eval/calc_handler → configurator/tests
relation | snapshot_build_relations → record_children/tree → render_object_tree
reparent | record_parent_relation → record_reparent/candidates/view → index → render_reparent_form
bulk import | BULK_IMPORT_FORMAT.md → lookup_id_by_label → bulk_import_* → record_save → PostgreSQL transaction
PostgreSQL migration | STATE.md «Сейчас» p.9 → commit 1fd71af → type fixes → live ТЗ-2 acceptance
AI freshness | file history of each AI document and its declared source scope; exact passport line ranges require independent verification