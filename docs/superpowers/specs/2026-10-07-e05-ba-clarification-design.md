# E05-02 synthetic BA clarification and customer-decision design

2026-10-09 execution status: T1 foundation, T2 workflow and T3 authorized bounded read slices independently reviewed, including their scoped fixes. Task4 adds approved strict API/guarded synthetic party CLI and local evidence; dev29–33 applied incrementally and97assertion authenticated Laravel HTTP-kernel CRM rollback passed. Final focused/full-suite and root whole-unit/spec/security/quality review remain pending. This status does not alter approved scope/image or activate AI, live sources, UI, business calendars, E03 scope clearance or production delivery.

2026-10-07. **Written design execution authorized:** after presentation of this completed document, user said “Kodni yozishga ruxsat beraman, ishni davom et.” This supersedes the historical written-review waiting state and authorizes implementation within the approved scope, including routine plan preparation and specialist execution/review. Task branch `ba-clarification-tracking` starts from accepted E05-01 commit `21b7339c277f531eff73808548a1bd1b937ca6c1`. E05-02 commit, push, merge and deployment remain separate permissions.

## Approval, purpose and boundary

The user explicitly approved diagram v1 and **BA records → PM confirms** on 2026-10-07. Approved image: [E05-02 flow](../../design-mockups/e05-02-ba-clarification-flow-v1.png), 1600×1150, SHA256 `2bd3cc426b93dccdadd0190507d2381b2188c65d096169b18eda64eb90d92592`. Approved scope includes a dedicated BA module, one question with 1–20 exact task/criterion links, conservative whole-record visibility, elapsed UTC aging and unavailable AI. This approval permits preparing this written design, not bypassing its review or the plan gate.

Ivan records synthetic business questions/customer-attributed answers. An explicitly authorized PM confirms an exact current answer. John reads permitted unresolved decisions and task dependencies; Victoria reads a separately authorized BA department queue. Customer waiting is a declared dependency, never inferred employee inactivity, fault, worked hours or productivity. Resolution records an internally reviewed statement; it proves neither customer authenticity nor task/QA/release acceptance.

Selected technical proposals in this document make that scope implementable. Requiring different authenticated employee identities for answer recorder and PM confirmer is a **disclosed implementation assumption**, not a specification-mandated dual-approval policy. It is submitted with this design for approval. Verified existing role taxonomy is `developer`, `qa`, `ba`, `pm`, `department_head`, `reviewer` in migration `2026_10_03_000003_create_identity_access_and_projects.php:79`; `business_analyst`/`project_manager` are not existing codes. Selected synthetic mapping uses Ivan=`ba`, John=`pm`, Victoria=`department_head`. Recording requires current/effective `ba` assignment plus `ba.answer.record`; confirmation requires `pm` plus `ba.decision.confirm`, each with exact scoped grant/membership/source authority. Assignment/title alone never grants access. These action-to-role checks are proposed written-design details, not existing BA capability.

Included: synthetic-only Laravel/PostgreSQL records, immutable source/request/answer/review/event history, exact E03 references, bounded current queues/history, deterministic applicability and permission checks, safe local fixture registration and acceptance evidence. Excluded: live source/customer verification, external source writes, inference/model provisioning, UI/framework choice, calendars/capacity, general escalation/support, snapshots/reports/notifications/export/RAG, finance, release bridge and deployment. Existing QA contracts remain unchanged. BA never writes E03 requirement/task/baseline/acceptance records.

## Requirement trace and deferred requirements

Authoritative source is `docs/requirements/AI_monitoring_Texnik_spetsifikatsiya_v1_0.docx`, v1.0 dated2026-10-02, SHA256 `e13b83e55872a3497bcce4f08f2ac3cf32e0c02da484678079d9b30fd69901bf`. P locators are one-based all `w:p` paragraphs in `word/document.xml`, including blanks/table paragraphs.

