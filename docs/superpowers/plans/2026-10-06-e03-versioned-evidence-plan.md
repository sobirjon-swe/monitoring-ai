# E03 versiyalangan talab va dalilli qabul — implementatsiya rejasi

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Synthetic talab/mezondan inson tasdiqlagan scope va QA daliligacha versiyali kuzatiluvchanlik hamda aniq qabul progressini yetkazish.

**Architecture:** Mavjud Laravel/PostgreSQL, TrustedActor, AccessGateway va TenantContext qayta ishlatiladi. Joriy input eligibility synchronously tekshiriladi; durable E03 change outbox faqat derived applicability/coverage qayta hisoblaydi. Approved snapshots va human decisions immutable, baseline approval/acceptance/correction bir project coordination transactionda.

**Tech Stack:** composer.lockdagi Laravel/PHP, PostgreSQL17.10; portable PHP8.5.11. Yangi dependency, frontend yoki inference yo‘q.

**Spec:** docs/superpowers/specs/2026-10-06-e03-versioned-evidence-design.md; tasdiqlangan docs/design-mockups/e03-requirements-evidence-flow-v1.png. Repository DOCX FR-030/080/150, §12, E03-BE/DATA/QA, AC-05/08/09 va partial AC-06.

## Global Constraints

- Foydalanuvchi diagramma/doirani va yozma dizaynni tasdiqladi. Reja foydalanuvchining E03 ni kodiin yozib keyin qilgan ishlarga o‘t ko‘rsatmasi bilan 2026-10-06da execution uchun tasdiqlandi.
- Branch requirements-evidence-baselines, base a9ad71e. Existing doc o‘zgarishlarini saqlash; push/merge/deploy yo‘q. Commit har meaningful unit yakunida user authorization bilan, Uzbek, Co-Authored-By yo‘q.
- Kanban faqat read-only; avtomatik import yoki manbaga write yo‘q. Product AI lokal-only talabi saqlanadi, bu birlik inference ishlatmaydi.
- Har scoped record tenant_id/project_id bilan composite FK/uniquenessga ega; FORCE RLS, nonowner/nonSUPERUSER/nonBYPASSRLS runtime va transaction-local context saqlanadi.
- Draft yaratish yoki tahrirlash current acceptancega ta’sir qilmaydi. Approved snapshot UPDATE/DELETE taqiqlanadi.
- Policy tasdiqlanmagan bo‘lsa acceptance unavailable. Production DoD/weighting/retention siyosati tasdiqlanmaydi; faqat explicit fictional local policy.
- Recompute worker o‘zgargan criterion/policy/evidence/QA generationiga eski human acceptance qarorini valid qilib qaytara olmaydi.
- No SQLite/reset/migrate:fresh. Testlar guarded monitoring_ai_test localhost55432; dev migration reviewed incremental app:migrate-local bilan. Secrets/env/config/real source content Git/log/promptga kirmaydi.

## Review Focus

1. R1: faqat bitta criterion o‘zgarganda unchanged criterion versiyasi/human acceptance yashirin yangilanmasin.
2. R3/R4: latest failed QAni eski passed yoki zero observation vacuous passing almashtirmasin.
3. R4: scope kengayishida unchanged acceptance faqat explicit baseline carry-forward bilan saqlansin; changed version/policy/hash qayta inson qabuli talab qilsin.
4. R4/R5: job pending yoki actor/source grant revocation paytida cached favorable progress yetkazilmasin.
5. R5: eski generation job yoki replacement QA passed stale human acceptanceni valid qilmasin.

## Umumiy interfaces va limitlar

Service errors existing sanitized403/404/409/422; body authority emas. Routes local bearer, UUID path constraints; GET/HEAD read only. Strict JSON schema validation existing local Opis bilan, max64KiB, decoded NUL/duplicate key/type ambiguity reject; tenant/project/actor fields prohibited. POST acknowledgements server ID/revision/hash va bounded states only. UTC timestamptz(6); effective_at server clock, no backdate/future activation.

