# E05-03 management support implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the synthetic local escalation → explicit support assignment → support fact → independent human outcome review, with permitted queues/history and unchanged E03/QA/BA stores.

**Architecture:** Dedicated Laravel `Domain/Management`, immutable scoped facts/receipts and CAS-protected current heads in PostgreSQL. Management-specific typed read-only adapters verify exact E03/QA/BA references; a single project transaction, keyed authority capture and final DB-clock fence protect every mutation/delivery. No generic workflow engine or nested source-domain coordination.

**Tech Stack:** Existing PHP8.5.11, locked Laravel13.34.0/Opis2.6.0/PHPUnit12.5.37/Pint1.32.1, existing PostgreSQL17 dedicated loopback55432. No dependency installation, model or server provisioning.

**Spec:** `docs/superpowers/specs/2026-10-09-e05-management-support-design.md`.

**Review status:** Execution approved on2026-10-09. After this complete plan was presented, user said “Kodni yozishni boshla, men tasdiqlayman”. This authorizes implementation of all five units within the approved design and routine scoped fixes/reviews without repeated coding-permission requests. Specialist implementation and independent review method follows the user's AGENTS workflow; every agent uses `gpt-6.1-sol`, reasoning `medium` (latest user policy2026-10-13, superseding Ultra). This document supplies no executed module test result until evidence is recorded. Commit/push/merge/deployment remain separate from execution.

## Global Constraints

- Continue `management-support-tracking` from E05-02 `2c66c55fa5ec7e7084a08a72a2f45f0ab69bdc81`; preserve all changes. Root alone stages/branches/commits; children never dispatch, create branches or commit.
- Approved image SHA256 `b24d27d90b482b6dc534c30e4fadd7c8992f77c71e526efcd603f99ffbb77334`; no new UI/live sources/AI/calendar/capacity/forecast/notification/export/RAG/finance/deployment.
- Source contracts and stores are read-only. Never invoke QA/BA mutation, maintenance, terminal observation, receipt writes, or nested coordination; never fake their `app.*_authority_closed` markers. Existing TenantContext, TrustedActor, local bearer, SecretGate and installed Opis are infrastructure reuse.
- Roles/purposes follow the spec matrix exactly. Project grants and department grants are distinct; role/title/tenant grant never substitutes. Management/source original proof categories remain independently keyed, even for the same employee/user.
- One owner/helper/current support per episode. Reviewer employee UUID differs from helper AND support recorder; two accounts for one employee do not establish independence. Helper may equal owner; names never authorize.
- Each action/support reference set has1–20 typed exact tuples. Different typed subjects may share a tuple; duplicate typed subject+tuple or contradictory relation rejects. Actual task/criterion and approved-current requirement membership must match. UUID existence/highest version is insufficient.
- FORCE RLS, composite tenant/project semantic FKs, immutable histories and nonowner/nonSUPERUSER/nonBYPASSRLS runtime. No reset, migrate:fresh, down, schema/history rewrite, secret printing or environment override.
- Business state, support/review status, current outcome, applicability and underlying dependency remain separate. Completed management support cannot close QA/BA or accept E03. Newer missing/rejected/retracted/gapped evidence never falls back to older favorable output.
- One mutation root; retained and prospective boundaries are both checked (B≤2). Queue default20/max50 roots; history max50 events. B=queue roots/history events/mutation retained+prospective boundaries. L≤40B and≤2000; each boundary separately has≤20 action/20 support refs.
- Charge slots before dedup. Management proofs≤min(10B,400); source rows≤min(64L,4000); original source proofs≤min(32L,2300); named slots≤min(8B+8L,2000); responsibility slots≤min(2L,1000); receipts≤min(16L,200); source instances/connector heads≤200 each; original actors≤100. Per-ref closure≤64 rows/32 proofs/64 steps. Current/source/department checks independently reserved, still bounded. Task2 execution adjustment: reviewed boundaries require9 distinct event/fact/receipt originals, reviewed context10; relative8B was insufficient. Preserve every mandatory original and unchanged absolute400 rather than omit human receipts.
- Mutation overflow503/no fact; read overflow empty unknown/null count/no cursor. Privacy-complete uncertain source state may return null outcome; hidden mandatory source/person/proof hides entire record. No favorable truncated closure.
- POST object≤65536 UTF-8 bytes/depth16; text≤4000bytes; required reason≤2000; object_id≤200; Idempotency-Key1–100 ASCII `[A-Za-z0-9._:-]`. Closed schema per operation, duplicate/unknown key/type/control/secret checks; timestamps real microsecond UTC.
- Source CAS header differs from envelope source_revision and body aggregate expected_revision. Counter controls excluded from stable content hash; source identity/timestamps/typed content included. New201/replay200;401/403/404/409/413/422/503 safe errors. HEAD same permissions, empty body.
- Whole-record privacy before LIMIT/count/cursor; current delivery rechecks and final PostgreSQL clock. Cursor≤4096 bytes binds tenant/actor/project/resource/action/department/limit/generation/revision; no hidden positions/totals.
- Original event-time responsibility uses exact task and assignment intervals; future/missing/inconsistent time unknown. Elapsed UTC only, business_age=null/calendar_unavailable. Every DTO synthetic:true/ai_interpretation:unavailable; BA customer authenticity remains not_verified.
- Source and human immutable receipts preallocate output IDs and use deferred output/parent guards, rather than updating immutable receipt rows. Source natural identity and requester/operation/key identity remain distinct.
- Five sequential implementation units, each frozen and independently reviewed for spec compliance and quality. Ownership changes require root handoff. Root serializes DB writers and preserves ignored reports. No repeated broad/full suite without new change/failure.

