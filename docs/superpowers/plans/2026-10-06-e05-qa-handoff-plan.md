# E05-01 QA dalillari va topshirish — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sintetik build/muhit/test/nuqson/qayta test/topshirish dalillaridan ruxsatli, versiyalangan QA tekshiruvi va qamrovini berish.

**Architecture:** Dedicated Laravel `App\Domain\Qa` owns immutable typed PostgreSQL evidence and human CAS decisions. Current eligibility checks exact selection/source/original-authority pins before delivery; durable bounded maintenance supplements those checks. E03 acceptance/generation and E04 disabled runtime remain unchanged.

**Tech Stack:** Existing locked Laravel13.34.0, PHP8.5.11, PostgreSQL17.10, PHPUnit12.5.37, Opis/json-schema2.6.0, Pint. No new dependency, Python, inference, UI or service provisioning.

**Spec:** [Approved written design](../specs/2026-10-06-e05-qa-handoff-design.md); [approved diagram/scope](../../design-mockups/e05-role-evidence-flow-v1.md). Executors read both, AGENTS.md and relevant repository DOCX §8/30.6/AC-07/08/09 before code.

Status2026-10-06: diagram/scope and written spec approved. User approved execution with “Ishni davom et.”; execution started under the existing subagent-driven method. Branch `role-evidence-monitoring`, base `49519f0ace50cd618e3300d093cc91486a3a35a4`. Preserve existing subagent-driven method; no repeated execution-method question. No E05 commit/push/merge/deploy permission inferred.

## Global Constraints

- Synthetic local backend only; all product AI=`unavailable`; external inference/fallback and source calls/writes prohibited. No actual E04 parser activation claim.
- E03 gate, observations, immutable acceptance pins/progress/history/generation unchanged. QA fields are `qa_verification`, never release approval, hours or employee score.
- Request≤64KiB UTF-8/depth16; included+excluded plan criteria≤100; each criterion definitions≤20; total selected definitions≤2000; logical keys≤100 ASCII bytes; title≤500 UTF-8bytes; steps/reproduction≤4000bytes; human reason nonblank≤2000 UTF-8bytes.
- Page default50/max100, cursor≤128bytes; original actor-set≤100 per operation. Overflow explicit partial/unknown, no favourable truncation. UTC microseconds; Asia/Tashkent display; elapsed UTC age only, business age unavailable.
- Maintenance batch20, new pending admission≤100project, lease60s, max5claims, retry5/30/120/600s, fenced publication/durable catch-up watermark. Admission503 before writes; existing authorized retry/read available. E03 hooks never fail from QA backlog.
- FORCE RLS, semantic composite lineage, nonowner/noBYPASSRLS, append-only facts/reviews, guarded monotonic heads, actual DB clock after locks/before commit; no destructive resets/history cleanup.
- Project-only QA purposes plus separate department grant checks; role/tenant grant alone grants no QA access. No logs containing body, proof, secrets or inferred blame.
- Fresh independent unit/final reviews. Child agents do not recursively dispatch, branch, commit or change other owners' files. Root serializes shared mutable database checks.

## Review Focus

1. Same actor legitimately has different original proofs on different records: lock once, evaluate each keyed proof separately; stale old proof must not poison valid new facts or be repaired by replacement grant. R1/R5 tests.
2. A correction changes an old pass after another attempt: latest known attempt/current correction wins; no historical pass fallback. R3/R4 tests.
3. Department cursors/counts can reveal forbidden same-project people: filter before pagination/counts and recheck each page. R5/R6 tests.
4. Scope changes while a defect awaits retest: explicit scope_revised preserves history/state but requires a new fix request/pins before close. R4 tests.
5. A100criteria/2000test UUID plan cannot fit64KiB: immutable per-criterion entry snapshots plus compact final references, full canonical assembled hash and batched queries. R2/R3 tests.

## Execution and file ownership

R1→R2→R3→R4→R5→R6 have producer/consumer dependencies; implement/review gates sequential. Read-only domain/security planning ran in parallel. Fresh Laravel/PG/security reviewers are distinct from implementers; final whole-unit review separate. No competing migrations or test-DB writers. One R1 schema owner reserves all increments. `QaTestCase` additions hand off serially R1→R2→R3→R4→R5.

Dedicated current task checkout is used; before execution verify branch/base/status/Git dir/common-dir. Worktree only if isolation becomes necessary per AGENTS; never stash/reset user work. Children may request minimal producer fixes through root, not silently edit frozen modules. No application implementation/tests in plan drafting.

Verified commands from `backend/` (use focused filter per task):

```powershell
& ../.local/tools/php85/php.exe artisan app:migrate-local --env=testing
& ../.local/tools/php85/php.exe artisan test --colors=never --filter=QaSchemaIsolationTest
& ../.local/tools/php85/php.exe vendor/bin/pint --test
& ../.local/tools/php85/php.exe C:/php/php84/composer.phar validate --strict
& ../.local/tools/php85/php.exe C:/php/php84/composer.phar check-platform-reqs --lock
```

RED must expose missing specified behavior; GREEN inspect exit0/counts. Isolated guarded PostgreSQL loopback55432 dev/test only; no SQLite/reset/dump or credential output. Real two-process tests retain committed synthetic immutable fixtures. No auto-commit step: exact owned diff+review then root asks completed-unit commit approval unless separately authorized; Uzbek subjects, no coauthor/push/merge/deploy.