Synthetic safety assumptions: max100 criterion/item/task per project snapshot, max20 required_test_keys per criterion, string IDs/keys max100, title max500, business outcome/NFR text max4000, reason max500; payload/source secrets kiritilmaydi. Weight1..1000000, max100items → denominator<=100000000; int arithmetic then basis-point display floor(accepted*10000/denominator), exact numerator/denominator authoritative. List default20/max100 UUID cursor; dependency traversal bounded100nodes/1000edges. Oversize413, malformed422, stale expected revision/generation/current pointer409. These are synthetic bounds, not production capacity/weight policy.

All producer/consumer DTOs use UUID strings, int generation/revision and arrays below; arbitrary client handler/URL/class yo‘q. Central ProjectCoordination global generation is coordination CAS only; unrelated generation change never alone invalidates a decision. Per-input IDs/versions/hash/review/QA revision determine applicability.

## R1 — Versiya, explicit DoD va atomic change foundation

**Files:** Create backend/database/migrations/2026_10_06_000009_create_requirement_versions.php; backend/app/Domain/Lifecycle/{ProjectCoordination,RequirementService,DodPolicyService,LifecycleChangeWriter}.php; backend/app/Http/Controllers/RequirementController.php; contracts/lifecycle-requirement-v1.schema.json; backend/tests/Support/LifecycleTestCase.php; backend/tests/Feature/RequirementVersionTest.php. Modify backend/app/Domain/Access/DatabaseAccessGateway.php, backend/app/Domain/Audit/AuditWriter.php, backend/routes/api.php.

**Schema:** lifecycle_project_state(tenant_id,project_id PK,generation bigint>=0,current_baseline_id nullable,current_dod_policy_id nullable,is_closed bool); requirement identities; revisioned drafts; immutable requirement_versions; stable criteria(unique scoped requirement/key); immutable criterion_versions(text,required_test_keys,context_hash,content_hash); immutable requirement_version_criteria joins. Distinct dod_policy_versions(JSON mandatory_qa=true,human_acceptance=true,fictional=true,hash,actor,time). Immutable lifecycle_changes(subject_kind,subject_id,input_version,reason,actor,authorizing_purpose,generation,idempotency_key); separate lifecycle_outbox(change_id unique,status,attempt,available_at,lease UUID/until,completed_at,fixed reason), same lease CHECKs/5-attempt bounds as E02 but independent tables. Composite tenant/project FKs and immutable triggers/revokes. Future baseline pointer FKs are added by R4, not guessed now.

**Interfaces:** ProjectCoordination::run(TrustedActor $actor,string $projectId,string $purpose,int $expectedGeneration,Closure $work):array{result:mixed,generation:int}. It authorizes, locks project state, checks CAS, executes nested atomic work, advances generation once, returns callback result and new generation; exceptions roll back. ProjectCoordination::read(actor,project,purpose,Closure $read):mixed authorizes and holds the same project-state lock in a nonmutating transaction while collecting and serializing one coherent baseline/policy/evidence/QA snapshot; no generation increment/change/outbox. Internal snapshot reads inside run reuse its lock without nested generation advances. Delivery authorization is rechecked before serialization; all lifecycle input mutations must acquire this lock. LifecycleChangeWriter::record(TrustedActor $actor,string $projectId,string $purpose,string $subjectKind,string $subjectId,string $inputVersion,string $reason):string writes change+outbox inside this coordination transaction; approved allowlist only. RequirementService::createDraft(actor,project,array):array; updateDraft(actor,project,draft,array,int expectedRevision):array; approve(actor,project,draft,int expectedRevision,int expectedGeneration,string reason):array; listing(actor,project,int limit,?string cursor):array. DodPolicyService::approve(actor,project,array,int expectedGeneration):array creates unused immutable approved fictional policy; baseline activation later makes it effective.

