# Management data dictionary

All management records use tenant_id/project_id and scoped UUID identities. Timestamps are timestamptz(6), UTC; hashes are SHA256. [Contract](management-evidence-v1.md) and [approved detailed design](../superpowers/specs/2026-10-09-e05-management-support-design.md) govern exact lineage and limits.

| Table | Meaning and mutation |
| --- | --- |
| management_project_state | Serialized monotonic generation; inserted management events/terminal observations assign it in PostgreSQL |
| management_receipts | Immutable source/human input, original authority and preallocated exact output IDs |
| management_request_bindings | Immutable requester/operation/key to receipt/hash; authorized replay aliases |
| management_source_heads | CAS source_instance/family/object_id → revision, receipt, completeness; support series binds one action/version/assignment |
| management_actions | Mutable current revision/event/version/assignment/support/review pointers and separate state/status/outcome |
| management_action_versions | Immutable episode, requester/owner, reason, occurred_at, action/references hashes and receipt/proof |
| management_assignments | Immutable exact action version/hash, owner/helper/department, actor/proof/receipt/time |
| management_support_versions | Immutable exact assignment/action pins, helper/recorder, evidence/text or reason, proposed outcome, predecessor/source series/time/hash |
| management_reviews | Immutable exact support/action/assignment/reference pins, confirm/reject/revoke and independent employee; revoke predecessor |
| management_events | Immutable aggregate revision/episode/state, exact receipt and separate subject_support/current projection pointers |
| management_reference_sets | Exactly one action-version or support parent, immutable refs hash |
| management_references | Ordinal, typed exact subject+task/criterion tuple, requirement/hash and source snapshot hash; allowlisted FK columns |
| management_terminal_applicability | Append-only exact subject/proof/reason invalidity; never modifies source terminal stores |

`occurred_at` is event time; `source_updated_at` is source update time; receipt/server time is ingestion/review time. Effective membership/role/assignment intervals govern event-time responsibility. Current membership cannot repair an earlier missing interval. Future/missing/inconsistent event time returns unknown responsibility.

Public records separate `applicability`, `current_applicability`, `current_outcome`, `review_status`, `subject_support`, `support`, `current_projection`, `reference_results` and `underlying_dependency_status`. Original proofs, tokens, private source content and internal envelopes never become DTO fields. `synthetic=true`, `ai_interpretation=unavailable`, `customer_authenticity=not_verified`, `business_age=null`. Whole-record privacy precedes visible counts and cursors. Unknown graph overflow has no favorable fallback. Approved foundation SQL34–39 is forward-only and remains unchanged.