| Trace | This slice and limit |
| --- | --- |
| FR-080 P203–209 | Immutable clarification/decision facts and exact requirement/criterion/task versions; separates deterministic traceability from absent AI quality. Customer dependency, human resolution and selective version staleness. Business-calendar aging and broad semantic impact analysis deferred. |
| FR-090 P214–215 | John's permitted unresolved decision/task dependencies and reasoned transitions; no full PM capacity/milestone forecast or reassignment productivity. |
| FR-100 P223–226 | Victoria's permitted BA queue; named-person department authority distinct from aggregate privilege. General support outcomes/capacity/trends deferred; proposed five-person privacy threshold not activated. |
| FR-200 P358; FR-220 P505 | Default-deny purpose/scope, durable transactional facts, independently bounded reads/writes; no claim of full production workload fairness. |
| E05-BE/DATA/QA P607–612 | Adds BA path on E03; complements accepted E05-01 without completing six roles, business-time projections, AI, OPS or AC-07. |
| AC-07/08 P662–663 | Ivan/John/Victoria synthetic trace and affected-current-link staleness/history; E03 scope approval remains its own path. |
| AC-09/10/11/14 P664–666/P669 | Current revocation/tenant isolation, explicit missing coverage, bounded untrusted text/locators and correction without erasure; no new RAG/export paths. |
| NFR-001/002/003/004/005 P456–457 | Pilot volume, availability, latency/freshness and recovery targets remain unmeasured production gates. Synthetic bounds and crash/transaction tests are not those measurements. |
| NFR-010 P555 | Request/correlation IDs and redacted audit/change records; no raw question/answer, secrets or traces in general logs. |
| DEC-02/03/04/05 P66 | Real calendars/DoD, source/mapping scopes, runtime/model choices and real named-person/privacy/notice/retention policy remain open. Synthetic grants/fixtures do not activate real data. |

User decisions local-only AI and local computer first remain mandatory; external inference/cloud fallback is prohibited. AI is explicitly `unavailable` in every BA DTO. Existing E04 parser limitations remain independent.

## Module and responsibility boundaries

Add a dedicated `Domain/Ba` module and versioned BA JSON contract; retain E03 as the only requirement/task version store. Reuse the proven transaction/tenant/access/strict transport/audit mechanisms, plus QA patterns for original authority proof, receipt identity, bounded graph and terminal applicability. Do not call QA-specific purpose/subject validators for BA or extract a speculative generic engine.

The data model distinguishes:

- **Authenticated recorder:** employee/user whose current grant authorizes recording a statement.
- **Requester:** exact employee identity requesting clarification; event-time proof retained.
- **BA coordinator:** explicit accountable internal employee for coordination, independent of task assignee.
- **Decision party:** declared `customer`, `internal` or `unknown`; this conveys dependency type, not blame.
- **Decision owner:** either exact internal employee reference, or a bounded synthetic source-party reference; never fabricate a customer employee account.
- **Attributed answer author:** exact source-party identity/reference, distinct from the recorder and confirmer. Synthetic fixture identity is not cryptographically verified; no customer/source calls occur.
- **PM confirmer:** independently authorized internal actor who reviews an exact answer/request boundary.
- **Event-time task assignee:** captured for each pinned task-version interval, never replaced by the current assignee.

Missing/ambiguous identity is stored as unknown coverage. Resolution requires an exact registered synthetic party reference and resolved event-time responsibility for every blocking link; a source label/name/email alone never creates identity mapping. `customer_authenticity="not_verified"` remains visible even after PM confirmation.

## State, events and current applicability

Business state and applicability are different. Business states are `open`, `answered`, `resolved`, `withdrawn`. Current applicability is `current`, `stale`, `unknown` or `revoked`; history retains the state reached at each event.

| Event | Allowed source state | Result and mandatory boundary |
| --- | --- | --- |
| request.open | creation | open; request revision1 and exact immutable links/owner/question |
| answer.record | open/answered | answered; pins current request revision/hash and links hash; source-series head CAS |
| answer.correct | answered/resolved | answered; exact same-series predecessor answer version, current request/links, new source revision; previous review cannot carry |
| answer.retract | answered/resolved | open; immutable retraction of current answer, no earlier-answer fallback |
| review.confirm | answered | resolved; current answer UUID/hash, request revision/hash and links hash, distinct PM employee, resolution reason and disposition |
| review.reject | answered | open; exact answer rejected with reason, no older fallback |
| review.revoke | resolved | answered; exact confirmation revoked, requires fresh confirmation |
| request.revise | open/answered/resolved | open; revised question, owner or links produces new request boundary; all former answers remain historical |
| request.reopen | resolved/withdrawn | open; explicit reason and new episode/request revision; prior resolution retained |
| request.withdraw | open/answered | withdrawn with reason; withdrawal is not favorable readiness |
| context.add | any | unchanged; bounded immutable context, cannot change pins/question/answer |