## Review Focus

1. Same tuple with unrelated QA/BA record: exact semantic proof denies; current approved requirement is not merely highest revision. T2 `test_same_project_wrong_tuple_and_unapproved_highest_version_do_not_link`.
2. Current+prospective full20-reference mutation: neither retained privacy nor prospective source proofs disappear under admission. T2/T3 `test_prospective_boundary_keeps_all_current_and_new_proofs`.
3. Late source fact/decline/gap after reviewed support: separate subject fact survives, projection never falls back or borrows current helper attribution. T3 `test_late_and_gapped_support_preserve_subject_without_favorable_fallback`.
4. Many distinct saved proofs for one user: category charges and final-clock expiry remain independent. T1/T4 `test_same_actor_distinct_originals_and_delivery_expiry`.
5. Hidden context/source/scope origin before and between pages: no text/ID/count/cursor leak; optional old history cannot clear mandatory BA obligation. T4/T5 `test_hidden_dependency_before_limit_and_revocation_between_pages`.

## Shared interfaces and ownership

Workers read both spec and plan. PHP associative arrays use documented closed shapes; no user-provided callable/table/URL/authority request. Add PHPDoc shapes to ManagementPins and contract documentation, not a generic framework.

`ManagementGraph`: `coverage` complete|unknown, `reason_code` nullable; `boundaries` exact root/event/action-version/assignment/support/review/subject-support IDs; `references` typed subject+tuple+hash; `refs` allowlisted typed table→UUID lists; `heads` captured exact mutable-head fields; `proof_slots` key/format/subject/hash/actor/purposes/pinned_proof/mandatory; `current_requests` reserved typed checks; `named_slots` and `responsibility_slots`; `budget` all independently charged category counts. Request keys≤100 bytes incorporate format/type/subject/hash/scope/purpose, not actor alone.

Locked evaluation: `subject_fact`, `current_projection`, `historical_review_status`, `applicability`, `current_outcome`, `reference_results`, `underlying_dependency_status`, `required_proof_keys`, `terminal_observations`, `responsibility`, `coverage`, `reason_codes`. Internal proofs/envelopes never become public DTO fields.

T1 owns contract/schema/authority/fence. T2 owns graph/source adapters/responsibility. T3 owns workflow/receipts. T4 owns read/pagination. T5 owns transport/guarded fixture/published evidence. Shared ManagementTestCase passes serially between units; changing an earlier unit's other files requires a narrow explicit root ownership return. Additional receipt/responsibility/pagination helpers implement the approved module boundaries; they do not broaden product scope.

All commands below run in `backend/` with `& ../.local/tools/php85/php.exe`. Foundation migrations are reserved34–36. Execution ruling2026-10-09 adds forward-only37 `2026_10_09_000037_preserve_management_projection_context.php`: actual direct-SQL RED demonstrated that36 rejected unchanged confirmed projections on context.add. Preserve applied34–36;37 replaces only the management commit guard and must retain exact confirmation/predecessor lineage. Guarded TEST applies only37 after34–36; fresh DEV allowlist is34–37. Historical `.local/ba-task4-*` scripts are references, not executable management commands.

