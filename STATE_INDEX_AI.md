# STATE_INDEX_AI.md

source_main_commit: 3d1af2c
source_state_blob: bfc03f8
rebuild_type: full
source_scope: STATE.md plus accepted code milestones visible in current main
format: date | tags | decision/status | ref
freshness_rule: verify STATE.md and relevant code histories after this baseline; do not trust this header alone

## Architectural decisions and completed milestones

07-01 | configurator, model | Data-model changes belong to the native configurator; direct DB editing is outside the normal design path | STATE.md architectural rule
07-01 | diagnosis, debt | Two primary problem classes: insufficient kernel and incorrectly described models; not ordinary accumulated technical debt | STATE.md diagnosis
07-01 | snapshot, runtime | Runtime uses one compiled snapshot path; live introspection is restricted to bootstrap, explicit rebuild and admin contour | ARCHITECTURE.md §8
07-04 | registry, labels | Address space split into `model_registry` and 1:1 `model_labels`; service tables use `model_*` prefix | STATE.md a0; ARCHITECTURE.md §17
07-05 | calc, spike | Sibling-row computation proved technically possible; original hard-coded calc implementation was quarantined as concept-only | historical entities.php quarantine
07-07 | voc, resolver | Conventional dictionary resolver accepted as temporary mechanism; fail-fast used under configurator guarantees, local degradation deferred | STATE.md journal 07-07
07-08 | dictionaries, templates | Composite labels compile from table templates; simple and composite dictionaries converge on one plan/executor | STATE.md a2; lookup_labels
07-08 | template, formula | Label-template syntax and arithmetic formula syntax may share visual tokens but remain different semantic consumers | STATE.md journal 07-08/07-14
07-08 | relations | `dep_` is the direct ownership edge; `rel_main` is broader root-dossier membership | snapshot_build_relations
07-09 | object_tree | Object card shows the full dependency tree without arbitrary depth limit | record_tree
07-10 | view, render | Lists, forms, schema cards and object maps use render-neutral builders; renderer only lays out prepared structures | STATE.md view-layer decisions
07-11 | registry, repair | Configurator diagnoses DB↔registry drift and offers explicit human repair operations | model_diagnose/configurator repair contour
07-11 | labels, dictionary | `data_name` and composite labels unified into one compiled self-label mechanism | lookup_labels/record_label
07-12 | link | Explicit `link_` target via `model_links` shipped; field-level projection remains deferred | STATE.md journal 07-12
07-12 | boolean | `bul` entity shipped as checkbox/TINYINT(1); renderer hidden-zero safeguard is required | ent_bul/bul_handler/render_input
07-12 | configurator, alter | Existing managed tables support add/drop entity fields with explicit data-loss confirmation | configurator add/drop field
07-13 | db, abstraction | Thin `db_select/db_execute` layer accepted; it is not a dialect builder or semantic query planner | STATE.md journal 07-13
07-13 | query_plan | Future selection/report abstraction must emerge from real reusable forms: filters, relations, projections, aggregates, trees, m2m and reports | STATE.md query-plan decision

## AI collaboration and document trust

07-14 | AI context | Compact collaboration protocol accepted: function passports + architecture snapshot + state index + timestamped HANDOFF | AI_CONTEXT_PROTOCOL.md
07-14 | delegation | Chat handles mechanical/documentation work; Claude+Vlad own architectural decisions; other models may prepare bounded bulk artifacts | AI_CONTEXT_PROTOCOL.md
07-14 | trust, freshness | AI-document freshness is scoped to its source files, not every repository commit | STATE.md journal; commit 46dc044
07-14 | trust, git | Real file history (`git log -1 -- <document>`) is the freshness baseline; self-reported generated_at is only a hint | commits f9dcd36, 3f0f535
07-14 | handoff | HANDOFF became an operational artifact; timestamped filenames allow several transfers per day | commits fc01ed7, b080e57, b1d1114
07-14 | trust, line_refs | GitHub connector line reads showed repeatable offset artifacts, especially render.php (+2) and smoke-test calls via prior assignment (+1); stable declaration anchors are safer for regenerated passports | accepted review after commits 6268f46/245768d/723b84b

## 2026-07-14 implementation closure