Every human event requires aggregate `expected_revision`; incompatible state/pins or stale CAS conflicts409. Owner changes use request.revise, preventing old reviewed response from silently following another accountable party. Reopen/revise cannot reuse a previous request's answer as current; record fresh evidence explicitly. Appending context does not supersede an unchanged response.

Current answer is the latest known source head for the active request. Missing revision/predecessor, retraction, unavailable head, revoked input or correction never falls back to an earlier answer. Source ordering follows explicit revisions and predecessor identity, not arrival timestamps. `resolved` is current only when every mandatory current input and original proof remains valid. Stale versions retain historical resolution but current dependency is unresolved/stale. Overflow is unknown, never recorded as revocation.

## Links, scope and honest readiness

One immutable request revision holds 1–20 unique tuples `(task_version_id, criterion_id, criterion_version_id, relation)` where relation is `blocks` or `context`. Validate the actual same-project E03 task-version→criterion-version association and exact requirement-version membership. Pin validated requirement version/hash as derived immutable data. Sort tuples by task UUID, criterion UUID/version UUID and relation before canonical hashing; duplicate identity pairs and contradictory relations reject. Independent decisions or different independently resolving owners use separate clarifications.

All links have exact applicability, even context links for privacy. Blocking links govern dependency; a stale/unknown blocking link prevents favorable current resolution. Link revision is explicit and invalidates response/review applicability. No automatic carry to newer task/criterion heads, even for reassignment; historical evidence and interval responsibility remain intact. Unchanged criteria outside the linked set are not invalidated by BA.

PM confirmation declares `no_scope_change` or `scope_change_required`. Both may resolve the business question; neither grants E03 approval. DTO distinguishes `clarification_state`, `answer_review_status`, `scope_status` and `dependency_status`, avoiding a single misleading ready flag.

For `no_scope_change` with no outstanding scope obligation on this root, scope_status is `not_requested`, never `approved`; dependency is `decision_resolved` only if exact linked inputs/proofs are current. An inherited unresolved scope obligation takes precedence as described below. This does not mean the task is ready, Done, accepted or releasable.

For `scope_change_required`, scope_status is `scope_approval_pending` while exact original inputs remain current, and `unknown` after a relevant scope transition or missing proof; dependency stays unresolved. No command, endpoint or evaluator in this slice can mark that scope obligation approved. Existing E03 users may approve scope through existing APIs, but newer heads, baseline/criterion row existence and a claimed approval ID cannot prove approval of this answer-specific change. A future separately designed bridge would need an exact human attestation tying the answer/request/links hashes to approved E03 inputs; that bridge is excluded here.

Once a confirmation declares `scope_change_required`, retain immutable obligation provenance (origin confirmation/request/answer/link hashes) on the clarification root. The unresolved obligation survives request revision, relinking, reopen, response correction/retraction and review revocation. A later `no_scope_change` confirmation cannot clear it, even after relinking to newer E03 heads. This deliberately conservative slice has no obligation-clearance path; withdrawal or a separately created question is not clearance of the original obligation. An unrelated fresh clarification with no such provenance can use normal `no_scope_change` resolution. Historical provenance cannot supply favorable authority; hidden or invalid provenance produces withheld/unknown output rather than an inferred clearance. This is a disclosed technical limit submitted with the written design, not a completed scope-readiness capability.

E03 mutations are observed synchronously on BA reads/replays/reviews by comparing current heads/approved membership. No BA invalidation hook is required in E03 write services. BA stores observed terminal invalidity only inside BA coordination; a BA observation failure cannot make an E03 write fail. No new durable maintenance queue is justified initially: SQL heads/live proofs remain authoritative, with bounded synchronous evaluation and retry by the caller. If implementation reveals a necessary independent maintenance workload, stop and amend this design/plan rather than adding an unapproved coupling.

