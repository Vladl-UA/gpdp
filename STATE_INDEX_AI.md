# STATE_INDEX_AI.md

generated_at_commit: 5ef443b
source_state_commit: 5ef443b
source_scope: STATE.md; freshness must be checked by file history, not this self-reported field
format: date | tags | decision | ref

07-01 | configurator, model | Data-model changes must go through native configurator; direct DB editing is outside normal design path | STATE.md:architectural rule
07-01 | diagnosis, debt | Two primary problem classes: wrong kernel and incorrectly described models; not ordinary accumulated technical debt | STATE.md:diagnosis
07-01 | snapshot, runtime | Runtime uses compiled snapshot; live introspection only at bootstrap, explicit rebuild and admin contour | ARCHITECTURE.md:§8
07-04 | model_registry, labels | Model address space split into model_registry + model_labels; system tables use model_* prefix | ARCHITECTURE.md:§17
07-05 | calc, entity | Sibling-row computation proved possible; ent_calc implementation quarantined, concept only | entities.php:calc quarantine
07-07 | voc_, resolver | Dictionary address resolver: convention first; fail-fast currently, local degradation deferred | STATE.md:07-07
07-08 | template, math_ | Shared template syntax may serve labels and formulas; semantic consumers remain separate | STATE.md:07-08
07-08 | dep_, rel_main | dep_ is direct ownership edge; rel_main is broader root-dossier membership | core.php:snapshot_build_relations
07-09 | object_tree | Object card renders full dependency depth; no arbitrary depth limit | core.php:record_tree
07-10 | view, render | List, object map and form use shared view builders; renderer only lays out structures | STATE.md:view layer
07-11 | registry, repair | Configurator diagnoses DB↔registry drift and provides explicit repair operations | configurator.php:diagnose
07-11 | dict, labels | Simple data_name dictionary and composite label unified into one compiled plan/executor | helpers.php:lookup_labels
07-12 | link_ | Idea A shipped: explicit link target in model_links; Idea B field-level projection deferred | STATE.md:07-12
07-12 | boolean | bul entity added: checkbox/TINYINT(1), hidden 0 safeguard required in renderer | entities.php:ent_bul
07-12 | configurator, ALTER | Existing tables support add/drop entity fields with data-loss confirmation | configurator.php:alter operations
07-13 | db, portability | Adopt thin db_select/db_execute wrapper; not SQL dialect builder and not record_select plan layer | STATE.md:07-13
07-13 | query_plan | Future domain selections must first become reusable forms: filter, relation, projection, aggregate, tree, m2m, report | STATE.md:query-plan decision
07-14 | db.php | db.php introduced; record/core/configurator migration started and advanced through configurator create_table | commits 40e40a0..a1c8144
07-14 | AI context | Compact context protocol accepted: passports + architecture snapshot + state index + HANDOFF | AI_CONTEXT_PROTOCOL.md:1-238
07-14 | delegation | Chat maintains compact docs/mechanical tasks; Claude+Vlad own architecture decisions; Gemini may do bulk templates | AI_CONTEXT_PROTOCOL.md:179-237
07-14 | trust, freshness | AI-document freshness is scoped to each document's source files, not commit..HEAD across the whole repository | STATE.md:journal 2026-07-14; commit 46dc044
07-14 | trust, git | Freshness baseline comes from real file history (`git log -1 -- <AI-document>`), not from its self-reported generated_at_commit field | STATE.md:journal 2026-07-14; commits f9dcd36, 3f0f535
07-14 | handoff | HANDOFF became an operational protocol artifact; filenames use `HANDOFF_YYYY-MM-DDTHH-MM.md` so several handoffs per day remain distinct | AI_CONTEXT_PROTOCOL.md:§4; commits fc01ed7, b080e57, b1d1114
07-14 | db, delegation | DB call-wrapper migration completed across core, configurator, helpers and labels; first delegated Chat task accepted after commit/diff/smoke verification | STATE.md:journal 2026-07-14; commit 9726521
07-14 | db, boundary | Low-level db_select/db_execute execution and future semantic record_select plans are separate layers; do not mix or predesign unused query forms | STATE.md:journal 2026-07-14; commit 51b9848
07-14 | Sheet2, acceptance | Sheet2 data entry closed completely after the final two buffers; full live well card matches the reconstructed source without discrepancies | STATE.md:current item 7; commit 5ef443b