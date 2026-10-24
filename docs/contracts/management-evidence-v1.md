# Management evidence v1 — synthetic local backend

Approved E05-03 diagram SHA256 `b24d27d90b482b6dc534c30e4fadd7c8992f77c71e526efcd603f99ffbb77334`; requirements FR-090/100/200/220, E05-BE/DATA/QA and scoped AC-07/09/10/11/14. [Design](../superpowers/specs/2026-10-09-e05-management-support-design.md), [JSON Schema](management-evidence-v1.schema.json), [OpenAPI](../api/openapi.yaml), [dictionary](management-data-dictionary.md).

The API is local/testing only and uses existing loopback bearer authentication. Tenant and actor are server controlled. A management-specific global guard validates local configuration before bearer database access, then checks the actual marked restricted runtime. Paths receive the same single decode as Laravel routing. No remote inference, live source, external write, new identity/grant/source provisioning or source maintenance is exposed.

| HTTP path under `/api/v1/projects/{project}/management` | Body/operations | Controls |
| --- | --- | --- |
| POST `/actions` | source envelope, `action.open` | Idempotency-Key, Expected-Source-Revision |
| POST `/actions/{action}/events` | action.revise/reopen/withdraw, assignment.set, context.add | Idempotency-Key; expected_revision in body |
| POST `/actions/{action}/support` | source envelope, support.record/correct/retract/decline | both headers; body action_id matches route |
| POST `/actions/{action}/reviews` | outcome.confirm/reject/revoke | Idempotency-Key; expected_revision in body |
| GET/HEAD `/queue` | no body | limit, cursor, department_id query only |
| GET/HEAD `/history/{action}` | no body | same query |

Source root is exactly schema_version, operation, source_instance_id, object_id, source_revision, occurred_at, source_updated_at, body. Schema version is `management-evidence-v1`. Human roots have operation and their closed operation-specific fields. Every body definition is published under the same `$defs` name; the standalone envelope also binds operation to its exact body. Its absolute schema identity is `urn:monitoring-ai:management-evidence-v1`; definition names and wire version are unchanged. Runtime preserves JSON object/list identity through schema selection.

Raw POST maximum65536 UTF8 bytes/depth16; text/context4000bytes, reason2000bytes, object_id200bytes. Duplicate decoded JSON keys (including escaped spellings), unknown/nested keys, invalid UTF8, forbidden controls, secret text, false integer counters and invalid calendar dates reject. Dates require real UTC `YYYY-MM-DDTHH:mm:ss.ffffffZ`. Schema string limits count characters; runtime independently enforces bytes and secrets. Idempotency-Key is exactly1–100 ASCII `[A-Za-z0-9._:-]`. Required headers have one canonical value; repeated/comma-joined/whitespace/signed/leading-zero counters reject. Decimal counters are bounded0..9007199254740991; positive source_revision is separate from source-head CAS and aggregate expected_revision. Stable content hashes include source identity/time/typed content but omit counter controls.

Write queries reject. Human operations reject source-head headers; all routes reject Expected-Series-Revision, Expected-Project-Generation and Expected-Revision headers. Reads reject all five controls and bodies. `limit` is canonical1–50/default20, cursor maximum4096bytes with signed bound format, department_id UUID; `department` is not an alias. Route UUIDs must be valid. GET and HEAD apply identical authority/privacy; every HEAD status has an empty body.

| Operation authority | Required roles/purposes in addition to project membership, source scope and lifecycle read |
| --- | --- |
| open/revise/reopen/withdraw | pm + management.action.manage |
| assignment.set | department_head + management.support.assign + management.department.manage for exact department |
| support record/correct/retract/decline | developer/qa/ba/pm/department_head + management.support.record and exact source/project scopes; actor employee exact owner/helper, decline by helper only |
| context.add | pm manage or authorized owner/helper recording purpose |
| outcome review | pm or authorized department_head + management.outcome.review; reviewer employee distinct from helper and recorder |
| queue/history | management.read + management.source.read; department query additionally management.department.read |
| local fixture wrapper | ordinary open authority AND management.fixture.manage, captured in the same final commit fence |

Department and project grants remain independent. Each referenced source requires its own read/source grants and original producer proofs. Names and two accounts for the same employee never authorize independence. All named subjects, historical assignments, source originals and current delivery access remain mandatory; missing private context hides the entire record before pagination/count/cursor.

Each action/support refset contains1–20 exact typed tuples: lifecycle, qa_handoff_event, qa_defect_event, qa_execution_version, ba_request_version, ba_answer_version or ba_review. Each includes actual task/criterion/version membership and blocks/context relation; duplicate subject+tuple or contradictory relation rejects. Requirement/version/hash and source closure are pinned. Source APIs/tables are read-only dependencies: no QA/BA observers, mutation, nested coordinators or fake authority markers. Only management facts/receipts/terminal observations are written.

Current state, support status, historical review, subject applicability, current outcome and underlying dependency are separate. New corrections/retractions/declines/gaps clear favorable projection; late facts remain historical. Management completed does not close an open QA handoff, failed QA execution, BA scope obligation or E03 criterion. Every DTO has synthetic:true and ai_interpretation:unavailable; BA customer_authenticity:not_verified. Age is elapsed UTC; business_age:null/calendar_unavailable. No productivity rankings or causal credit are computed.

New committed writes201, exact authorized replay200;401 unauthenticated,403 authority/privacy,404 malformed route or missing mutation subject,409 CAS/hash/state/cursor,413 raw oversize,422 strict contract/control,503 local/bounded mutation unavailable. Hidden/absent authorized history returns empty200. Error bodies contain generic code/message/request ID, never SQL/payload/token. Mutation overflow writes no fact; read overflow returns empty unknown/null count/no cursor.

Bounds charge before deduplication: B=max50 read roots/history events or max2 retained+prospective mutation boundaries; L<=40B and2000 with<=20 action/20 support refs per boundary. Management originals<=min(10B,400); source rows<=min(64L,4000), original source proofs<=min(32L,2300), named<=min(8B+8L,2000), responsibilities<=min(2L,1000), receipts<=min(16L,200); instances/connector heads<=200 each, original actors<=100. Per-ref closure<=64rows/32proofs/64steps. Categories are jointly enforced, not jointly guaranteed attainable: a retained BA-review-only B1/L2 specimen reaches25 named slots against24 and safely returns unknown/503. Complete directly relevant task evidence may produce a different admissible graph; no proof/cap is omitted or raised.

Immutable source receipts preallocate exact output IDs. Source natural identity and requester/operation/idempotency binding differ. RLS, composite semantic keys, immutable histories, final PostgreSQL clock and selective terminal observations remain enforced by the accepted domain. The inherited access store cannot reconstruct wholly unobserved revoke/restore; captured terminal invalidity remains durable. This is a synthetic backend contract, not full E05/AC-07, browser UAT or a production NFR result.