## Persistence, isolation and transaction model

Append-only migration adds BA tables without resets/backfill of fictitious facts:

| Table group | Meaning |
| --- | --- |
| ba_receipts / ba_request_bindings / ba_source_heads | Immutable typed envelopes and hashes; scoped source-version identity/request idempotency; bounded mutable source heads with CAS. |
| ba_source_party_versions | Immutable scoped synthetic business-party identity/version/hash registered explicitly against its source; exact party-version FKs from owner/attributed author. No employee account, login permission, email/name merge or customer-authenticity claim. |
| ba_clarifications | UUID root, current aggregate revision/event/request projection and state; mutable projection only. |
| ba_request_versions / ba_request_links | Immutable question/owner/request boundary and validated exact links/hash; episode/timestamp/identity snapshots. |
| ba_answer_versions | Immutable attributed text, request/link pins, source receipt, same-series correction/retraction predecessor and timestamps. |
| ba_reviews / ba_events | Exact answer/request/link hash confirmation/rejection/revocation, reason, actor and original authority proof; immutable aggregate sequence. |
| ba_terminal_applicability | Monotonic observed terminal-invalid markers tied to exact subject/proof, never mutable restoration of evidence. |

All project tables include tenant_id/project_id and composite scoped uniqueness/FKs. FK links bind actual E03 task-criterion association/criterion membership where an existing scoped unique key permits; otherwise add a narrow non-destructive scoped unique key only after compatibility review, and retain explicit semantic guards. Same-scope IDs alone do not prove the association. Root/source/request/answer/review same-boundary references are enforced with composite keys plus checked service/DB lineage guards. No weak unchecked JSON-only reference.

Enable and FORCE RLS using existing tenant context; application/worker roles are not owners/SUPERUSER/BYPASSRLS. Protected immutable rows reject UPDATE/DELETE through application roles/immutability guards; mutable heads are scoped and CAS guarded. Administrative migration credentials remain separate and secrets ignored.

Under project serialization and deterministic lock ordering, authorize current actor and all mandatory source/link/named-person/original proofs; lock captured heads and verify unchanged; evaluate final PostgreSQL clock, then persist receipt/immutable fact/head/event/audit atomically. Only safe IDs/hash/reason codes enter audit/change metadata. Durable committed receipt precedes acknowledgement. Crash before commit leaves no success; after commit exact authorized replay returns same fact. No distributed exactly-once claim.

## Source identity, replay, original proofs and history

Envelope schema `ba-evidence-v1`: typed operation, existing registered manual synthetic source_instance_id, object_id, positive source_revision, strict microsecond UTC occurred_at/source_updated_at and typed body. Tenant/project/requester derive from authenticated route/context, never payload. received_at uses PostgreSQL server time. Human review/events are separate typed bodies; customer source identity grants no internal permission.

Natural identity is tenant/project/source/typed object/object revision. Request identity is tenant/project/requester/typed operation/idempotency key. Canonical SHA256 recursively sorts object keys, preserves semantic ordered arrays and uses normalized sorted link sets. Exact identity/hash replay returns200 and adds no fact/event/audit/change; conflicting hash returns409. Natural alias binding may add only the scoped binding after current authority/terminal checks. Source head Expected-Source-Revision, request source_revision and aggregate expected_revision are distinct counters. Answer correction source predecessor must match exact series/current head; gaps remain unknown and cannot auto-confirm.

Original proof records exact user/employee/membership/grant/policy and required role-assignment identities and validity intervals for requester/recorder/PM review and mandatory source/link responsibilities. A role-gated operation pins the actual role row; mandatory original BA/PM role authority is revalidated alongside current role/grant authority under the same lock and final PostgreSQL-clock fence. Removing, expiring or replacing that pinned role prevents favorable applicability despite an unchanged purpose grant. Replacement role/grant cannot repair the old proof; observed terminal invalidity survives restoring the same row. Revalidate each keyed proof independently, even when proofs share an actor. Distinct recorder/confirmer compares employee UUIDs: two user accounts for one employee do not satisfy it. The existing access store cannot reconstruct wholly unobserved same-row revoke/restore between checks; disclose this inherited limitation, never claim stronger temporal audit.