All classes below are created in `backend/app/Domain/Qa/`; tests in `backend/tests/Feature/`. Contract/authority/receipt/pins helpers require authorized coordination; eligibility/calculator never reopen authority locks. Canonical receipt returns `{receipt_id,record_id,source_head_revision,current,replayed,input_hash}`; human CAS returns `{id,revision,state,synthetic:true}`. Read DTO has `{qa_verification,selection,counts,critical_signals,coverage_status,reason_codes,synthetic:true,ai_interpretation:'unavailable'}`. QA counts expose numerator/denominator/nullable ratio_basis_points, complete/all-required-passed criterion counts, per-criterion/excluded/omitted data. Basis points=floor(10000*numerator/denominator), null for0/unknown; no release boolean.

### R1 — contract, semantic storage and ordered authority

**Owner:** Laravel/PG implementer; independent database/security reviewer.

**Create:** `QaContract.php`, `QaAuthority.php`, `QaCoordination.php`, `QaReceiptWriter.php`, `QaChangeWriter.php`, `QaPins.php`, `QaResponsibility.php`; `docs/contracts/qa-evidence-v1.schema.json`; `backend/tests/Support/QaTestCase.php`; `QaSchemaIsolationTest.php`, `QaAuthorityTest.php`.
**Modify:** `backend/app/Domain/Access/DatabaseAccessGateway.php` exact purpose/scope allowlists (`qa.fixture.manage`, `qa.plan.manage`, `qa.execution.record`, `qa.defect.review`, `qa.handoff.manage`, `qa.read`, `qa.source.read`, `qa.process` project-only; `qa.department.read` department-only, no tenant grant fallback); `backend/app/Domain/Audit/AuditWriter.php` fixed QA action/resource allowlist.

**Create all migrations (R1 sole owner):**

| File `backend/database/migrations/2026_10_06_…php` | Tables |
|---|---|
| `000019_create_qa_evidence_foundation` | qa_receipts, qa_request_keys, qa_source_heads, qa_build_versions, qa_environment_versions, qa_definition_identities, qa_definition_versions, qa_project_state |
| `000020_create_qa_plans_and_reviews` | qa_plan_entry_snapshots, qa_plan_entry_definitions, qa_plan_versions, qa_plan_entries, qa_reviews, qa_selection_events |
| `000021_create_qa_execution_lineage` | qa_execution_series, qa_execution_attempts, qa_execution_versions |
| `000022_create_qa_defects_and_handoffs` | qa_defects, qa_defect_events, qa_handoffs, qa_handoff_events |
| `000023_create_qa_maintenance` | qa_changes, qa_applicability, qa_invalidations, qa_outbox |
| `000024_strengthen_qa_lineage_and_proofs` | Additive proof binding, exact semantic lineage and guarded head/lease constraints; applied19–23 unchanged |
| `000025_correct_qa_guard_record_dispatch` | Correct generic trigger record-field dispatch; applied19–24 unchanged |
| `000026_bind_qa_lifecycle_change_origins` | Separate trusted lifecycle_mutation_id composite FK from exactly-one typed subject |
| `000027_close_qa_review_and_lineage_gaps` | Review INSERT/head proof, exact defect fix-request closure and typed idempotency output constraints |
| `000028_pin_qa_entry_and_selection_reviews` | Immutable exact definition review pin on entry tests and plan review pin on selection |

`qa_request_keys` is immutable `(tenant,project,requester,operation,key,payload_hash)` with exactly one explicit output FK (receipt, plan entry, plan, defect or handoff); its later-table FKs are added by R1 final increment after all targets exist. It records request aliases separately from canonical source receipts. Exact replay returns original output; new alias may add only this request binding, never another source event/attempt/audit/outbox. Different payload under a retained same requester/op/key409.

Typed row→receipt composite `(tenant,project,receipt,kind)`; definition→same stable criterion; entry snapshot→existing baseline_items `(tenant,project,baseline,policy,criterion_version,id)`; execution→exact series/definition/build/environment/attempt; predecessor same series. Definition/plan reviews have two explicit nullable subject FKs, exactly-one-subject and current-review pointer bound to subject/revision/ID. Selection/defect/handoff heads bind exact parent/event/revision. Change subjects use typed nullable composite FKs + exactly-one-subject, not unchecked polymorphic UUID. Change subjects include explicit legacy criterion_version/task_version/baseline/lifecycle_change FK slots as well as QA subjects. Trusted lifecycle_mutation_id or alternate task_mutation_version_id is a separate origin composite FK outside the exactly-one subject count; lifecycle-origin requires exactly one origin and matching app.lifecycle_mutation actor/purpose/generation/scope. Task origin is the newly inserted current task version, actor-bound under requirements.manage, same stable task as affected subject; no new E03 task_changed event or generation semantics. QA-origin requires QA proof and neither lifecycle-origin FK. QA-origin facts require qa-authority proof; lifecycle-origin invalidation changes bind trusted existing lifecycle mutation/actor/purpose and exact current subject references, never fabricated QA proof. Fact/review/event UPDATE/DELETE/TRUNCATE denied; only documented heads/applicability/lease fields mutable under guards. down() refuses history deletion.

