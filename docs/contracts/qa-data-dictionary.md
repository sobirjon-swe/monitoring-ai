# E05-01 QA data dictionary v1

2026-10-06. Authoritative schema is reviewed increments000019–000028 and [qa-evidence-v1](qa-evidence-v1.md). These are separate E05 tables: existing E03 `qa_test_identities`, `qa_observations`, `qa_observation_heads` remain unchanged.

Every E05 row carries tenant/project UUID scope with composite same-scope foreign keys. IDs are UUIDs; revisions/ordinals/source counters/watermarks are bigint unless stated; review revisions/claim counts are integer; instants are timestamptz(6); proofs/responsibility/envelopes are jsonb; hashes are lowercase SHA256 varchar(64); logical keys varchar(100). Received/action time uses actual server DB clock; UTC microseconds persist, Asia/Tashkent is the display convention. Payload byte bounds are checked before SQL; JSONB's storage cap is not a transport allowance.

| Table | Immutable facts / bounded mutable head |
|---|---|
| `qa_receipts` | source_instance_id/object_id/source_revision/kind; canonical envelope/payload_hash; original actor/proof; occurred/source_updated/received instants; scoped natural uniqueness |
| `qa_request_keys` | requester/typed operation/key/hash → exactly one receipt/entry/plan/defect/handoff output; allowed operation/output and receipt-kind guards |
| `qa_source_heads` | scoped source/object identity; guarded increasing source_revision/revision/receipt pointer |
| `qa_build_versions` | logical identity/revision/hash/title, exact optional synthetic MR/base/head SHA; receipt-kind build; no live SHA resolution |
| `qa_environment_versions` | logical identity/revision/hash/title; receipt-kind environment |
| `qa_definition_identities` | stable test logical key + stable criterion; monotonic revision/current_definition pointer |
| `qa_definition_versions` | exact criterion version/receipt/body hash/title/steps/expected_result/type/release_critical boolean; only review_revision(integer)/current_review/review_state head mutable |
| `qa_project_state` | project PK; monotonic selection revision/current selection and maintenance watermark |
| `qa_plan_entry_snapshots` | exact baseline/policy/item/stable criterion/version, included boolean, exclusion reason, full content hash and creator proof |
| `qa_plan_entry_definitions` | entry/criterion/definition/exact definition_review_id/source/required boolean; semantic review/definition/entry lineage |
| `qa_plan_versions` | baseline/policy/logical identity/revision/content hash and creator proof; guarded review head only |
| `qa_plan_entries` | exact immutable plan → entry snapshot/criterion-version links; complete baseline partition validated by service |
| `qa_reviews` | exactly one typed definition/plan subject, immutable revision/decision/subject_hash/reason/actor/original proof/time |
| `qa_selection_events` | exact approved plan_review_id/plan/build/environment + revision/reason/actor/proof/time; current pointer lives in project state |
| `qa_execution_series` | exact source/criterion-version/definition/build/environment tuple; guarded next ordinal/current attempt/revision |
| `qa_execution_attempts` | execution logical identity/ordinal/series, same-series predecessor, separate historical retest origin and exact fix request; guarded current source revision/version |
| `qa_execution_versions` | attempt/series/exact typed pins, source revision/receipt, outcome passed/failed/blocked, content hash, event-time responsibility/occurred_at |
| `qa_defects` | initial report receipt/logical key/severity/reproducibility/reproduction/first affected pins; guarded event/revision/state/disposition/canonical head |
| `qa_defect_events` | exact parent/revision/type/state/disposition/affected criterion/test/build/environment/task, request/retest/selection/fix metadata/cause/reason/proof; scope updates append old/new history |
| `qa_handoffs` | initial receipt/task/criterion/execution/defect/linked handoff/type/event-time responsibility/opened_at; guarded state/revision/current event/employee owner |
| `qa_handoff_events` | parent/revision/type/state/explicit owner/typed evidence/reason/actor/proof/time; resolved is terminal except context |
| `qa_changes` | global bigint identity change_seq, QA or trusted lifecycle origin, exactly one typed subject, actor/input hash/proof; lifecycle_mutation_id or task_mutation_version_id typed origin outside subject count |
| `qa_applicability` | exactly one canonical change; bounded status current/stale/revoked/unknown/reason/change_seq/observed_at; terminal cannot return current |
| `qa_invalidations` | append-only exact change/input-hash/reason/actor/observed time; idempotent reason observation |
| `qa_outbox` | exactly one change/invalidation/coalesced catch-up; bounded pending/processing/retry/completed/dead_letter, integer attempts0..5, available_at, UUID lease token/60s expiry, original processing proof/hash and target watermark |

All25 tables use ENABLE/FORCE RLS and missing tenant context deny. Runtime `monitoring_ai_app` owns none, lacks SUPERUSER/BYPASSRLS, and cannot delete/truncate history. Immutable inserts and guarded pointer updates bind semantic lineage, receipt kind/content, exact review/head identities and original proofs, not merely same tenant/project. Owner-protecting triggers also forbid history rewrites. Down migrations refuse destructive removal; no cleanup bypass is shipped.

Review revision0 means designed definition/draft plan with no current review. Selection retains its **original** approved review UUID; an approved→revoked→approved head cannot revive old selection/entry pins. Append-only facts, immutable review snapshots and current pointers are distinct. Output CAS decision `id` is the appended review/event UUID; create defect/handoff `id` is the stable root UUID, so retain the create ID for later routes.

Project-only purposes: fixture.manage, plan.manage, execution.record, defect.review, handoff.manage, read, source.read, process (all `qa.`). Department-only `qa.department.read` additionally requires explicit department grant and current membership. Original authority proof is `qa-authority-v1`; current viewer proof is separate. Locks collect all required keyed original proofs once before callbacks, compare each proof separately, then actual final clock rolls back expiry. Same actor with distinct proofs is not collapsed to one favorable proof. Original actor≤100; owner refs≤100; department employee refs≤2000; overflow is explicit denied/unknown rather than favorable truncation.

Canonical lookup metadata/history never asserts current release acceptance. Current queue/verification recheck exact current sources/criterion/task/review/selection and durable terminal states before DTO; maintenance lag cannot revive invalid evidence. Denied own retained retry may persist observed original invalidity separately without returning IDs or inventing read permission. Wholly unobserved same-row revoke/restore cannot be reconstructed from current access rows. Retained synthetic independent-process fixtures are never deleted; restore/retention policy and fresh empty-database provisioning are separate unverified gates.