Task1 review/fix ruling2026-10-09 supersedes the fresh DEV allowlist with34–38. Forward-only38 `2026_10_09_000038_enforce_management_atomic_lineage.php` repairs exact receipt/event review binding, fresh support subject/current projection and event/root/generation correspondence. It adds nullable-historical project_generation on events/terminal observations and scoped state event/terminal pointers, without backfilling history. New event or terminal insert assigns generation in the database; exact replay/duplicate observation assigns none. Workflow callers insert facts/receipt, then event, then advance root, and never manually increment generation. Sequential multi-event writes use deferred constraints; verify only after a complete write phase. Final favorable root needs exact complete source head; historical eligibility is still checked by T3. TEST38 was incrementally applied after actual bypass RED; fresh DEV34–38 remains gated on independent review/rootT5. Applied34–37 SQL is preserved. Task1 fix focused37tests/837assertions and fresh independent scoped spec/quality rereview passed; Task1 is complete within its foundation scope, with T2–T5 and whole-module acceptance still pending.

Task2 execution handoff: exact boundary selectors are current action UUIDs or `{action_id,event_id}` historical selectors; normalized graph exposes `root_id/event_id`. Prospective references may be closed internal `{reference,occurred_at,assignment_id?}` wrappers after strict wire validation; wrapper metadata never enters public fields/reference hashes. A nonexistent preallocated assignment yields unknown. For assignment.set/reassign, collect task/source context without that ID, capture explicit owner/helper/department named proofs before closure, and write the new assignment time/membership snapshot from those already fenced proofs; no additional authority after closure. Action occurrence uses task responsibility without a later assignment; each support/subject_support uses its own immutable assignment, action version, receipts and named origins. The actual current-only late-reassignment specimen requires11 management originals and is whole unknown forB1; legitimate two-event historyB2 can preserve all provenance within20. Mutation retained+prospectiveB2 remains independently charged. Read-only source adapters must overlay exact durable QA invalidity and reject captured absence becoming terminal before delivery; same-row grant restoration cannot revive terminal source facts. These are implementation corrections within the approved flow, not an increase of category ceilings. Task2 independent fix re-review passed for all four findings; Task3 public workflow integration is now active. T4/T5 and whole-module acceptance remain pending.

Task3 fix ruling2026-10-13: forward-only39 `2026_10_13_000039_bind_management_support_source_series.php` binds each support natural source series to one exact action/action-version/assignment, including late facts with no predecessor. A measured composite source-prefix/binding index supports two scoped tuple-range probes; no source tables, privileges, history backfill or applied34–38 SQL change. Guarded dedicated TEST39 was applied after root inspected the DDL/preflight, with unchanged source/management count and head inventories in28 current-plan retained scopes. This is bounded inventory evidence, not a global unchanged-data claim. Fresh DEV allowlist becomes34–39 after independent fix review and remains gated on rootT5; DEV is untouched. Task3 fix/re-review and T4/T5 remain pending.

Task3 adapter handoff: reference occurrences retain their exact owning boundary/refset/subject internally, without changing wire tuples or reference hashes. Historical subject applicability and the current projection are evaluated separately while the entire mandatory privacy/original-proof union stays fenced. Read-only BA adapters overlay native terminal invalidity using exact typed subjects and the hash of the stored native JSONB proof representation; parties bind their own scoped registration receipt proof. Captured terminal absence must be rechecked under project serialization. No management adapter calls source observation/mutation methods. T4 consumes this fix and must add separately authorized bounded multi-boundary evaluation/batching rather than flattening all occurrences into the first boundary.

### Task 1: Contract, storage, authority and transaction fence

**Create:** `backend/app/Domain/Management/ManagementContract.php`, `ManagementAuthority.php`, `ManagementCoordination.php`, `ManagementTerminal.php`; `backend/app/Support/LocalManagementDatabase.php`; `backend/config/management.php`; `docs/contracts/management-evidence-v1.schema.json`; `backend/tests/Support/ManagementTestCase.php`; `backend/tests/Unit/ManagementSchemaContractTest.php`; `backend/tests/Feature/ManagementContractTest.php`, `ManagementSchemaIsolationTest.php`, `ManagementAuthorityTest.php`.

**Modify:** `backend/app/Domain/Access/DatabaseAccessGateway.php` management-only scope/purpose branches. Never relax existing purposes.

**Create migrations:** `backend/database/migrations/2026_10_09_000034_create_management_support_foundation.php`, `2026_10_09_000035_enforce_management_lineage.php`, `2026_10_09_000036_enforce_management_projection_and_receipts.php`. 34 creates the13 spec tables, scoped keys, FORCE RLS and immutable guards;35 adds typed local semantic/series/review guards;36 deferred receipt/output/reference-set/root/event/head consistency. Apply together only to guarded dedicated TEST after schema inspection; DEV remains deferred to T5.