**Interfaces produces:**

- `QaContract::parse(string $raw): array`, `validate(string $operation,array $data): array`, `canonical(array $value): string`, `reason(string $value): string`; fixed local schema/duplicate-key/UTF-8/byte checks and existing SecretGate::safeText, no remote resolver.
- `QaAuthority::captureBatch(TrustedActor $scope,string $project,array $requests,?string $department=null): array`; requests `{key,actor_id,purposes,pinned_proof?:array,required:bool,responsibility_refs?:array}`; keyed results `{valid,proof?:array,reason_code}`. Lock union sorted once; current required denial403, optional original failure for applicability. Same-user distinct pinned proofs remain separate. responsibility_refs include exact task_version_id/identity_id and occurred_at for bounded event-time candidates; missing occurred_at means historical-context-unknown, never current-role substitution. `assertEffective(array $proofResults,string $dbInstant): void`; exact proof `qa-authority-v1`, captured_at excluded from equality only.
- `QaCoordination::run(TrustedActor $actor,string $project,array $purposes,bool $requireOpen,Closure $work,array $requests=[],?string $department=null): mixed`; callback receives keyed proof results. Project→E03state→QAstate→tenant/users/project+department memberships/roles/approved identity mappings/grants/policies→content; authority phase closes before callback, no nesting/reacquisition, final actual clock expiry rollback.
- `QaResponsibility::captureLocked(string $project,string $taskVersion,string $occurredAt,?string $identityId): array`; batch-prelocked task/event-time role/membership/mapping input, no authority reacquisition; IDs/revisions/periods/hash or missing/ambiguous/overflow unknown. `elapsedAge(string $openedAt,string $dbInstant): array` elapsed_utc, invalid/future unknown. R3 executions and R4 handoffs both consume this foundation.
- `QaPins::collect(TrustedActor $actor,string $project,array $subjects): array` returns `{requests,refs,input_hash}` from bounded scope-only hints without delivery; `loadLocked(string $project,array $refs): array` prefetches exact version/source inputs after coordination.
- `QaReceiptWriter::recordLocked(TrustedActor $actor,string $project,array $envelope,string $key,int $expectedSourceRevision,array $proof): array`; replay lookup before CAS/admission, conflicting hash409, scoped source/object/version uniqueness. Caller typed record/request-key binding in same transaction. `QaReceiptWriter::requestLocked(TrustedActor $actor,string $project,string $operation,string $key,string $payloadHash): ?array` performs known-operation scoped request lookup before CAS/admission; `bindRequestLocked(TrustedActor $actor,string $project,string $operation,string $key,string $payloadHash,array $typedOutput): void` records exactly one FK-backed output. Human createEntry/compose/report/open use these helpers; CAS review/event/selection calls use expected revisions and do not require an Idempotency-Key.
- `QaChangeWriter::appendLocked(TrustedActor $actor,string $project,string $kind,array $subject,string $inputHash,array $proof): string`; typed change/outbox/audit, QA-only DB identity `change_seq` monotonic watermark, no E03 generation. Per-project catch-up filters project while using this global QA sequence; unrelated/rollback sequence holes are not execution-source gaps.

- [x] Write RED schema/authority tests, including actual RLS/nonowner, same-tenant wrong project AND semantic lineage, immutable facts/reviews, forbidden pointer changes, unknown keys/nested duplicate JSON keys/actor spoofing, secret text/2000vs2001-byte reason with no writes. Example scoped assertions:

```php
$this->assertFalse($this->runtimeOwnsAnyQaTable());
$this->assertTrue($this->allQaTablesForceRls());
$this->assertSqlState('23503', fn () => $this->crossCriterionExecutionWrite());
$this->assertSqlState('42501', fn () => $this->rewriteQaReviewHistory());
$this->assertDenied(fn () => $this->captureTenantOnlyQaGrant());
```

R1 owns helper signatures used in examples: runtimeOwnsAnyQaTable():bool, allQaTablesForceRls():bool, assertSqlState(string $state,Closure $operation):void, crossCriterionExecutionWrite():void, rewriteQaReviewHistory():void, assertDenied(Closure $operation):void, captureTenantOnlyQaGrant():array. These check actual PG privilege/constraint behavior, not mocked services.

`QaTestCase extends BaselineTestCase` reuses guarded E03 synthetic requirement/baseline setup, ordinarily no E03 observations/acceptance. Helpers preserve explicit expected revisions. Test same actor mixed original proofs independently; current role/tenant-only denial; real DB clock expiry after locking/working rolls back fact/head/history/audit/outbox.

- [x] Run RED `--filter='QaSchemaIsolationTest|QaAuthorityTest'`; expected missing QA schema/contract/authority. Record failures before code.
- [x] Implement exact signatures, five increments and triggers; reuse TenantContext/AccessGateway/SecretGate patterns without generic refactor.
- [x] Guarded isolated test migrations only; GREEN focused plus TenantIsolationTest/ProjectAccessTest/AcceptedProgressTest/DeveloperSnapshotTest. Pint intended paths, inspect owned diff.
- [x] Fresh DB/security review checks actual FK/RLS/trigger privileges/proof lock order/expiry. Resolve/scoped re-review; freeze schema/interfaces before R2. No dev migration/commit yet.

