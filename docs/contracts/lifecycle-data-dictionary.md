# E03 lifecycle data dictionary — v1

2026-10-06. Deterministic synthetic local slice; FR-030/080/150, specification §12, E03-BE/DATA/QA and AC-05/08/09 foundation. Full E03 and AC-06 forecasting are not delivered. HTTP transport is [OpenAPI](../api/openapi.yaml); payload schemas are `contracts/lifecycle-{requirement,task,evidence,baseline}-v1.schema.json`.

## Records and lineage

Every table below is tenant/project scoped with FORCE RLS, scoped uniqueness and composite foreign keys. Runtime is nonowner/nonSUPERUSER/nonBYPASSRLS. Transaction-local tenant context and project parent/state locks fence coordinated reads/writes. UUIDs identify records; server UTC timestamptz(6) records effective events. Global generation is a coordination CAS/watermark, not proof that every input changed.

| Tables | Role and mutability |
| --- | --- |
| requirements, criteria | Stable identities; runtime identity changes/deletion denied. |
| requirement_drafts | Mutable synthetic draft, revision CAS; no current acceptance effect. |
| requirement_versions, criterion_versions, requirement_version_criteria | Immutable approved history; joins bind requirement identity/version and criterion identity/version. Unchanged criterion fingerprint reuses its version. |
| dod_policy_versions | Immutable explicit fictional mandatory-QA/human-acceptance policy. Approval alone does not activate it. |
| lifecycle_project_state | Mutable generation, current baseline/policy pair and closure projection; composite current baseline/policy FK. Reads never create state. |
| lifecycle_tasks, task_versions, task_criterion_links | Current task pointer and immutable task/version/criterion pins; task status never accepts a criterion. Optional receipt is all-or-none and composite scoped. |
| task_dependency_events, task_dependencies | Immutable edge events and add-only current dependency projection; no removal API in this slice. |
| evidence_link_proposals, evidence_reviews, evidence_review_heads | Immutable receipt/hash/criterion/locator proposal, immutable review revisions and current review pointer. reviewed/rejected/revoked; proposal alone is insufficient. |
| qa_test_identities, qa_observations, qa_observation_heads | Scoped criterion/test identity, immutable synthetic passed/failed/missing observations and current revision pointer. Composite joins prevent cross-criterion links. |
| baselines, baseline_items | Immutable approved scope, policy and version/weight items. Explicit carry binds prior decision to same criterion version and policy, rather than to the new baseline identity. |
| human_acceptance_decisions | Immutable original baseline/item/policy/criterion, exact input pins/hash and original authority proof/hash. New inputs require a new decision. |
| acceptance_applicability | Mutable derived valid/stale/revoked projection; immutable decision/pins identity and nondecreasing generation. stale/revoked cannot return valid; revoked is terminal. |
| baseline_progress_snapshots | Immutable old/closed fraction, versions, coverage, schedule coverage and as_of. Current viewer access still gates delivery. |
| lifecycle_changes, lifecycle_outbox | Immutable change dependency IDs and durable mutable claim/lease/retry/completion state. Separate from E02 ingestion outbox. |

Runtime UPDATE/DELETE/TRUNCATE on immutable histories is denied by privileges/guards. JSON pins are validated synchronously against authorized current inputs; JSON equality is not a foreign-key guarantee. Migrations `000009`–`000014` include all corrective lineage/authority/dispatch migrations. `000012_strengthen_acceptance_authority` deliberately uses nullable legacy columns plus NOT VALID required-proof CHECK: new writes need proof, legacy null proof is unknown/unavailable, never fabricated. No immutable history was backfilled or deleted.

## Acceptance and arithmetic

Baseline activation requires explicit approved fictional policy, current approved criteria, unique stable criterion items and integer weights. Acceptance requires current baseline/policy, all mandatory latest QA passed, reviewed nonrevoked links, matching accepted immutable receipt history, exact pins and current original reviewer authority. Empty/missing required QA never passes. Locator allowlist is payload.title, payload.status, payload.external_ticket_id; no arbitrary URL/path or raw source response.

Exact numerator/denominator is authoritative: accepted item weight / total approved item weight. Display basis points = floor(accepted × 10000 / total), using integer arithmetic. 67/100 → 6700 (67.00%); explicit unchanged carry with scope120 → 67/120 → 5583 (55.83%), while old snapshot stays67/100. Zero scope or unavailable access yields null percentage. Status is available/partial/unavailable; current DTO contains status, accepted_weight, total_weight, percentage_basis_points, baseline_id, policy_id, coverage_reason_codes, previous_snapshot and schedule_coverage. Frozen DTO additionally has as_of; previous_snapshot uses frozen fraction fields. Schedule coverage is complete/limited with reason_codes, cycle_task_ids and missing_estimate_task_ids; no forecast date/probability.

Carry is explicit human baseline approval of unchanged live pins/authority; absence of carry contributes zero until new acceptance. Changed criterion/policy/link review/hash/QA and terminal applicability cannot reuse a decision. Draft edits and unused policy approval do not invalidate unchanged acceptance. Frozen snapshots preserve historical weight and are not rewritten by current invalidation.

Original authority pins selected user/employee/membership and exact acceptance.review/ingestion.read grant+policy identities/periods. Shared row locks fence delivery. Replacement grant cannot restore old authority. Existing mutable access tables cannot reconstruct revoke-and-restore of the same row wholly between observations; R5 retains revocations it actually observes. Valid cache never substitutes live checks.

## Bounds and worker contract

Synthetic assumptions: JSON64KiB/depth16; max100 criteria/items/tasks, max20 required tests, keys100/title500/context4000/reason500 characters, weights1..1000000 (denominator≤100000000); lists default20/max100 UUID cursor. Dependency traversal100nodes/1000edges. Source-history inspection bound100 explicitly produces unknown on overflow; it does not truncate into a favorable result. These are not production policy/capacity measurements.

Lifecycle CLI returns exactly claimed, completed, retry, dead_letter, blocked, stale integer counts. stale counts fenced delivery leases, not invalidated decisions. Claims use SKIP LOCKED, 60s UUID lease and five attempts; retry5/30/120/600 seconds, fifth failure dead_letter. blocked/access_revoked is not automatically redriven. Only fixed processing_failed/access_revoked/input_stale reasons, no payload/exception text. UUID chunks100 bound historical scan memory, not total work; projects exceeding lease duration safely retry/dead-letter. Worker recomputes current inputs, never creates human acceptance or revives stale decisions.

## HTTP DTO detail

Requirement list data is a plain array of id, requirement_id, version, draft_revision, content_hash, approved_at. Task list data is a plain array of id, revision, version_id, title, assignee_id, estimate_basis, remaining_effort, expected_test_keys, status, content_hash, effective_at. Cursor is the last returned id; no items/next_cursor wrapper is emitted. POST/PATCH acknowledgements use id/revision/hash/generation fields shown in OpenAPI; draft writes have no generation. Requirement approval criteria entries are id/key/version_id. Policy approval purpose is policy.approve; activation/closure is baseline.approve. Lifecycle read and process purposes require explicit project scope, while independent ingestion.read retains existing gateway scope semantics.