**Consumes:** TenantContext::run, TrustedActor, local runtime guards, installed SecretGate/Opis and existing scoped E03 unique association keys added in migration29. Tests may use trusted server-built graph inputs to exercise authority without pretending T2 source adapters exist.

**Produces:**
- Shared `ManagementTestCase extends BaselineTestCase` exposes `managementInputs(): void`, `managementActor(string $role,?string $employee=null): TrustedActor`, `actionInput(array $references=[]): array`, `managementEnvelope(string $operation,array $body,string $object='case',int $revision=1): array`, `lifecycleReference(): array`, `managementRun(Closure $work): mixed`. managementInputs calls inherited inputs(), initializes valid approved baseline/criterion/task association and explicit synthetic source, and supplies the default actor's exact PM role plus management/E03 read and source purposes needed for open/read. Fixtures use existing `$this->p`; additional source/role grants are explicit per test. No fixture factory runs in product code. Runtime test writes remain tenant-scoped and rollback.
- `ManagementContract::parse(string $raw): array`, `validate(string $operation,array|object $data): array`, `canonical(mixed $value): string`, `contentHash(string $operation,array $data): string`.
- `ManagementAuthority::captureBatch(TrustedActor $actor,string $project,array $requests,?string $department=null,array $namedSlots=[],array $responsibilitySlots=[]): array`; `assertEffective(array $proofs,string $at): void`; `requireDeliveryLocked(array $keys): void`.
- `ManagementCoordination::run(TrustedActor $actor,string $project,array $purposes,bool $requireOpen,array $graph,Closure $work,?string $department=null): mixed`. Graph carries pre-collected refs/heads and typed authority requests; coordination has no unresolved T2 class dependency. Work receives locked proof results; no new authority collection in callback.
- `ManagementTerminal::overlayLocked(string $project,array $graph): array`, `observeLocked(string $project,string $kind,string $subject,string $proofHash,string $reason): void` on management tables only.
- `LocalManagementDatabase::assertRuntime(): void` delegates LocalBaDatabase, then rejects runtime ownership of management_ tables.

- [x] **Step1 — RED:** Write `test_closed_operation_schema_and_byte_limits` asserting max4000-byte text accepted/4001 rejected, reason2000/2001, wrong operation body/unknown key/secret denied with no insert. Write `test_direct_query_builder_wrong_lineage_and_cross_tenant_are_rejected`, `test_runtime_cannot_update_delete_or_truncate_immutable_facts`, `test_same_employee_cannot_review_through_second_account`. Use actual PostgreSQL runtime/migrator roles, FORCE RLS missing context, scoped task-criterion FK and deferred receipt/output validation. Add `test_same_actor_distinct_originals_and_delivery_expiry`, exact role/grant replacement/expiry and department vs project grants. No production/shared reset.

```php
public function test_duplicate_decoded_keys_are_rejected(): void
{
    $this->expectException(InvalidArgumentException::class);
    app(ManagementContract::class)->parse('{"operation":"action.open","operation":"support.record"}');
}
```

- [x] **Step2 — verify RED:** Run `artisan test --colors=never --filter 'ManagementContractTest|ManagementSchemaIsolationTest|ManagementAuthorityTest|ManagementSchemaContractTest'`; record actual expected missing contract/schema behavior, not unrelated DB failure. Review34–36 SQL and actual dedicated TEST marker/migrator before their guarded incremental application.
- [x] **Step3 — GREEN:** Implement closed contract, all spec tables/guards and typed management/QA/BA original-format authority batches. Extend every relevant access scope branch (project-only and department-only), not only allowlist. Lock tenant/user, bounded responsibility inputs, employee, memberships/roles/grants, policy/source/connector in sorted order; final DB-clock fence and transaction-local marker cleanup. Current viewer denial is not an original terminal observation. No dummy source request for empty source context.
- [x] **Step4 — verify:** Rerun the focused command; `vendor/bin/pint --test` on owned PHP. Assert successful transaction and thrown callback both reset tenant/management settings; runtime never becomes table owner/SUPERUSER/BYPASSRLS. Record actual test/assertion counts and unchanged source stores.
- [x] **Step5 — freeze/review:** Root captures intended diff and hashes, specialist report includes RED/GREEN/schema guards and limits. Fresh independent PostgreSQL/security and spec/quality review; fix only findings and rereview changed scope. No staging/commit.

### Task 2: Exact read-only source proofs, graph and historical responsibility