### R2 — registry, reviewed definitions, compact plan and selection

**Depends:** reviewed R1 contracts/schema/proofs.
**Create:** `QaFixtureService.php`, `QaDefinitionService.php`, `QaPlanService.php`, `QaSelectionService.php`; `QaPlanTest.php`, `QaSelectionTest.php`; `backend/tests/Fixtures/qa-crm.php`.
**Handoff modify:** QaTestCase production-service helpers; preserve R1 tests.

**Consumes:** R1 receipt/change/coordination/pins; existing approved baseline_items/current criterion links. Same-tenant existing `source_instances.kind=manual` synthetic setup; QA does not create connectors/users/grants/mappings. QA receipts/source heads explicitly project-bind the source instance; no real adapter.

**Produces:**

- `QaFixtureService::register(TrustedActor $actor,string $project,array $envelope,string $key,int $expectedSourceRevision): array` for `build|environment|definition`; qa.fixture.manage+qa.source.read. Stable test identity bound to stable criterion, immutable definition/version/source, designed by default, explicit release_critical flag.
- `QaDefinitionService::review(TrustedActor $actor,string $project,string $definition,array $decision): array`; `{expected_revision,decision:reviewed|rejected|revoked,reason}`, qa.plan.manage+qa.source.read.
- `QaPlanService::createEntry(TrustedActor $actor,string $project,array $entry,string $key): array`; `{baseline_id,baseline_item_id,criterion_version_id,disposition:included|excluded,reason,tests:[{definition_version_id,source_instance_id,required}]}`≤20. One immutable snapshot per criterion; no mutable draft-item append.
- `QaPlanService::compose(TrustedActor $actor,string $project,array $compactPlan,string $key): array`; `{baseline_id,entry_snapshot_ids:[UUID]}`≤100; exact complete baseline partition and full assembled ordered data canonical hash, not UUID-list hash. Required/optional/source bindings pinned.
- `QaPlanService::review(TrustedActor $actor,string $project,string $plan,array $decision): array`; approved/rejected/revoked CAS, qa.plan.manage+qa.read+qa.source.read.
- `QaSelectionService::select(TrustedActor $actor,string $project,array $selection): array`; `{plan_version_id,build_id,environment_version_id,expected_revision,reason}`, same three purposes; immutable event/current head CAS. Importing new build never selects it.

- [x] RED tests: full partition/no omitted/duplicate/baseline-foreign criteria; exclusion reason mandatory; missing definitions remain included; exact reviewed-version pin/no inherited review; current source/original review required. No plan versus all-excluded approved plan gives distinct null denominator/zero denominator, never100%. Selection preserves history; wrong build/env/criterion/revoked review409; CAS conflict no writes.

```php
$this->assertCount(100, $plan['entries']); // prebuilt immutable snapshots
$this->assertSame(2000, $plan['selected_definition_count']);
$this->assertLessThanOrEqual(65536, strlen($compactRequest));
$this->assertSame($plan['input_hash'], $this->assembledPlanHash($plan));
$this->assertSame($oldSelection, $this->currentSelection()); // after build import
```

- [x] Run RED `--filter='QaPlanTest|QaSelectionTest'`; expected missing services. Add reviewedDefinition/approvedPlan/select helpers returning immutable IDs and explicit head revisions, not silently repairing stale CAS. R2 helper assembledPlanHash(array $plan):string independently reconstructs expected full pinned canonical input; currentSelection():?array reads guarded current pointer for assertions.
- [x] Implement exact services, atomic receipt/head/change/outbox/audit, original proof/hash and final DB clock. Registration/review/plan use scopes; exact replay before new admission. Smaller criterion snapshots allow100×20 selected tests under64KiB EACH request without raising limits.
- [x] GREEN focused+R1 regression, scoped Pint. Fresh Laravel/security review verifies partition/source lineage/hash/selection proofs; freeze exports before R3. No commit.

### R3 — attempts, corrections and deterministic verification

**Depends:** R2 frozen plan/selection and R1 proof/pins APIs.
**Create:** `QaExecutionService.php`, `QaEligibility.php`, `QaVerificationCalculator.php`; `QaExecutionTest.php`, `QaProjectionTest.php`.
**Handoff modify:** QaTestCase execution helpers.

**Produces:**

- `QaExecutionService::register(TrustedActor $actor,string $project,array $envelope,string $key,int $expectedSourceRevision,int $expectedSeriesRevision): array`; qa.execution.record+qa.source.read. Body `{definition_version_id,build_id,environment_version_id,attempt_ordinal,outcome:passed|failed|blocked,predecessor_attempt_id?:UUID,retests_execution_id?:UUID,fix_request_event_id?:UUID,task_version_id,actor_identity_id?:UUID,blocked_reason?,locator}`. Source object ID is immutable attempt identity, source revision correction ordinal; correction cannot switch series/attempt. Use R1 QaResponsibility at record time; no R4 forward dependency.
- `QaEligibility::inspectLocked(string $project,array $inputs,array $proofResults): array`; exact selected current attempt/correction/definition review/current criterion/source and original-proof compatibility, typed invalidation reasons; no authority reacquisition. Missing/overflow ordering/proof unknown.
- `QaVerificationCalculator::calculate(array $eligiblePlan): array`; pure distinct-criterion completed numerator/approved denominator/basis_points, required completeness and all_required_passed, explicit critical signals. Failed counts as completed, blocked not; zero required never true; required/optional critical failures visible. No release flag.