Historical previous answers/reviews are typed optional lineage. They do not support the fresh answer's current resolution and their revoked registrar proof must not poison fresh independently authorized evidence. Their text/identities can be delivered only when independently currently authorized; if unsafe to include them, history page coverage is unknown/withheld. Mandatory current answer/source/request/link/review proofs cannot be downgraded to optional. History is immutable, not an authority bypass.

## Authorization and bounded reads

Proposed project purposes: `ba.request.manage`, `ba.answer.record`, `ba.decision.confirm`, `ba.read`, `ba.source.read`; separate `ba.department.read` for named-person department view. Request open/revise/reopen/withdraw/context requires current `ba` role plus request.manage. Answer record/correct/retract requires `ba` plus answer.record. Every confirm/reject/revoke requires `pm` plus decision.confirm, including its original/current role proof; role labels alone never authorize. The selected BA coordinator must have an effective same-project BA assignment, independently of the task assignee. Fixture registration uses separately explicit local `ba.fixture.manage` plus source authority and grants no BA read/decision capability. Every write needs relevant source access; reads require ba.read and every linked source's current access. Department named-person delivery additionally requires the current department_head assignment and exact department grant/membership. No general new role is invented.

All named requester/coordinator/decision-owner employees, recorder/confirmer and per-link original task assignees and source-party facts must be visible before exposing shared root text, IDs, count or cursor. Victoria additionally needs explicit department scope and permitted department membership for all named employee facts; employee-less customer reference still requires source access, not invented department membership. All 1–20 links are checked. Hide the entire root if one is unauthorized; never expose a partial answer revealing hidden work. Uniform coverage reason avoids hidden actor/identity details. Filter authorization before LIMIT/count.

Capacity proposals distinguish **100 distinct authenticated authority actors** from independently keyed proof records. Never collapse different grant/role/review proofs by actor, and never impose a 100-reference or 100-proof-record ceiling. For an admitted page of R roots/events (R≤50), L exact link tuples≤20R≤1000. Bound each current-root metadata category (request, answer, review, boundary, scope-origin metadata) to R; task version/head/successor and criterion/requirement association categories each to L. Six mandatory BA action-purpose/source proof records per root plus two event-time proof records per link give P≤6R+2L≤2300 independently checked proof records; each selected proof has a fixed typed identity/role/grant field set, not recursive payload references. Named employee/party lookup slots are bounded separately by 7R+L≤1350, accounting for requester/coordinator/owner/request recorder/answer recorder/current reviewer/scope-origin reviewer; source-receipt references by 4R≤200, including current request/answer and bounded scope-origin provenance. Scope-origin metadata is historical provenance, not a favorable current proof. History pages apply the same per-event categories and do not recursively expand the entire episode lineage. These numbers are proposed admission accounting, not measured performance.

The implementation plan must enumerate actual typed categories against this recipe before code; exceeding a category's admitted shape rejects an operation or yields explicit unknown coverage, rather than silently materializing/truncating an unlimited graph. Deduplicate only identical immutable reference/proof keys, batch category lookups and role/authority checks, and measure that queries do not grow per root/link. Exact task head/successor joins replace full history scans. Overflow returns an empty unknown page with null count/cursor and coverage_limit, or a smaller independently complete legal page; no favorable subset, hidden count or terminal revocation is inferred. A legal maximum-link page sharing one actor must not fail merely because its independent proofs exceed100.

Queue returns permitted unresolved/stale/unknown decisions as well as answered awaiting review, not only comfortably current facts. Healthy authorized questions remain readable independently. Closed projects deny new facts/current dependency evaluation; authorized historical metadata may remain readable with current permission. Page cursor is opaque HMAC bound to tenant/requester/project/resource/department/query state; permissions and heads rechecked each page. No global hidden totals, omitted IDs or guessed denominator.

## Time, contract/API and local operations

Requested_at, response_at, occurred_at, source_updated_at and received_at remain distinct. Late sourced time can describe original task interval `[effective_at,next_version.effective_at)`; exact bounded successor lookup proves responsibility, otherwise unknown. Strict UTC storage; display timezone metadata Asia/Tashkent. Negative/future/inconsistent wait inputs return elapsed_age=null with time_unknown. Initial episode starts requested_at; reopen starts new explicit episode; retain original_requested_at. `business_age=null`, reason calendar_unavailable; elapsed seconds are neither business hours nor employee time.