**Create:** `backend/app/Domain/Management/ManagementPins.php`, `ManagementSourceProofs.php`, `ManagementResponsibility.php`; `backend/tests/Feature/ManagementSourceProofTest.php`, `ManagementBudgetTest.php`, `ManagementResponsibilityTest.php`. Extend only shared ManagementTestCase.

**Consumes:** T1 contract, scoped storage, typed authority slots and coordination fence. Source fixtures are set up through existing approved services in TEST only; production adapters never mutate them. No QaPins/BaPins locked loaders under fabricated source markers.

**Produces:**
- `ManagementPins::collect(TrustedActor $actor,string $project,array $boundaries,array $prospectiveReferences=[],array $sourceIds=[]): array`; `loadLocked(string $project,array $graph): array`.
- `ManagementSourceProofs::lifecycle(string $project,array $references,array &$budget): array`, `qa(...)`, `ba(...)` with identical signatures; `evaluateLocked(string $project,array $graph,array $rows,array $proofs): array`.
- `ManagementResponsibility::captureLocked(string $project,array $reference,string $occurredAt,?array $assignment,array $proofs): array`; `age(string $openedAt,?string $episodeOpenedAt,string $now): array` returns null unknown for invalid/future/inconsistent time and business_age=null.

- [x] **Step1 — RED:** `test_same_project_wrong_tuple_and_unapproved_highest_version_do_not_link` checks all7 typed reference kinds against actual association/current approval. `test_lifecycle_only_graph_needs_no_dummy_qa_ba_source_context` still requires E03 read rights and named privacy. `test_prospective_boundary_keeps_all_current_and_new_proofs` composes retained20+20 and prospective20 sets, validating both boundaries and independent absolute caps. Tests retain QA selection/entry/definition-review/retest/duplicate and BA request/answer/review/party/scope-origin; unrelated mutation leaves reference current, changed exact head becomes stale, no source writes.

```php
public function test_lifecycle_only_graph_admits_its_exact_reference(): void
{
    $this->managementInputs();
    $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [], [$this->lifecycleReference()]);
    $this->assertSame('complete', $graph['coverage']);
    $this->assertSame(1, $graph['budget']['references']);
    $this->assertSame(['lifecycle'], array_column($graph['references'], 'kind'));
}
```

- [x] **Step2 — verify RED:** `artisan test --colors=never --filter 'ManagementSourceProofTest|ManagementBudgetTest|ManagementResponsibilityTest'`. Record expected missing adapters/incorrect association results separately from unavailable fixtures.
- [x] **Step3 — GREEN:** Implement fixed typed batch closure and format-specific proof validators, semantic tuples/current heads, captured/locked head comparison and cycle≤64-step detection. Count every category before dedup, enforce min(formula,absolute) independently; charge current+prospective, preserve complete required lineage. Read-only SQL joins use existing association keys; no highest-version-only shortcut or source-domain marker. Cache lookups only by complete saved identity, never one proof per actor.
- [x] **Step4 — verify:** Focused command must pass. `test_category_cap_and_cap_plus_one_without_other_cap_binding` uses jointly feasible specimens for each independent limit; test complex QA closure overflow unknown, original actor101, reference21 and hidden context. Historical task/assignment boundary edges include exact successor timestamp, before assignment/future/unresolved membership; no current-person substitution. Query-log source writes count must be zero.
- [x] **Step5 — freeze/review:** Source capability table records tested/simple and overflow-unsupported shapes. Independent Laravel/security review against original QA/BA contract, including mandatory provenance. No staging/commit.

### Task 3: Immutable actions, support facts, receipts and independent review

**Create:** `backend/app/Domain/Management/ManagementActionService.php`, `ManagementSupportService.php`, `ManagementReviewService.php`, `ManagementReceiptWriter.php`; `backend/tests/Feature/ManagementActionTest.php`, `ManagementSupportTest.php`, `ManagementReviewTest.php`, `ManagementReceiptTest.php`, `ManagementConcurrencyTest.php`, `ManagementRevocationTest.php`; `backend/tests/Support/management-cas-child.php`. Extend only shared ManagementTestCase.

**Consumes:** T1 coordination/authority/terminal/contract, T2 graph/source evaluation/responsibility. No source write helper or existing schema changes.