- [x] RED replay/conflict/order/correction/retest/cross-build tests plus three-included/one-excluded fixture: A multiple attempts/tests, B blocked-only, C missing definitions. Assert numerator1/denominator3 and3333basis_points, not attempt count or pass rate. Required pass+missing/failed/blocked parameterized; optional fail separate. No plan/zero scope null; original-proof overflow unknown, no old-pass fallback.

```php
$this->assertSame(1, $facts['numerator']);
$this->assertSame(3, $facts['denominator']);
$this->assertSame(3333, $facts['ratio_basis_points']);
$this->assertFalse($facts['criteria'][$blocked]['all_required_passed']);
$this->assertContains('release_critical_test_failed', $facts['critical_signals']['codes']);
```

Test exact replay no extra receipt/event/audit/outbox; conflict409; late source correction remains historical; correction no new retest; latest blocked/failed/invalid correction prevents old-pass selection; source gap/absent predecessor explicit unknown, separate sources not merged.

- [x] Run RED `--filter='QaExecutionTest|QaProjectionTest'`; expected missing services; implement exact interfaces.
- [x] Heads use explicit attempt ordinal then source correction revision, never arrival time. New local retest next ordinal/CAS; new build/env series starts ordinal1 with separate historical retests_execution_id, no cross-series predecessor. When defect fix_request_event_id supplied, validate exact existing typed lineage without adding transition behavior.
- [x] GREEN focused+R1/R2; query-count test max100criteria/2000definitions with≤100 original actors, bounded prefetch rather than per-test/history queries; actors overflow explicit unknown/continuation. Measure before adding indexes, no p95 claim. Pint and fresh review freeze eligibility/DTO for R4. No commit.

### R4 — reviewed defects and historical handoff facts

**Depends:** R3 exact current execution/eligibility; R2 selection/reviews.
**Create:** `QaDefectService.php`, `QaHandoffService.php`; `QaDefectTest.php`, `QaHandoffTest.php`, `QaConcurrencyTest.php`; `backend/tests/Support/qa-cas-child.php`.
**Handoff modify:** QaTestCase fixedDefect/handoff/committed fixture helpers only. Producer fixes through root ownership return, no silent R1–R3 edits.

**Produces:**

- `QaDefectService::report(TrustedActor $actor,string $project,array $report,string $key): array`; qa.defect.review+qa.source.read, immutable first build/criterion/definition/task, severity/reproduction source pins.
- `QaDefectService::transition(TrustedActor $actor,string $project,string $defect,array $event): array`; `{expected_revision,kind,reason,pins}`; exact approved spec§7 states, current fix request/retest/selection and original proofs.
- `QaHandoffService::open(TrustedActor $actor,string $project,array $data,string $key): array`, `transition(TrustedActor $actor,string $project,string $handoff,array $event): array`; qa.handoff.manage+qa.source.read, typed owner/task/event version CAS.
Consumes R1 QaResponsibility methods; R3 executions already use the same event-time snapshot, so no forward dependency or retrospective backfill.

- [x] RED states: open→triaged→fixed_awaiting_retest→verified_closed; closed→reopened→triaged, cause required. Passed import never closes; exact latest attempt/current correction and this defect/current fix request required. Old pass behind failed/blocked/invalid correction, another defect/request or old fix request409 with no new event/head/audit/outbox.

```php
$before = $this->defectWriteCounts();
$this->assertConflict(fn () => $this->closeUsingHistoricalPass());
$this->assertSame($before, $this->defectWriteCounts());
$this->assertSame($originalActor, $this->handoffHistory()[0]['responsibility']['employee_id']);
$this->assertSame('elapsed_utc', $this->handoffAge()['age_basis']);
```

R4 helper contracts: defectWriteCounts():array snapshots actual event/head/audit/outbox counts; closeUsingHistoricalPass():array invokes production transition with retained old pins; handoffHistory():array and handoffAge():array call current authorized service/test projections.

Parameterize scope_revised in open/triaged/fixed_awaiting_retest/reopened: same stable criterion/test with valid new E03 links, retain state/old-new pins; old fix request historical, new explicit request required before close. Closed version change only reviewed new_scope reopening; unrelated/removed criterion cannot force closure. Duplicate reviewed scoped canonical target no self/cycles or hidden fail. Initial failure and all retest history retained.

- [x] RED handoff open→ack→resolve/direct evidence-backed resolve, context preserves disposition; resolved terminal except context; owner change only open/ack with history. Reassignment preserves original responsibility and oldest age; unknown mapping/role not inferred from current owner.
- [x] Run RED `--filter='QaDefectTest|QaHandoffTest|QaConcurrencyTest'`; implement matrices/interfaces with prefetched proofs/eligibility, no callback authority locks.
- [x] Real two-process CAS data-provider selection/definition-review/defect/handoff: commit synthetic fixtures, hold project lock, spawn fixed guarded children via ready/go pipes, observe two distinct PostgreSQL PIDs both waiting, release. Exactly one success/one409 and one event/head increment, no sequential-service substitution. Final DB clock expiry while wait/work rolls back writes. Retain immutable fixtures.
- [x] GREEN focused+R3 regressions/Pint; fresh Laravel/PG review checks actual contention, closure/pin lineage, transitions and attribution. Freeze before R5; no commit.