Purposes spec8 names plus lifecycle.process explicit **scope_type=project** grant only; tenant-wide grant must not satisfy E03 purposes. Add purpose allowlist/project-required checks and fixed audit action/resource/reason vocabulary centrally. Existing E01/E02 behavior unchanged. Requirement draft approval checks owner active project member, nonblank owner/stakeholder/outcome, criteria/expected tests/NFR/dependency traceability. Required test keys1..20 unique; canonical criterion fingerprint includes text/test keys + shared business outcome/NFR/dependency context. Unchanged fingerprint reuses criterion_version_id; changed shared context changes all affected criterion versions. Owner/stakeholder-only administrative changes remain in requirement history without auto-mutating criterion fingerprints. Removed keys emit criterion_removed change; changed keys criterion_replaced, no draft event/outbox.

**Routes:** GET/POST /api/v1/projects/{project}/requirements; PATCH /api/v1/projects/{project}/requirement-drafts/{draft}; POST same/{draft}/approve; POST /api/v1/projects/{project}/dod-policies. Read requirements.read, drafts requirements.manage, approval requirements.approve, policy policy.approve. Server scope/purpose only.

- [x] Tests `RequirementVersionTest::test_approved_versions_are_immutable_and_drafts_do_not_invalidate`: draft edit increments revision, zero changes/outbox; approved record UPDATE/DELETE fails. `test_single_changed_criterion_preserves_other_version`: A reject→merge changes A ID, B ID preserved, one affected change/outbox. `test_explicit_project_grants_and_owner_scope`: tenant-wide/current membership absence denies; foreign FK/query-builder writes fail. `test_unused_policy_has_no_effective_default`: fictional policy approved, no current policy pointer; no hidden bootstrap.
- [x] Run PHP artisan test --filter=RequirementVersionTest --colors=never; observe missing route/service/schema behavioral red.
- [x] Implement R1 interfaces/schema/contracts/routes; receipt-style atomic lineage and audit; safe test migration only via app:migrate-local --env=testing.
- [x] Run green plus ConnectorAccessTest/ProjectAccessTest/TenantIsolationTest; scoped Pint and diff check. Reviewer checks RLS/FKs/revocations, version reuse and grant matching. Report red/green; commit request only after reviewed unit, no worker auto-commit.

## R2 — Vazifa versiyalari, links va dependency coverage

**Files:** Create migration2026_10_06_000010_create_lifecycle_tasks.php; backend/app/Domain/Lifecycle/{TaskService,DependencyGraph}.php; backend/app/Http/Controllers/LifecycleTaskController.php; contracts/lifecycle-task-v1.schema.json; backend/tests/Feature/TaskDependencyTest.php.

**Schema:** scoped task identities, immutable task_versions(title,assignee,estimate_basis,remaining_effort nullable exact nonnegative integer units,expected_test_keys,status,actor,time), revision/current-version projection; scoped task_criterion_links pin stable criterion/current version; immutable dependency edge events + active projection. Status unstarted/in_progress/blocked/implemented_awaiting_verification/done independent from acceptance. Source task receipt binding optional full composite FK; no real import. All task writes requirements.manage inside R1 coordination; no universal point→hour conversion.

**Interfaces:** TaskService::create(actor,project,array,int expectedGeneration):array; revise(actor,project,task,array,int expectedRevision,int expectedGeneration):array; depend(actor,project,task,prerequisite,int expectedGeneration):array; listing(actor,project,int limit,?string cursor):array. DependencyGraph::coverage(string $projectId):array{status:string,reason_codes:array,cycle_task_ids:array,missing_estimate_task_ids:array} inside authorized context; bounded traversal, status complete/limited, no date/probability. Later R4 progress consumes this exact DTO.

**Routes:** GET/POST /api/v1/projects/{project}/lifecycle-tasks; PATCH same/{task}; POST same/{task}/dependencies. Draft-style strict validation; listing requires requirements.read.

- [x] Tests `test_done_does_not_create_acceptance`, `test_self_and_cross_project_edges_rejected`, `test_cycle_and_missing_effort_are_explicit`: A→B→A yields limited/dependency_cycle, null effort missing_remaining_effort, no forecast. Revision CAS409 and UTC microsecond history; foreign assignee denied. Graph overflow explicit traversal_limit, never complete.
- [x] Observe red with artisan test --filter=TaskDependencyTest --colors=never, implement minimal R2, green + RequirementVersionTest/access regressions/scoped Pint. Independent task review; no unsupported hours/ranking/forecast claims.