**Produces:**
- `ManagementActionService::open(TrustedActor $actor,string $project,array $envelope,string $key,int $expectedSourceRevision): array`; `event(TrustedActor $actor,string $project,string $action,array $body,string $key): array`.
- `ManagementSupportService::record(TrustedActor $actor,string $project,string $action,array $envelope,string $key,int $expectedSourceRevision): array` dispatches record/correct/retract/decline.
- `ManagementReviewService::review(TrustedActor $actor,string $project,string $action,array $body,string $key): array` dispatches confirm/reject/revoke.
- `ManagementReceiptWriter::beginSourceLocked(TrustedActor $actor,string $project,array $envelope,string $key,int $expectedSourceRevision,array $proof): array`; `beginHumanLocked(TrustedActor $actor,string $project,string $operation,array $body,string $key,array $proof): array`; `bindOutputLocked(array $ticket,array $output): void`. Preallocate receipt/fact/event IDs; insert immutable receipt once and defer guard to commit. Binding records immutable request alias, never UPDATE receipt. Ticket includes preallocated typed output IDs before insertion.
- Acknowledgement has receipt/action/event/operation-specific IDs, revision,input_hash,replayed,synthetic,AI markers and fresh applicability; no saved favorable DTO replay.

- [x] **Step1 — RED:** Exercise every allowed/disallowed transition in spec, explicit PM/department assignment/owner-helper roles and full pins. `test_late_and_gapped_support_preserve_subject_without_favorable_fallback` stores lower source revision's subject with unchanged current projection; newer gap clears favorable output. Same-series predecessor/hash/state/CAS mismatch409; missing predecessor remains unknown only for admitted otherwise valid source facts. Decline/retraction retain exact refs/reasons, no invented support text. Confirm employee distinct from helper AND recorder; two user accounts rejected.

```php
public function test_exact_open_replay_returns_the_original_subject(): void
{
    $this->managementInputs();
    $envelope = $this->managementEnvelope('action.open', $this->actionInput());
    $service = app(ManagementActionService::class);
    $first = $service->open($this->actor(), $this->p, $envelope, 'open-1', 0);
    $again = $service->open($this->actor(), $this->p, $envelope, 'open-1', 0);
    $this->assertFalse($first['replayed']);
    $this->assertTrue($again['replayed']);
    $this->assertSame($first['action_id'], $again['action_id']);
    $this->assertSame($first['event_id'], $again['event_id']);
}
```

- [x] **Step2 — verify RED:** `artisan test --colors=never --filter 'ManagementActionTest|ManagementSupportTest|ManagementReviewTest|ManagementReceiptTest|ManagementConcurrencyTest|ManagementRevocationTest'`.
- [x] **Step3 — GREEN:** Implement state tables exactly, server-derived requester and server-time assignment boundaries, original/prospective privacy plus exact current source proof fence. Source expected-head counter, source_revision and aggregate expected_revision remain distinct; contentHash excludes body counter controls only and retains all source identity/timestamps/content. Exact request/natural replay validates current+mandatory originals first; persist proven management-local invalidity monotonically, observe no false revocation on unknown. Correction/reopen/reassign never inherit old review. Review binds all action/assignment/support/ref hashes; underlying QA/BA status remains separate.
- [x] **Step4 — verify:** Focused tests pass, actual2-process/PDO races one successful CAS/one409; exact concurrent retries one fact, different hash conflict; callback crash leaves no receipt/event/alias/head changes. Expiry after capture denies before commit, no terminal resurrection after regrant. Tests assert E03/QA/BA heads, obligation and accepted weights unchanged; late assignment/helper attribution stays historical/unknown.
- [x] **Step5 — freeze/review:** Independent workflow/spec/quality and security review on frozen physical diff; scoped fixes and fresh rereview as needed. No staging/commit.

Task3 completed2026-10-13 after fresh independent Medium Fix1 re-review: I1–I4 addressed, no new Critical/Important or Minor,12-input initial/final drift[]. Final affected covering76tests/2909assertions/125.07s and direct race/terminal/deferred regressions passed; old76/4461 had different coverage. Forward-only TEST39 is preserved,34–38 byte exact; DEV remains gated on T5. Service query counts are historical pre-fix measurements and require T4/T5 measurement. Whole-module/full-suite/browser/live AI acceptance is not implied. Sequential Task4 may now start with the narrow root handoff.

### Task 4: Whole-record permitted queue/history and bounded pagination

**Create:** `backend/app/Domain/Management/ManagementReadService.php`, `ManagementPagination.php`; `backend/tests/Feature/ManagementQueueTest.php`, `ManagementHistoryTest.php`, `ManagementPrivacyTest.php`, `ManagementProjectionBoundaryTest.php`, `ManagementReadBudgetTest.php`. Extend only shared ManagementTestCase.

**Consumes:** T1–T3 unchanged public/internal interfaces and proof protocol; management-local observation before delivery with separate full category budgets.