### R5 — selective invalidity, durable catch-up and protected delivery

**Depends:** reviewed R1–R4 data/proofs/eligibility/events.
**Create:** `QaInvalidation.php`, `QaMaintenanceProcessor.php`, `QaDepartmentView.php`, `QaReadService.php`; `QaInvalidationTest.php`, `QaRecoveryTest.php`, `QaAccessTest.php`, `QaBoundaryTest.php`; `backend/tests/Support/qa-maintenance-child.php`.
**Modify sole owner:** `backend/app/Domain/Lifecycle/RequirementService.php`, `TaskService.php`, `BaselineService.php` minimal coalesced QA change hooks; QaTestCase committed recovery helpers. R2/R3/R4 services need narrow denied-retry wrappers only: root explicitly returns exact ownership to R5, preserves interfaces and separate scoped diff. No E03 acceptance refactor.

**Produces:**

- `QaInvalidation::observeLocked(TrustedActor $actor,string $project,string $eventKey,array $affected): void`; existing project/lifecycle coordination only, typed selective QA change/watermark, no new QA actor grant/authority locks inside E03 callback.
- `QaInvalidation::refreshDenied(TrustedActor $actor,string $project,string $operation,string $key): void`; runs after denial rollback in own bounded scope; match retained qa_request_keys requester/project/op/key and its FK-backed output only, independently verify original/source proof failure; no content/IDs, viewer-only denial no invalidation. `persistLocked(array $invalidSubjects,string $eventKey): void` typed terminal invalidity, never revive observed revoke.
- `QaMaintenanceProcessor::claim(TrustedActor $operator,string $project): ?array`, `processClaim(TrustedActor $operator,string $project,array $claim): array`, `drain(TrustedActor $operator,string $project,int $limit=20): array`; qa.process+qa.source.read, durable exact claim proof/token before compute, fenced publication/retry under current proofs.
- `QaDepartmentView::authorizeLocked(TrustedActor $actor,string $project,string $department,array $inputs,array $proofResults): array`; separate project QA read/source and department-only qa.department.read/current membership and subject visibility. Filter before counts/page/cursor, no hidden IDs/omitted-count leak. In department mode global approved-plan numerator/denominator/ratio are ALWAYS withheld/null; show only permitted per-criterion facts and visible-record counts with basis=department_authorized_entries, not a silently reduced global coverage denominator. No conditional hidden-subject indicator or unfiltered omitted total. Project-only full verification preserves approved-plan formula.
- `QaReadService::verification(TrustedActor $actor,string $project,?string $department=null): array`; `history(TrustedActor $actor,string $project,string $kind,string $id,int $limit=50,?string $cursor=null,?string $department=null): array`; `queue(TrustedActor $actor,string $project,int $limit=50,?string $cursor=null,?string $department=null): array`. Bounded hints/prefetch→single proof batch→pure eligibility→terminal invalidity→reload DTO. qa.read+qa.source.read; closed history metadata allowed, current verification unavailable.

- [x] RED original-revocation tests: registrar/reviewer denied exact replay persists observed invalidity after rollback; same-row restore/replacement still stale; wrong requester/tenant/project/op/key no healthy invalidation; viewer-only denial unchanged. First-observation DTO not pre-invalidation favourable. Same user different original proofs independently evaluated. Unobserved restore limitation disclosed.
- [x] RED selective reject→merge criterion, definition review, selection, source correction, task reassignment/closed changes; unrelated criterion stays applicable. Worker lag never supplies stale current result. E03 accepted67% plus critical candidate failure separate; QA generation/events don't touch E03 observations/acceptance/history.
- [x] Implement hooks/current reads and processor. Canonical QA events have transactional outbox per fact; admission100 rejects new mutation503 with no partial writes. E03 hooks append durable typed change and monotonic watermark/coalesced reserved catch-up row regardless QA admission; one running cursor+bounded pending catch-up,≤20 subjects per batch, process through target watermark. No retained-history scan inside GET/source/E03 hook; no E03 failure due backlog. Closed hook after is_closed update, preserve existing E03/E04 behavior. E03 callback may already hold authority locks: hook only appends typed immutable change and updates reserved outbox watermark at the final outbox phase, NEVER reacquires QA project-state/tenant/membership/grant locks. Existing trusted lifecycle mutation binding authorizes invalidation-only observation; it grants no QA content access. DB change_seq supplies watermark without updating QA state. Tests assert SQL lock order under baseline closure with original E03 authority already held.
- [x] GREEN real child crash/reclaim: exit after committed claim and before publication; inspect durable token/proof, expire mutable lease only, new child reclaims; paused old worker later cannot publish. Fifth expired lease no sixth claim, exact retry5/30/120/600. New changes arriving during catch-up retain distinct canonical facts/watermark and are handled without lost subjects. No immutable cleanup bypass.
- [x] GREEN history/access: >100rows plus concurrent append no omissions/duplicates; cursor scope confusion422/404; source/department/member revoke before next delivery denied. Adam project-only/department-only/tenant-only/role-only deny, wrong/absent/expired subject departments filtered before count/page. Department mode test asserts global counts/ratio withheld even if all subjects happen to be visible, and pagination reveals only authorized continuations. Actual final DB clock expiry rollback; max-size queries measured before indexes, no p95 invented.
- [x] Focused `--filter='QaInvalidationTest|QaRecoveryTest|QaAccessTest|QaBoundaryTest'`, required lifecycle/accepted progress/developer invalidation regressions, Pint. Fresh security/PG review/scoped fix re-review before R6/dev migration. No commit.