## R3 — Reviewed receipt links, synthetic QA va joriy input eligibility

**Files:** Create migration2026_10_06_000011_create_lifecycle_evidence.php; backend/app/Domain/Lifecycle/{EvidenceService,QaObservationService,CurrentInputEligibility}.php; backend/app/Http/Controllers/LifecycleEvidenceController.php; contracts/lifecycle-evidence-v1.schema.json; backend/tests/Feature/LifecycleEvidenceTest.php.

**Schema:** immutable evidence_link_proposals(criterion_version_id,receipt_id,canonical_hash,locator,actor,time); append-only evidence_reviews(link_id,decision reviewed/rejected/revoked,revision,actor,reason,time) + current-review pointer. QA test identity scoped criterion/test_key; immutable qa_observations(link_id,criterion_version_id,test_key,result passed/failed/missing,revision,actor,time) + current observation pointer. Composite FKs bind same criterion/project and receipt/project/source. Corrections new revision/review; raw receipts unchanged.

**Interfaces:** EvidenceService::propose(actor,project,array,int expectedGeneration):array; review(actor,project,link,string decision,int expectedRevision,int expectedGeneration,string reason):array. QaObservationService::record(actor,project,array,int expectedRevision,int expectedGeneration):array. Both purposes evidence.review plus ingestion.read for source retrieval. CurrentInputEligibility::check(TrustedActor $actor,string $projectId,string $criterionVersionId,string $dodPolicyVersionId,array $reviewedLinkIds):array{status:string,reason_codes:array,pins:array}; invoked inside current authorized tenant/project coordination/read context; metadata-only output. status valid/stale/unknown; pins criterion ID/context/content hash, policy ID/hash, link IDs/hash/current review revision, current QA observation IDs/revisions/test_keys. Ingestion.read denied yields unknown/source_access_unavailable without reading raw bytes. Caller separately enforces acceptance.review or progress.read; evidence.review is not inherited by those purposes.

**Routes:** POST /api/v1/projects/{project}/evidence-links; POST same/{link}/reviews; POST /api/v1/projects/{project}/qa-observations. Review payload decision/reason/expected_revision/expected_generation; QA payload criterion_version_id/link_id/test_key/result/expected_revision/expected_generation. All require evidence.review; source access separately ingestion.read. No source payload delivery route is added.

Locator enum payload.title,payload.status,payload.external_ticket_id only; no arbitrary path/URL. Verify persisted envelope/canonical hash/type, source adapter synthetic-v1 and full binding. No title/payload in response/errors/logs. Required tests come from pinned criterion version; empty/missing/duplicate/unrecognized tests never vacuously pass. All required keys latest approved QA passed, each backed by reviewed nonrevoked receipt link; an older passed does not override latest failed. Explicit synthetic observations are not CI evidence.

- [x] Tests `test_proposed_link_never_valid`, `test_invalid_locator_hash_scope_rejected`, `test_latest_failed_and_no_qa_never_pass`, `test_revoked_source_access_unknown_without_payload`, `test_correction_atomic_change_event`: verify strict reject/deny, fixed reasons, current pins and correction/outbox atomic rollback. Two-session coordination coverage prevents review/QA updates interleaving inconsistent pins.
- [x] Red artisan test --filter=LifecycleEvidenceTest --colors=never, implement R3, green + IngestionAcceptanceTest/RequirementVersionTest; scoped Pint, independent security/lineage review. No acceptance or background recompute manufactured here.

## R4 — Baseline, inson qabuli va exact progress

**Files:** Create migration2026_10_06_000012_create_baselines_and_acceptance.php; backend/app/Domain/Lifecycle/{BaselineService,HumanAcceptanceService,ProgressCalculator}.php; backend/app/Http/Controllers/{BaselineController,AcceptanceController,ProgressController}.php; contracts/lifecycle-baseline-v1.schema.json; backend/tests/Feature/{BaselineAcceptanceTest,AcceptedProgressTest}.php.