**Produces:** `ManagementReadService::queue(TrustedActor $actor,string $project,array $query=[]): array`, `history(TrustedActor $actor,string $project,string $action,array $query=[]): array`; `ManagementPagination::decode(TrustedActor $actor,string $project,string $resource,?string $action,array $query,int $generation): ?array`, `encode(TrustedActor $actor,string $project,string $resource,?string $action,array $query,int $generation,array $last): string`. Query allowlist limit/cursor/department_id; page items/next_cursor/visible_record_count/coverage_status/reasons/synthetic/AI, no hidden totals.

- [x] **Step1 — RED:** `test_hidden_dependency_before_limit_and_revocation_between_pages` places hidden cases before/middle/after visible ones and includes hidden context, original reviewer, source and BA scope origin. Verify permitted page filled without hidden count/cursor ID leak; source/person revocation on next page and between selection/delivery denies/withholds. `test_stale_confirmed_history_has_null_current_outcome` retains reviewed decision separately; optional hidden old history does not poison fresh independent facts.

```php
public function test_absent_history_is_an_authorized_empty_page(): void
{
    $this->managementInputs();
    $page = app(ManagementReadService::class)->history($this->actor(), $this->p, (string) Str::uuid());
    $this->assertSame([], $page['items']);
    $this->assertNull($page['next_cursor']);
    $this->assertArrayNotHasKey('action_id', $page);
}
```

- [x] **Step2 — verify RED:** `artisan test --colors=never --filter 'ManagementQueueTest|ManagementHistoryTest|ManagementPrivacyTest|ManagementProjectionBoundaryTest|ManagementReadBudgetTest'`.
- [x] **Step3 — GREEN:** Fixed typed SQL privacy before LIMIT/continuation, batch/count source closure work itself bounded, no per-root recursion or unbounded SQL recursive walk. No text/envelope fetched before permission admission. Observation and delivery independent; final clock/current scope check all delivered proofs. Cursor scoped HMAC≤4096; generation mismatch409; positions only delivered authorized records. Closed project queue unavailable/project_closed while authorized history remains possible. DTO subject_support distinct from projected support, null unknown/stale outcome and honest partial/unresolved/declined.
- [x] **Step4 — verify:** Focused pass; history absent/hidden authorized empty200/no actionID. Bounds20/50/51, malformed/tampered/mismatched cursor, legal full page then empty continuation, count only delivered. Record SQL queries and memory/elapsed for small and maximum jointly feasible admitted graphs; demonstrate no per-root/link query growth or favourable overflow. Same-actor originals remain independent; capture/delivery expiry never leaks half-page. No production NFR claim.
- [x] **Step5 — freeze/review:** Fresh read/privacy/security review and exact re-review for fixes, preserving T1–T3 behavior. No staging/commit.

### Task 5: Strict HTTP, guarded synthetic fixture and final local acceptance

**Create:** `backend/app/Http/Controllers/ManagementController.php`; `backend/app/Http/Controllers/Concerns/StrictManagementJson.php`; `backend/app/Domain/Management/ManagementFixtureService.php`; `backend/app/Console/Commands/RegisterLocalManagementFixture.php`; `backend/tests/Fixtures/management-crm.php`; `backend/tests/Feature/ManagementApiTest.php`, `ManagementLocalGuardTest.php`, `ManagementFixtureTest.php`, `ManagementFinalRegressionTest.php`; `docs/contracts/management-evidence-v1.md`, `management-data-dictionary.md`; `docs/operations/local-management-support.md`; `docs/acceptance/e05-03.md`. Ignored local preflight/CRM/freeze/check scripts explicitly assigned here.

**Modify:** `backend/routes/api.php`, `docs/api/openapi.yaml`, docs indexes/decisions. No frontend changes.

**Consumes:** All four independently reviewed units, installed bearer/local guards, unchanged source modules and installed dependency lock.

**Produces:** `ManagementController::write(Request $request): mixed`, `read(Request $request,ManagementReadService $service): mixed`; `ManagementFixtureService::register(TrustedActor $actor,string $project,array $fixture,string $key,int $expectedSourceRevision): array`. CLI name `app:register-local-management-fixture`, project+fixture positional arguments, token-file/expected-source-revision/idempotency-key options. Fixture calls approved action.open/service via existing registered synthetic source; creates no new source/user/grants. Token file≤512 bytes, fixture≤65536 bytes; no token argv/log/raw errors.

- [x] **Step1 — RED:** `ManagementApiTest` asserts source/human route controls, actual closed operation schema before service, duplicate decoded JSON keys/control headers, unknown keys/types, multibyte byte bounds, dates/secret, route UUIDs, new201/replay200/409/403/401/422/413/503. HEAD mirrors read denials/no body. Department query field is department_id, not BA's department. Guards reject URL/read/write/options/port/role/marker/ownership overrides without mutation; CLI token-file-only/no self-grants.