### R6 — transport, local operations, contracts and final verification

**Depends:** all prior reviewed interfaces/schema/recovery gates.
**Create:** `backend/app/Http/Controllers/QaController.php`, `backend/app/Http/Controllers/Concerns/StrictQaJson.php`; `backend/app/Console/Commands/RegisterLocalQaFixture.php`, `DrainLocalQaMaintenance.php`; `backend/config/qa.php`; `QaApiTest.php`; docs `contracts/qa-evidence-v1.md`, `contracts/qa-data-dictionary.md`, `operations/local-qa-evidence.md`, `acceptance/e05-01.md`.
**Modify sole owner:** `backend/routes/api.php`, `backend/app/Providers/AppServiceProvider.php`, `docs/api/openapi.yaml`, `docs/contracts/source-capability-matrix.md`, `docs/README.md`, `docs/decisions.md`, approved spec/plan/image Markdown status only. PNG unchanged.

Transport base `/api/v1/projects/{project}/qa`, authenticated trusted actor/UUID routes; no unknown fields/query; HEAD same permission and empty body. Source-control expected revisions are outside immutable envelope hash; Idempotency-Key≤100 scoped requester/project/op hash on fixture/execution/entry/plan/defect-report/handoff-open only; human CAS review/selection/event uses expected revision instead. Current retries check authority before receipt lookup. Existing E02 ticket.snapshot v1 unchanged.

| Verb/path after base | Service/purpose (all `qa.` prefix) |
|---|---|
| POST `/fixtures` | fixture.register; fixture.manage+source.read; build/environment/definition only |
| POST `/definitions/{definition}/reviews` | definition.review; plan.manage+source.read |
| POST `/plan-entries` | plan.createEntry; plan.manage+source.read |
| POST `/plans` | plan.compose; plan.manage+source.read |
| POST `/plans/{plan}/reviews` | plan.review; plan.manage+read+source.read |
| POST `/selections` | selection.select; plan.manage+read+source.read |
| POST `/executions` | execution.register; execution.record+source.read |
| POST `/defects` / `/defects/{defect}/events` | defect.report/transition; defect.review+source.read |
| POST `/handoffs` / `/handoffs/{handoff}/events` | handoff.open/transition; handoff.manage+source.read |
| GET `/verification` / `/queue` | read.verification/queue; read+source.read; optional department UUID adds department.read |
| GET `/history/{kind}/{subject}` | read.history; read+source.read; kind build/environment/definition/plan/selection/execution/defect/handoff |

New immutable record201/exact replay200; ordinary review/event/selection200. Errors413transport size,422schema/bounds/cursor,409CAS/conflict/closed/ineligible closure,403authority,404unknown scoped UUID,503admission. No raw body/proof/tracebacks.

CLI `app:qa-fixture {project} {fixture} --token-file=... --expected-source-revision=...`: shared R1 strict parser≤65537bytes, fixed local guarded DB, token never argv/output; only fixture operations. CLI supplies `--idempotency-key` or derives an ASCII SHA256 key from the natural source identity/version, not expected CAS revision. Execution HTTP purpose remains separate. `app:qa-drain {tenant} {actor} {project} --limit=20`: existing explicit operator convention, qa.process+source.read required; refuses nonlocal/testing, wrong endpoint/name/marker/overrides or owner/BYPASSRLS. Fixed counters only, no scheduler/service principal/global service changes.

- [ ] RED API/CLI strict contract/duplicate nested keys/control/unknown operation/source actor spoofing/bool-int and array-object coercion; UTF-8 reason2000/2001+secret no-write; required source/project/department grants; status/HEAD/cursor default50/max100/128bytes/revocation next-page; CLI guard no self-grants;503new admission but committed exact replay/read200.
- [ ] Run RED `--filter=QaApiTest`; implement thin transport/shared parser/provider/config/commands and exact OpenAPI paths/contracts, no unversioned alias or E02 schema expansion. GREEN all QA focused tests/Pint.
- [ ] Document canonical hashes, actual schema/types/purposes/selection/denominator, source matrix, lease/dead-letter/runbook/sample-only limitation, open DEC/no full E05/AC07/AI/OPS/load/retention/inference claim. Validate local OpenAPI YAML/JSON using available parser, no new deployment tooling.
- [ ] Fresh full PHP `artisan test --colors=never`, full Pint, strict locked Composer validate/platform. Existing E04 runner-denial stays in full PHP; unchanged Python need not be rerun. Only after schema/recovery reviews apply reviewed19–28 dev incrementals via app:migrate-local, no reset/history deletion.
- [ ] Scoped service+HTTP CRM smoke: critical failed/blocked build, fixed/retest/manual close, criterion change/reassignment, permitted Adam filtering, E03 acceptance independent; rollback-safe dev fixture creates no permanent grants/token. Retained test fixtures are not deleted. Health200/protected401 liveness only.
- [x] Fresh whole-unit independent Laravel/PG/security review against base+intended tracked/untracked inventory/image hash; owned scoped fixes/re-review. Controller fresh relevant verification after behavior fix, no broad repetition after pass. Compare with approved diagram/12 scenarios and report discrepancies/open gates accurately.
- [ ] Root final diff/credential exclusions/status, open acceptance report, ask completed-unit E05 commit permission. Only then intended staging/Uzbek feat/fix/test commit, no coauthor/push/merge/deploy. Preserve ignored review evidence, no automatic recursive delete.