API proposal base `/api/v1/projects/{project}/ba`:

Every POST requires Idempotency-Key; source-envelope endpoints additionally require Expected-Source-Revision. Source counters remain outside the immutable envelope hash. Human event/review endpoints use body expected_revision and reject inapplicable source-control headers. New facts return201 and authorized exact replay returns200. Party fixture registration is explicit under ba.fixture.manage/source authority and stores immutable party versions; an unregistered or ambiguous party cannot be silently resolved from a label.

- POST `/clarifications`: request.open envelope, Idempotency-Key and Expected-Source-Revision.
- POST `/clarifications/{id}/answers`: answer.record/correct/retract envelope with same source controls plus aggregate expected_revision in body.
- POST `/clarifications/{id}/events`: typed request revise/reopen/withdraw/context body with expected_revision; revise supplies complete canonical request/link set.
- POST `/clarifications/{id}/reviews`: confirm/reject/revoke with expected_revision and exact current answer/request/link pins.
- GET/HEAD `/queue`, `/history/{id}`: bounded authorized pages; optional department scope, no body.

A strict schema rejects unknown fields, duplicate decoded JSON keys, array/object and bool/integer substitutions, control bytes and invalid UUID/UTC fields. POST root object≤65536 UTF-8 bytes/depth16; GET unknown query/body rejects; inapplicable custom control headers reject. Idempotency keys1–100 ASCII allowlisted characters. Text proposal: question/answer/context≤4000 bytes, human reason≤2000, source object/party reference≤200; nonblank required. Pages default20/max50; link count1–20; actor and derived graph/proof category bounds are separate as defined above. These are synthetic capacity assumptions. Credential scanning occurs before persistence using established scanner; no new corporate DLP capability claim. Locators are typed local receipt/field references, no arbitrary URL/path fetch.

DTOs include synthetic:true, ai_interpretation:unavailable, customer_authenticity:not_verified, exact permitted source/version/hash/request/answer/review/link pins, separate state/applicability/scope/dependency/coverage and UTC age. Mutation acknowledgements contain scoped UUID/revision/hash only. Errors401/403/404/409/413/422 and503 admission use generic code/message/request_id; no raw payload/hidden IDs/traceback. HEAD invokes identical checks and empty body.

CLI proposal only registers explicit synthetic BA sources/parties under guarded local/testing configuration and invokes the same authorized services; no source fetching, inference or notifications. Existing local PHP/Laravel/PostgreSQL versions/configuration remain; dependencies not changed. Local migrations apply incrementally to isolated dev/test, preserving existing E03/E05-01 records; no migrate:fresh/shared reset. Migration rollback verification on an isolated disposable database must preserve older module rows; normal recovery is retry/replay, not destructive rollback. New runbook records verified actual commands after implementation, token cleanup, disabled real-data access and failure reasons.

## Acceptance and review gate

The implementation plan must use behavioral PHPUnit/PostgreSQL tests, contract/OpenAPI checks, PHP style/dependency verification and guarded authenticated local smoke; no SQLite substitute for RLS/FKs/concurrency. These are proposed cases, not executed results:

1. CRM duplicate-import question ties Ivan coordination/customer owner to exact Steve task/criterion, John permitted dependency and Victoria separate BA queue; QA blocker remains separate.
2. Answer recorded stays answered; distinct PM exact confirmation resolves question with authenticity not_verified and no E03/QA/accepted-weight writes.
3. Recorder self-confirm denies; unauthorized role title/connector administrator denies; applicable exact purpose authorizes.
4. Confirm stale request/answer/hash/links/CAS conflicts atomically; concurrent correction/review cannot resolve obsolete answer.
5. Source correction/retraction/rejection/new revision/gap leaves current unreviewed/unknown; no prior favorable answer fallback.
6. Reopen/revise/new owner creates boundary/episode; context preserves existing boundary; withdrawal never marks readiness.
7. Scope-change-required remains pending/unknown with no clearance adapter; new heads/claimed approval cannot fake scope readiness. Revise/relink/reopen/fresh answer followed by no_scope_change cannot launder the outstanding obligation. An unrelated fresh question can resolve normally. Existing E03 reject→merge preserves history and selectively stales affected BA links.
8. Wrong task-criterion/requirement membership, duplicate edges, cross-project/tenant and illicit query-builder references reject by service/DB guards.
9. Source/request replay identical adds no facts/audit/change, differing hash conflicts; canonical link permutation has same identity; source and aggregate CAS stay independent.
10. Reassignment/late event retains original interval assignee; missing mapping/owner/time/calendar yields unknown, no blame/hours.
11. Current/source/original mandatory grant and required role proofs each rechecked at final clock; role/grant expiry/revoke/replacement denies/withholds despite unchanged other authority. Restore cannot revive observed terminal invalidity; two user accounts mapped to one employee cannot self-confirm.
12. Fresh independent answer may resolve despite an optional old historical answer's revoked registrar; no mandatory current proof bypass.
13. One hidden linked actor/task/source suppresses whole root/text/count/cursor; department scope is additional; cross-user/project/department cursor rejects and next-page revocation applies.
14. Distinct-actor and derived proof/page/category bounds yield safe unknown/no hidden counts; a same-actor maximum-link fixture with over100 independent proofs remains supported. Smaller legal pages and unrelated healthy questions remain usable; overflow causes no terminal revocation and category query counts show no N+1.
15. Future/inconsistent timestamps null age; reopen episode/current and original requested timelines remain distinct.
16. Injection/invalid locators/unknown fields/duplicate decoded keys/secret-bearing input cannot execute or grant privilege; logs/errors do not reveal payloads.
17. Worker/application RLS roles and composite FKs deny cross-tenant queries/writes; missing context never grants unrestricted access.
18. Crash before commit yields no partial success; committed replay resumes identical fact; migration upgrade/isolated rollback preserve E03/QA and no E03 write coupling occurs.

Independent Laravel/domain and security/database review must inspect integrated authority, links, supersession and scope uncertainty. Verify behavior against approved diagram and this approved written design before claiming the synthetic unit complete. Full E05/AC-07, AI/calendars/real customer evidence, production NFR and release acceptance remain open. User's explicit coding authorization is recorded above; [implementation plan](../plans/2026-10-07-e05-ba-clarification-plan.md) governs the bounded execution. Historical proposed-detail wording in this document is superseded by that authorization for this exact slice; new material scope changes still need new design approval.



Final Task4 verification2026-10-09: single bare PHP artisan test --colors=never **693passed/16020assertions/921.78s exit0**; expanded focused222passed/4988assertions/436.98s exit0. Full Pint, strict Composer/locked platform and381local OpenAPI/schema refs passed. Pre-full309frozen inputs verified unchanged after the full run; no code/schema/OpenAPI drift. Guarded existing dev29–33 and97assertion authenticated HTTP-kernel CRM/94scoped absence counts0/all tokens null/tx0 remain verified. Independent combined unit-review.md reports Task4 spec/quality PASS and whole-unit integration PASS, with no Critical/Important/Minor findings or fix wave. Root targeted regression passed:12 tests/337 assertions/12.99s, exit0; scoped final acceptance is complete. Full E05/AC07, AI/live sources/UI/calendar/production NFR/deployment and commit permission remain separate.

2026-10-09 root final gate: approved synthetic E05-02 backend complete. T1–T4 and independent Task4/whole-unit review passed, no open findings. Single bare full suite: 693 tests / 16020 assertions / 921.78s, exit0. Root integrated BaApiTest|BaSchemaContractTest: 12 tests / 337 assertions / 12.99s, exit0. Fresh final manifest drift=[] before evidence-only documentation updates. Full Pint, strict Composer/locked platform and 381 local contract/OpenAPI references passed. Dev incremental29–33 and authenticated HTTP-kernel CRM97 assertions, 94 scoped absence counts0, all created tokens unauthenticated and transaction0 verified. All resumed agent dispatches explicitly gpt-6.1-sol/medium. Branch ba-clarification-tracking, base21b7339; changes remain unstaged/uncommitted. Commit/push/merge/deployment are separate permissions. Full E05/AC07, UI/live sources/local inference/calendars/production NFR remain open.
