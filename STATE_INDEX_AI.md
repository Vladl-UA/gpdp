# STATE_INDEX_AI.md

generated_at_commit: ddcdebe
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
