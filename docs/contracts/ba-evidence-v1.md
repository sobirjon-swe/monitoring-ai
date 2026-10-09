# Synthetic BA clarification contract — E05-02

Approved diagram v1 SHA256 `2bd3cc426b93dccdadd0190507d2381b2188c65d096169b18eda64eb90d92592` and [written design](../superpowers/specs/2026-10-07-e05-ba-clarification-design.md). [JSON Schema](ba-evidence-v1.schema.json), [HTTP OpenAPI](../api/openapi.yaml), [dictionary](ba-data-dictionary.md), [local operations](../operations/local-ba-clarification.md). Traces FR-080/090/100/200/220, E05-BE/DATA/QA and scoped AC-07/08/09/10/11/14; full E05, AI, business calendars and production gates remain open.

Every response labels `synthetic:true`, `ai_interpretation:unavailable`, `customer_authenticity:not_verified`. A customer party is an explicitly registered synthetic source identity, never a fabricated employee/login or verified customer. BA never changes E03 scope/acceptance or QA/release readiness. No live source, inference, UI, notifications, export/RAG or finance path is added.

## Transport

Base `/api/v1/projects/{project}/ba`; inherited loopback local bearer authentication supplies trusted tenant/user. UUID routes are bounded. Roles require exact project membership, scoped purpose and source permissions; titles alone confer no access.

| Method/path | Body and controls | Required action role/purpose |
| --- | --- | --- |
| POST `/clarifications` | request.open source envelope; Idempotency-Key + Expected-Source-Revision | ba + ba.request.manage |
| POST `/clarifications/{clarification}/answers` | answer.record/correct/retract source envelope; same controls; body expected_revision | ba + ba.answer.record |
| POST `/clarifications/{clarification}/events` | request.revise/reopen/withdraw/context.add typed body; Idempotency-Key, body expected_revision | ba + ba.request.manage |
| POST `/clarifications/{clarification}/reviews` | review.confirm/reject/revoke; Idempotency-Key, exact request/answer/link hashes and body expected_revision | pm + ba.decision.confirm |
| GET/HEAD `/queue` | no body; limit/cursor/department only | ba.read + ba.source.read |
| GET/HEAD `/history/{clarification}` | same bodyless bounded query | same current read/source authority |

All writes require source authority. PM confirmation additionally requires a different employee identity from the recorder. Named department delivery adds department_head role, exact ba.department.read grant and every named person's effective department membership; project/source authority remains mandatory. The selected BA coordinator has an independently pinned effective same-project BA role.

New committed fact201; authorized exact replay200. Source Expected-Source-Revision is an ASCII canonical integer0..9007199254740991 **outside** the envelope hash. source_revision is a positive envelope counter; human expected_revision is aggregate CAS. Idempotency-Key uses `[A-Za-z0-9._:-]`,1–100 characters. Human routes reject Expected-Source-Revision. All routes reject inapplicable Idempotency-Key, Expected-Source-Revision, Expected-Series-Revision, Expected-Project-Generation and Expected-Revision controls. Normal HTTP headers remain supported.

Generic errors:401 unauthenticated,403 scope/purpose/source/role denied,404 malformed UUID route/absent scoped mutation subject or project,409 CAS/hash/state/cursor conflict,413 over65536 POST bytes,422 strict shape/control/query/text/secret rejection,503 unavailable local guard or bounded mutation graph. A missing/hidden history UUID returns an authorized empty200 page, avoiding an existence oracle. HEAD performs the same checks and returns an empty body for success and failure.

Strict POST root object≤65536 UTF-8 bytes/depth16. Reject duplicate decoded keys, unknown fields, object/array substitutions, bool/integer substitutions, control bytes and invalid UUID/precise UTC fields. question/answer/context≤4000bytes; reason≤2000; object_id/party_reference≤200; party label≤500. Required text is nonblank. Existing SecretGate scans all strings before persistence. Schema maxLength counts characters; runtime additionally enforces UTF-8 **byte** bounds, secret scanning and real calendar validity. The published schema independently chooses each operation's exact body; arbitrary/empty/wrong-operation bodies reject without invoking a service. answer.record predecessor must be null; answer.correct predecessor must be a UUID.

## Identity, replay and transitions

Source envelope fields: schema_version=ba-evidence-v1, operation, source_instance_id, object_id, source_revision, occurred_at, source_updated_at, typed body. All times use microsecond UTC; received_at is PostgreSQL server time. Source identity is tenant/project/source/family/object/revision; request identity additionally includes trusted requester, typed operation and key. Canonical SHA256 sorts object keys and exact link sets, preserving semantic array order. Same identity/hash replays without new fact/event/audit; conflicting hashes409. Alias binding cannot bypass current or original authority.

One immutable request holds1–20 exact `(task_version_id, criterion_id, criterion_version_id, relation)` links; actual E03 task/criterion association and approved requirement membership must match. Duplicate/contradictory pairs reject. Relations are blocks/context. Context links remain mandatory for whole-record privacy. Every recorded event has a monotonically increasing aggregate revision. Source heads and human state changes use separate CAS boundaries.

open→answered→resolved remains separate from applicability current/stale/unknown/revoked. Correct/retract/reject/revoke/revise/reopen invalidate prior favorable current state and preserve immutable history. Newer unavailable or rejected responses never fall back to an older favorable answer. scope_change_required records immutable origin obligation; later revise/relink/reopen/correct/retract/revoke or no_scope_change cannot clear it. This module has no scope-clearance adapter. no_scope_change without origin means scope_status=not_requested, never approved.

## Reads and reproducibility

Page default20/max50; cursor≤4096 bytes, scoped HMAC for tenant/current actor/project/resource/department/limit plus generation/history continuation revision. Whole-record current source/named/links/original proof filters precede LIMIT. Counts describe only delivered authorized items; unknown/withheld history has null visible_record_count and no continuation. Full visible pages can have continuation followed by an authorized empty page; cursor does not disclose hidden totals.

T3 uses two **sequential independent** bounded graphs: original invalidity observation and authorized delivery. Each separately obeys R≤50, L≤20R≤1000, original proof slots≤6R+2L≤2300, named slots≤7R+L≤1350, receipt refs≤4R≤200,100 original actors. Current caller/source authority is independently reserved; proofs are deduplicated only by identical saved keys, never by actor alone. Overflow yields unknown, never invented revocation. The maximum synthetic sample measured202 queries for small/large, delta0; its632239-byte response and query count are not production latency/NFR evidence.

Queue carries source timestamps, question/answer/request/hash/link pins, states, dependency/waiting_on, scope status/coverage, reason codes, responsibilities and elapsed_age/elapsed_seconds. business_age=null, business_age_reason=calendar_unavailable. Event-time task assignment uses precise `[start, successor)` intervals, never current-person substitution. Missing/future/inconsistent time produces unknown/null age. Waiting on customer is a dependency, never employee blame/inactivity/hours/ranking.

History exposes `subject_answer_version_id` for the recorded answer fact (including a late source response) separately from `answer_version_id`, the event projection. Its `answer` object describes the separately authorized recorded **subject**; those IDs may differ. Optional unsafe historical text/identities are withheld without poisoning fresh independently authorized evidence. Authority/proof keys and raw receipt envelopes are internal, not DTOs.

Exact original membership/grant/policy/role/source identities and validity intervals are rechecked with final PostgreSQL clock. Proven invalidity is observed monotonically on BA records only; restoring a row cannot erase an observation. Wholly unobserved revoke/restore of the same row remains an inherited access-store limitation. FORCE RLS and composite scoped FKs remain defense in depth; immutable histories deny mutation.