## Spec coverage and handoff

| Approved spec/scenarios | Task |
|---|---|
| §1–3 isolation/contract/AI/source boundaries | R1/R6, R5 E03/E04 boundary regression |
| §4 typed lineage/RLS/original proofs | R1 |
| §5 plan/selection/review/denominator | R2/R3 |
| §6 replay/correction/retest/source ordering | R3 |
| §7 defects/scope/duplicate/handoff/attribution | R4 |
| §8 revocation/department/closed/hooks | R1/R5 |
| §9 limits/cursors/recovery/transport/audit | R1/R3/R5/R6 |
| §10 scenarios1–5 | R2/R3; closure historical-pass control R4 |
| §10 scenarios6–8 | R4/R5 actual CAS/clock/selective changes |
| §10 scenarios9–12 | R1/R5/R6 |
| §11 open DEC/commit/deferred scope | R6 docs/root report |

Concrete test-name index (within each owning class):

| Task/class | Named behavioral tests |
|---|---|
| R1 QaSchemaIsolationTest/QaAuthorityTest | test_missing_context_and_semantic_lineage_deny; test_history_immutable; test_same_actor_distinct_original_proofs; test_actual_clock_expiry_rolls_back |
| R2 QaPlanTest/QaSelectionTest | test_exact_baseline_partition_and_compact_2000_definitions; test_empty_and_missing_plan_distinct; test_new_build_does_not_select; test_review_not_inherited |
| R3 QaExecutionTest/QaProjectionTest | test_replay_and_conflicting_hash; test_correction_no_new_attempt; test_no_historical_pass_fallback; test_three_criterion_coverage_is_1_of_3; test_optional_critical_failure_visible |
| R4 QaDefectTest/QaHandoffTest/QaConcurrencyTest | test_close_requires_latest_correction_and_fix_request; test_scope_revised_in_each_nonclosed_state; test_duplicate_cycle_does_not_hide_failure; test_handoff_terminal_and_event_time_owner; test_two_waiting_processes_one_cas_event |
| R5 QaInvalidationTest/QaRecoveryTest/QaAccessTest/QaBoundaryTest | test_denied_retry_observed_revocation_sticky; test_viewer_deny_does_not_invalidate; test_child_crash_reclaim_fences_old_worker; test_fifth_expired_claim_no_sixth; test_department_filter_before_counts; test_hook_backlog_and_lock_order_preserve_e03; test_e03_67_percent_and_candidate_failure_separate |
| R6 QaApiTest | test_strict_nested_json_and_utf8_reason_bounds_no_writes; test_cursor_scope_and_revocation_next_page; test_guarded_cli_no_self_grants; test_admission_503_replay_read_200 |

Parent self-review checks spec coverage, signature/data consistency, actionable steps, all ten Review Focus tests and proportion. This is one E05-01 plan; compact entries implement approved64KiB/max2000 combination without mutable plans/raised limits. Later BA/PM/capacity/calendar/notification/bridge is separate.

Historical draft-stage next step was user review of this plan; the recorded2026-10-06 “Ishni davom et.” approval superseded that waiting state and started the existing specialist/TDD/review workflow. No task execution/application test occurred during drafting; current execution status follows.

Final E05-01 gate2026-10-07: the single whole-unit fix wave and independent scoped rereview addressed I1–I4/M1 with no new breakage. Fresh bare PHP suite557passed/12987assertions/614.13s exit0; covering192passed/4780assertions; root integrated24passed/705assertions/51.13s exit0. Full Pint, locked Composer and OpenAPI checks passed. Guarded dev19–28 already applied; refreshed authenticated Laravel HTTP-kernel CRM69 static assertions passed with31 scoped absence counts zero, token absent and transaction0. TCP200/401 remains liveness only. The approved synthetic QA backend E05-01 is complete and ready for commit approval; full E05/AC-07, BA/PM/UI, live sources, local inference, production NFR and deployment remain open. E03 acceptance remains separate; image/design scope unchanged. No commit/push/merge/deploy permission inferred.

Integration ruling2026-10-07: original maximum-compose test ceiling80 predates reviewed R5's four constant tenant-context/request-key precheck queries. Root authorizes only this assertion84 (original80+4) and explanatory comment; actual first full sample82 versus prior78. Specification requires bounded table-category queries/no N+1, not a numeric80 ceiling. Security/product/schema stay unchanged; focused maximum and one final full run must verify the corrected gate before guarded dev smoke. This adjusts the test accounting boundary, not the approved design or a production performance target.