**Schema:** immutable baselines(project,policy_id,previous_baseline_id,hash,actor,reason,time), baseline_items(baseline,policy_id,stable_criterion_id,criterion_version_id,weight,optional carry_decision_id); immutable human_acceptance_decisions(baseline,item,policy,criterion,pins JSON/hash,actor,reason,time). New items have composite FK to their new baseline/policy; decisions have composite FK to their original baseline/item/policy/criterion version. Carry FK matches tenant/project/criterion_version/policy/decision ID, never the new baseline ID to the old decision baseline ID. Create tables before adding cyclic FKs. Evidence/QA pin equality is synchronously verified by service, not claimed as JSON FK protection. applicability projection decision_id/status(valid/stale/revoked)/input_generation/hash; immutable baseline_progress_snapshots(old baseline/as_of/numerator/denominator/availability/versions/coverage/closure event). Add R1 state baseline/policy composite FKs. Snapshot/item/decision UPDATE/DELETE denied.

**Interfaces:** BaselineService::approve(actor,project,array items,string policyId,?string expectedCurrentBaselineId,int expectedGeneration,string reason,bool reopen=false):array; close(actor,project,int expectedGeneration,string reason):array; ordinary approve rejects closed state; reopen uses approve with explicit reopen=true and verifies closed state/current baseline/generation, then creates a new immutable baseline/reopen event. Repeated or stale reopen409; no in-place baseline reopening. HumanAcceptanceService::accept(actor,project,string baselineId,string criterionVersionId,array reviewedLinkIds,int expectedGeneration,string reason):array. ProgressCalculator::calculate(actor,project,?string baselineId=null):array{status:string,accepted_weight:int,total_weight:int,percentage_basis_points:?int,baseline_id:?string,policy_id:?string,coverage_reason_codes:array,previous_snapshot:?array,schedule_coverage:array}.

**Routes:** POST /api/v1/projects/{project}/baselines; POST /api/v1/projects/{project}/baseline-close; POST /api/v1/projects/{project}/acceptance-decisions; GET /api/v1/projects/{project}/progress (optional baseline UUID only, no as_of privilege override). Purposes baseline.approve,acceptance.review,progress.read; closure baseline.approve. Progress source-access unknown is explicit; no raw link contents, employee names or fake health.

Baseline approval verifies effective criterion mapping from R1 current requirement versions, explicit approved fictional policy, unique stable criteria and weights. Replacement transaction freezes old progress before activation, swaps CAS current baseline/policy, emits bounded affected change events/outbox, records audit. Changed criterion/policy invalidation is immediately visible through R3 current eligibility; no waiting for R5. Existing applicability cannot override live checks. Project generation alone is never stale reason.

Carry-forward is explicit baseline approval input item.carry_decision_id, never inferred. It must reference prior same-project human decision with identical criterion/policy/pins, currently valid evidence/QA and authorized input eligibility; baseline approver endorses this exact reuse. Changed inputs require new acceptance.review decision. Carried references preserve original decision and new baseline approval lineage. New weights are prospective scope policy, never rewrite old snapshot. Missing carry means no accepted weight until new human decision; duplicates counted once.

Acceptance uses R1 coordination and R3 live eligibility with source access; requires active baseline/policy/criterion and all mandatory QA, expected generation. Failed/missing QA rejects acceptance and current criterion weight0; missing policy/project scope unavailable. Direct progress calculator rechecks live pins and current access before delivery, never trusts derived projection alone. Old baseline read returns frozen snapshot + current access authorization, no mixing with current numerator. Zero denominator null basis points/status unavailable. Close freezes snapshot/is_closed; task/acceptance mutations conflict until explicit reopen new baseline. Unchanged carry allowed only under above human-approved rules.