```php
public function test_management_queue_requires_local_authentication(): void
{
    $this->managementInputs();
    $this->getJson('/api/v1/projects/'.$this->p.'/management/queue')->assertStatus(401);
    $head = $this->call('HEAD', '/api/v1/projects/'.$this->p.'/management/queue', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $head->assertStatus(401);
    $this->assertSame('', $head->getContent());
}
```

- [x] **Step2 — verify RED:** `artisan test --colors=never --filter 'ManagementApiTest|ManagementLocalGuardTest|ManagementFixtureTest|ManagementFinalRegressionTest'`.
- [x] **Step3 — GREEN:** Wire six management paths (actions,events,support,reviews,queue,history) with exact UUIDs/trusted_actor, StrictManagementJson and LocalManagementDatabase on all entry points. Reuse transport infrastructure ideas without calling BA/QA domain handlers. Publish operation-specific schema/contract/role matrix/source capability limits/dictionary/runbook; preserve all existing OpenAPI paths/components. Write ignored preflight allowing only34–39 after the Task3 fix is independently reviewed, checking actual runtime AND migration session marker/roles/loopback, and rollback-only authenticated HTTP-kernel CRM.
- [x] **Step4 — local verification:** Focused management tests, full Pint, strict Composer/locked platform and all local schema/OpenAPI refs. Freeze all implementation inputs; run one bare `artisan test --colors=never`, capture actual exit/counts/logs. Independent Laravel/PostgreSQL/security whole-unit/spec/quality review and scoped fix rereview; then root integrated targeted regression on final inputs. No claim from stale unit logs.
- [x] **Step5 — guarded DEV acceptance:** Only after reviewed incremental migration guards and root authorization within existing local scope, apply pending34–39 via exact new preflight, no reset/down/provisioning. Authenticated HTTP-kernel CRM uses onePDO/outer transaction/savepoints; six named roles receive only authorized views; support complete keeps QA blocked/BA scope pending; corrections/revocation/time/privacy verified. Snapshot all older source counts/heads, rollback in finally, check every scoped tenant-column table plus tokens/tenant zero, auth token null and tx0. Separate TCP up200/me401 are liveness only, not browser UAT.
- [x] **Step6 — completion:** Fresh diff/secret/input-manifest checks; acceptance states exact synthetic module completion and open UI/live/model/calendar/NFR limits. Record branch/actual evidence. Ask root/user about this completed unit's commit unless already authorized for this scope; never push/merge/deploy. Only then start the next separately approved unit.

## Self-review and execution handoff

Coverage: contract/persistence/authority→T1; exact readonly source association/closure/responsibility→T2; workflow/idempotency/races/corrections→T3; visibility/history/DTO/query bounds→T4; HTTP/CLI/docs/integrated local proof→T5. Review Focus tests assigned explicitly. No unresolved placeholders or undefined inter-unit methods; helper ownership and graph shapes fixed. Existing runtime checked2026-10-09: PHP8.5.11, Laravel13.34.0, PHPUnit12.5.37 and local loopback55432 marked nonowner guard PASS. No new module code/test/migration was executed while writing this plan.

Proposed source-shape caps, independent review and prospective-boundary reservation implement the reviewed written design; prospective details are recorded in the spec. The user approved this plan and coding on2026-10-09; sequential implementation and independent review gates are active. Execution method is the AGENTS-required specialist implementer + fresh spec/quality/security reviewer per unit, with root integrated verification; all dispatches explicitly `gpt-6.1-sol`/`medium` (latest user policy2026-10-13). Review skill recommendations cannot escalate models. Remaining platform sequence is documented separately in `docs/operations/completion-roadmap.md`; this plan completes E05-03 only, not full E05/AC-07 or the whole product.
2026-10-24 root final acceptance: T1–T5 complete in approved synthetic backend scope;859/22557 barePASS before isolatedCLIgate, finalCLI6/714+offline2/40 and independent scopedsecurityrereviewPASS, actualDEV34–39 applied/post43records0pending, sixpersonaDEV287assertions/107scopedcounts0/tokensnull/tx0/samePDO. OlderDEVlocatorinventory LIMITED456absentUUIDs, no globalpreservationclaim. Completionreport/acceptance updated; commit approval requested separately, no commit/push/merge/deploy. Frontend/localAI/live sources/fullE05 remainopen.