07-14 | db, complete | `db.php` abstraction migration completed across core, configurator, helpers and labels; direct query mysqli remains only for declared connection/schema-introspection exceptions | STATE.md item 0; commits 40e40a0..a1c8144, afa0cab, 2c15076
07-14 | delegation, accepted | First real delegated Chat code task accepted after independent diff and live smoke verification | STATE.md journal; commit 9726521
07-14 | db, boundary | Mechanical DB execution and future semantic record-selection plans are separate layers; do not predesign unused query forms | STATE.md journal; commit 51b9848
07-14 | Sheet2, acceptance | Sheet2 data entry closed completely; final live well card matched reconstructed source | STATE.md item 7; commit 5ef443b
07-14 | reparent | Reparent implemented as a separate protected operation, not ordinary edit: resolve unique dep_ from snapshot, validate record/parent, update one FK, prepare/render dedicated form | commit ebc51d3
07-14 | reparent, smoke | Server smoke for dep_ relation resolution and reparent contour confirmed green; STATE item closed | commits 98b30b1, 584c294, 055157b
07-14 | view, object_tree | Tree nodes gained `reparentable`; renderer shows ⇄ only where the model has one unambiguous parent relation | record_tree/render_object_tree
07-14 | CSS, presentation | Shared admin CSS moved from PHP heredoc to external `style.css`; `render_admin_styles()` now returns one stylesheet link | commit da20150
07-14 | groups, reports | Proposed global field grouping/weights model rejected before code because the same field may have different importance in different reports; grouping belongs to report/presentation context | commit 4a46e04
07-14 | calc, decision | `math_` rejected as a general statistics/report mechanism; `calc_` retained for row-local computed fields with metadata formula plans | commit 62b1dc0
07-14 | calc, production | Quarantined calc spike replaced: `formula_parse`, `snapshot_build_formulas`, table-scoped whitelist, `formula_eval`, production `calc_handler`, formula section in snapshot | commit 5b1a737
07-14 | calc, live | Formula subsystem verified live on `cementing_interval.calc_volume_deviation`; calc item closed | commit 665f19a

## 2026-07-15 bulk-import contour

07-15 | import, reverse_lookup | `lookup_id_by_label()` added as exact reverse of compiled dictionary labels; absent and duplicate labels return explicit errors | commit ebf8e7e
07-15 | import, format | `BULK_IMPORT_FORMAT.md` defines hierarchical JSON: entity fields plus nested child-table arrays; dictionary/link values use human labels, not internal ids | commit 3d1af2c
07-15 | import, CLI | `tools_bulk_import.php` added with `bulk_import_dep_field`, `bulk_import_split_record`, `bulk_import_resolve_fields`, `bulk_import_insert` | commit b3c8cd9
07-15 | import, architecture | Importer is an adapter over `snapshot_init`, compiled dictionaries and `record_save`; it does not introduce a parallel validation/write pipeline | tools_bulk_import.php
07-15 | import, hierarchy | Parent ids are obtained from actual successful inserts; nested children receive trusted dep_ structural values and branch insertion stops after parent failure | bulk_import_insert
07-15 | import, dry_run | `--dry-run` executes the real importer inside a DB transaction and rolls back; InnoDB auto-increment gaps after rollback are explicitly accepted/documented | tools_bulk_import.php header and transaction branch
07-15 | import, ambiguity | Exact label matching is intentional; fuzzy/first-match lookup is forbidden because duplicate human labels must not silently bind to arbitrary ids | lookup_id_by_label

## Current architectural status

current | kernel | Generic entity registry, trusted field package, compiled snapshot, generic CRUD/views, dictionary/link resolution, relation tree, reparent and row-local formulas are present in main | current main
current | DB | Thin DB-call abstraction is complete within its declared scope; no broader portability layer has been approved | STATE.md item 0
current | presentation | HTML is isolated in render.php; shared CSS is static in style.css | render.php/style.css
current | model | Registry, labels/templates, explicit links and formulas are compiled into snapshot; structural drift has explicit diagnosis/repair | core/configurator
current | testing | Core CRUD, dictionaries, relations/reparent and production calc have live green verification; bulk importer needs its own completion/acceptance path beyond code presence | STATE.md + smoke history
current | AI docs | All three compact AI documents were fully rebuilt after bulk-import arrival; function passports use declaration anchors to avoid known connector line-offset errors | commits ec16cfb, fd4edae, this commit

## Deferred work and open questions

deferred | query_plan, reports | Build a semantic selection/report layer only from demonstrated reusable cases; do not store executable SQL in model metadata | STATE.md journal
 deferred | m2m | Many-to-many relations with payload remain unimplemented | roadmap
 deferred | snapshot | Explicit snapshot format version and migration policy remain open | architecture risk
 deferred | resilience | Local degradation for isolated model faults instead of whole-model unavailability remains strategic, not implemented | STATE.md dictionary/formula notes
 deferred | DDL | MySQL DDL compensation/operation journal remains open | configurator risk
 deferred | cleanup | Automatic cleanup of `model_links`/formula metadata on field/table deletion needs verification/design | architecture risk
 deferred | auth | Role/permission model should eventually replace file-level admin access separation | roadmap
 deferred | identity | Global field-name identity may need full table+field address in more subsystems | architecture risk
 deferred | import | Complete bulk-import steps, run live acceptance, define operational reporting/retry behavior and decide label-uniqueness policy | current bulk-import contour

## Fast lookup by task

entity | entities.php passport/handler → field_data → renderer widget → configurator parser → smoke
snapshot | snapshot_validate/build/save/load/init/refresh + changed model section consumers
formula | formula_parse → snapshot_build_formulas → field_data.formula → formula_eval/calc_handler → model refresh/tests
relation | snapshot_build_relations → record_children/tree → render_object_tree
reparent | record_parent_relation → record_reparent/candidates/view → index branches → render_reparent_form
bulk_import | BULK_IMPORT_FORMAT.md → lookup_id_by_label → four bulk_import_* functions → record_save → transaction behavior
DB audit | db.php contract → callers → declared mysqli exceptions
AI freshness | file history of each AI document and its source scope; do not compare unrelated commits mechanically