- [x] `AcceptedProgressTest::test_exact_67_of_100`: three criteria weights67/20/13; reviewed QA+human accept first → accepted67,total100,basis_points6700. `test_done_failed_qa_zero`: source/task Done + failed required QA contributes0. `test_scope_120_keeps_old_100_snapshot`: explicit unaffected carry retains67/120 current and67/100 frozen old; no mutation old decision/weights.
- [x] `BaselineAcceptanceTest::test_policy_missing_zero_scope_and_stale_revision`: unavailable/null fraction, negative/zero/duplicateweights422, stale baseline pointer409. `test_concurrent_correction_cannot_commit_stale_acceptance`: same real row/generation lock prevents correction/QA/criterion activation race; acceptance after correction refuses. `test_draft_and_unrelated_change_do_not_stale`: unrelated project generation change does not invalidate unchanged pinned decision.
- [x] `test_changed_version_policy_or_hash_requires_new_human_decision`, `test_closure_freezes_and_reopen_new_version`, `test_repeated_and_concurrent_reopen_conflict`, `test_cross_criterion_carry_rejected`, `test_progress_read_is_coherent_and_nonmutating`, revoked viewer source grant coverage unknown, no ingest/review authority inherited, historical source access denied. Tests use guarded real PostgreSQL; concurrency visible committed synthetic fixtures only when necessary, retained counts documented; no immutable delete bypass.
- [x] Observe red with artisan test --filter='BaselineAcceptanceTest|AcceptedProgressTest' --colors=never. Implement R4 minimal atomic baseline/acceptance/calculator. Green + R1/R2/R3/access tests, scoped Pint; independent database/domain review and scoped fixes.

## R5 — Selective invalidation dispatcher va recovery

**Files:** Create backend/app/Domain/Lifecycle/{LifecycleInvalidationProcessor,ApplicabilityRebuilder}.php; backend/app/Console/Commands/DrainLocalLifecycle.php; backend/tests/Feature/LifecycleInvalidationTest.php. No source outbox or R1 immutable change rewrite.

**Interfaces:** ApplicabilityRebuilder::apply(TrustedActor $operator,object persistedChange):array{affected:int,stale:int,reason_codes:array}; inside authorized transaction, re-read authoritative scoped change, follow criterion/policy/link/QA/baseline pinned dependency IDs. Never auto-accept changed inputs. LifecycleInvalidationProcessor::drain(actor,project,int limit=20):array counts claimed/completed/retry/dead_letter/blocked/stale; internal claimNext(actor,project):?object and deliver(actor,project,outboxId,leaseToken):string. CLI app:lifecycle-drain {tenant} {actor} {project} {--limit=20}, local/testing loopback exact markeddev/test nonowner guard same E02 command; lifecycle.process explicit project purpose. Original change actor/purpose recheck from trusted immutable server record; client cannot choose handler/purpose. Evidence jobs requiring retrieval also ingestion.read; insufficient access blocked reason only.

Independent durable claim tx, SKIP LOCKED,60sUUID lease,5attempts5/30/120/600 retry then dead_letter, max100batch, stale fencing. apply+completion atomic. Allowed reason codes processing_failed/access_revoked/input_stale; payload/error text never persisted/output. Older jobs recompute latest current eligibility, never revert a newer stale generation to valid or erase newer invalidation. Unknown coverage stays unavailable; changed criterion/policy/evidence/QA requires fresh human decision even if QA later passes. Original immutable change retained; fixed derived materialization only.

- [x] `test_reject_to_merge_invalidates_only_linked_acceptance`: A stale,B valid, old frozen snapshot unchanged. `test_job_pending_and_revocation_never_deliver_cached_progress`: before drain R4 live checks alreadydeny/unknown/zero as applicable.
- [x] `test_passed_replacement_qa_cannot_reaccept`, `test_old_generation_job_cannot_restore`, `test_claim_crash_expiry_stale_fence_and_5failures`, `test_two_runtime_sessions_same_committed_row`, `test_processor_original_actor_and_membership_revoked`, `test_cli_guard_and_safe_fixed_counts`. No literal OS kill/load claim unless actually performed.
- [x] Red artisan test --filter=LifecycleInvalidationTest --colors=never, implement R5, green targetedR4/R3+authorization regressions/Pint; independent durability/security review. No backgroundservice/networkprovisioning, no automatic DLQ redrive.

## R6 — Contract, local handoff va yakuniy verification

**Files:** docs/api/openapi.yaml, docs/README.md, docs/decisions.md; create docs/contracts/lifecycle-data-dictionary.md, docs/operations/local-lifecycle.md, docs/acceptance/e03.md. Update approved plan checklist and approval record.

