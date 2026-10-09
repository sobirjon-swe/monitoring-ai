# BA clarification data dictionary — E05-02

All project records carry tenant_id/project_id, scoped uniqueness/FKs and FORCE RLS. Runtime monitoring_ai_app is a nonowner, nonSUPERUSER, nonBYPASSRLS role. Source/customer records are synthetic. [Contract](ba-evidence-v1.md), [schema](ba-evidence-v1.schema.json), [approved design](../superpowers/specs/2026-10-07-e05-ba-clarification-design.md).

| Table | Meaning and mutation rule |
| --- | --- |
| ba_receipts | Immutable typed source/human envelope, hash, actor/proof, exact output identities and server received_at; durable success boundary. |
| ba_source_heads | Scoped mutable CAS head per source/family/object; no arrival-time fallback. |
| ba_request_bindings | Scoped idempotency/alias to canonical receipt; current/original authority precedes replay/alias. |
| ba_source_party_versions | Immutable registered synthetic party/reference/kind/label and predecessor; source identity, no user account or customer verification. |
| ba_clarifications | Mutable aggregate projection: revision, state, exact current request/answer/review/event IDs; original_requested_at retained. |
| ba_request_versions | Immutable request revision/episode/question/requester/coordinator/owner/time/hash/link-hash/proof; requester/non-null employee owner original membership pins preserved. |
| ba_request_links | Immutable1–20 exact task/criterion-version association tuples, relation blocks/context, pinned requirement version/hash. |
| ba_answer_versions | Immutable same-source series record/correct/retract, author party, answer/reason/response_at, request/link pins, predecessor/hash/proof. |
| ba_reviews | Immutable PM confirm/reject/revoke exact request/answer/link hashes and disposition/reason/proof; distinct employee confirmation. |
| ba_events | Immutable aggregate sequence/business state, action receipt/request/review, context/reason; subject_answer_version_id identifies recorded source subject separately from projected answer_version_id. |
| ba_scope_obligations | Immutable first scope_change_required origin request/answer/link/review provenance. No clearance/update API. |
| ba_terminal_applicability | Monotonic proven observed original invalidity for exact subject/proof; never generated from coverage overflow/current-viewer denial. |

Business states open/answered/resolved/withdrawn and applicability current/stale/unknown/revoked are independent. answer_review_status, dependency_status, scope_status and scope_origin_coverage remain distinct. not_requested is not approval. Recorded response/customer dependency does not determine task acceptance, productivity or worked hours.

Authority proof version ba-authority-v1 pins exact original actor→employee mapping, membership/grant/policy/BA or PM role and source/connector intervals; proof.request_subjects pins requester/internal owner membership; coordinator metadata pins BA membership/role at requested_at. Replacing a row never repairs old provenance. Proof payloads remain internal, not JSON API read fields. Event-time task responsibilities capture exact immutable versions and precise successor time bounds.

occurred_at/source_updated_at/received_at/requested_at/response_at are different UTC timestamps with microsecond storage. Queue returns elapsed_age/elapsed_seconds or null; business_age remains null/calendar_unavailable. Labels synthetic=true, ai_interpretation=unavailable and customer_authenticity=not_verified remain present even after human confirmation.

## Source capability boundary

| Capability | Current boundary |
| --- | --- |
| Manual synthetic-v1 source | Existing explicit source instance + active project connector + scoped source grant; accepted typed envelopes. |
| Party provisioning | Guarded local/testing CLI with existing token file and fixture.manage/source purpose; no HTTP party admin/self-grant path. |
| Real tracker/customer/webhook/attendance | Not enabled or verified by this module; no live calls or external writes. |
| LLM/embedding/reranking/translation | Unavailable; no product AI call or cloud fallback. Local-only future provisioning requires separate approval. |
| Calendar/capacity/notifications/export/RAG | Outside approved slice; no business-hour inference or delivery bypass. |

Migrations29–33 are additive foundation, lineage, typed table dispatch, receipt/event boundary and answer subject separation. Applied migration files remain unchanged. Recovery uses retry/authorized exact replay, never history deletion or destructive down/reset.