- [x] Document exact routes/purposes, schema tables/FKs/immutable/current state, exact ratio/display arithmetic, approved policy/carry-forward/QA/input eligibility, selective correction/revocation, retries/DLQ and retained synthetic fixtures. Real integration/AI/forecast/production DoD remain open; full E03/AC06 not claimed.
- [x] Lead full artisan test --colors=never, full vendor/bin/pint --test; PHP85 Composer validate --strict/check-platform-reqs, parse contracts/OpenAPI, route+HEAD+DTO parity, git diff --check and ignored-secret-file checks. No reinstall/network call. Test failures fixed through responsible worker/review.
- [x] Fresh most-capable whole-unit database/backend/security review, one combined fix wave/scoped re-review. Verify reviewed incremental000009–000014 dev migration through app:migrate-local; synthetic dev transaction rollback acceptance67/100/scope120/invalidation smoke without persistent admin/source employee. HTTP local18080 /up+unauthdenial remains liveness only.
- [ ] Deliver branch/unit/tests/limitations and commit request; no push/merge/deploy. E02commitapproval does not imply E03commitapproval. Preserve user changes.

## Concrete assertion examples

R4 red/green tests assert calculator DTO values directly, in addition to the fixture and race assertions above:

```php
$this->assertSame(67, $progress['accepted_weight']);
$this->assertSame(100, $progress['total_weight']);
$this->assertSame(6700, $progress['percentage_basis_points']);
$this->assertSame(120, $replacement['total_weight']);
$this->assertSame(100, $replacement['previous_snapshot']['total_weight']);
```

GET checks compare project generation and outbox count before/after; both must be unchanged. Concurrent progress/correction test uses committed fixtures and two PostgreSQL runtime sessions, ensuring the response represents one coordinated snapshot rather than mixing pre/post-correction pins.

## Execution handoff va self-review

Tavsiya/oldingi workflow: subagent-driven, sequential implementation owners, independent specialist review per task + final whole-unit review; workerlar recursively agents/branch/commit qilmaydi. Foydalanuvchi 2026-10-06da E03 kodini yozishni buyurdi; reja execution uchun tasdiqlandi va R1 boshlandi. AGENTS uchun specialist analysis/reviewlar saqlanadi; native execution faqat foydalanuvchi alohida tanlasa, mandatory specialist review bekor bo‘lmaydi.

Self-review: spec sectionsR1versionspolicy/R2dependencies/R3lineageeligibility/R4approvalprogresshistory/R5durableinvalidation/R6delivery bilan qamralgan. R3 →R4CurrentInputEligibility, R2 →R4DependencyGraph, R1 →R5immutablechange/outbox bindings bir xil; R4baseline+acceptance+calculator bir taskda bo‘lgani uchun replacement snapshot unimplemented future servicega bog‘lanmaydi. R1/R3 producer events R5handlerdan oldin durable bo‘lishi mumkin; R4livechecks pendingjobdan favorable natija bermaydi. Review Focus5 holati owning testsga bog‘langan. No placeholders; production policy/AI/real adapter/forecast explicitly excluded, not guessed.

## 2026-10-06 execution status

- [x] R1–R5 implementation/TDD and independent task review/re-review: no blocking findings.
- [x] R6 docs/contracts/local handoff prepared; formatting-only full Pint correction.
- [x] Controller final full suite218/4690/50.13s; Pint/strict Composer/platform passed.
- [x] R6 final whole-unit specialist review and I1/I2 scoped fix/re-review: no Critical/Important findings.
- [x] Ten reviewed incremental dev migrations000009–000014 and rollback synthetic smoke.
- [x] Final fixture/evidence reconciliation:49 synthetic tenants, zero nonsynthetic; detailed observed aggregates in acceptance/e03.md.
- [ ] User E03 commit approval; push/merge/deploy remain separate.

Original detailed steps remain reference requirements; no full E03/AC06 claim.

Final approved synthetic code unit verified2026-10-06. Deferred Minor100/101 other-receipt boundary test remains untested. Full E03 AI/production OPS and full AC06 remain open.
