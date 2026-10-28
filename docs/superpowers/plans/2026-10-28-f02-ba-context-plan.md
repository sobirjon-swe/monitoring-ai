# F02-01 BA Context Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: use superpowers:subagent-driven-development; complete each task's red/green cycle and independent task review before the next dependent task. Preserve existing uncommitted design artifacts; workers never stage, commit, branch or dispatch children.

**Goal:** Let a currently authorized BA append human context to one visible clarification, with strict idempotency, privacy and honest conflict handling, in the approved local React workspace.

**Architecture:** A single same-origin browser POST delegates to existing BA domain authority and receipt machinery after enforcing the exact `context.add` kind. A React form binds a frozen body/key/revision to each attempt and respects session/project scope. A separately provisioned synthetic tenant supplies a BA writer and canonical source proofs for actual local UAT; existing F01 credentials, grants and recovery journal stay untouched.

**Tech stack:** Existing Laravel/PHP/PostgreSQL, React/TypeScript/Vite, existing local Windows runtime and dependencies; no new package, external AI API, external source write or deployment.

**Spec:** `docs/superpowers/specs/2026-10-28-f02-ba-context-design.md`, approved 2026-10-28; image `docs/design-mockups/f02-01-ba-context-v2.png` SHA256 `12456bc6cc153e7c996e7db488992980a8a1adfaf44be9201397f782d22383f1`.

## Global constraints

- All agents use `gpt-6.1-terra`, reasoning `medium` until the account reset; then `gpt-6.1-sol`, `medium`, as the latest user instruction. No model substitution or nested dispatch. Lead tracks the reset time; reminder is set for 2 November 20:15 Asia/Tashkent.
- Branch `ba-workflow-frontend`, base `89f745385892b2750d72f960f33ee3d67aec70fb`. The untracked approved design and images are user-authorized work; preserve them. Use this checkout to retain the reviewed local runtimes and protected F01 files; no reset, stash, worktree cleanup, shared DB reset or production mutation.
- Browser accepts only the exact POST `projects/{project}/ba/clarifications/{clarification}/events` with kind `context.add`. Body is closed: `clarification_id`, `expected_revision`, `kind`, `context`; Idempotency-Key required and source revision header forbidden. Same-origin cookie/CSRF/local runtime guards and authoritative BA role, project, source, original proof, current read privacy and actor scope remain mandatory. Capability hints never grant authority.
- Preserve request/answer/review state and immutable history. Original F01 six token users remain read-only; do not modify its tenant, credentials, fixed manifest or snapshot journal. Kanban and other real sources stay read-only, and product AI remains local-only/unavailable.
- Secrets, raw context, token and idempotency key never go in URLs, storage, logs, reports or browser screenshots. Context is nonblank and at most4000 UTF-8 bytes; server secret validation remains authoritative.
- A post-send network failure, abort, malformed success response or ambiguous5xx retains one exact attempt snapshot/key while authorized; no second attempt for that record until outcome is resolved. Conflict/forbidden/session changes have distinct states; 401/logout/project change/revocation privacy clearing takes precedence. No exact context delivery claim from hidden/paginated history.
- No worker commits; ask for F02 commit at completed handoff unless authorized separately. Approval of this design is not push/merge/deployment permission.

## Review focus

1. A valid unrelated BA event kind on the approved path must be rejected, not accidentally forwarded. T1 test: `request.withdraw` with valid controls/body returns422 and leaves event/audit counts unchanged.
2. A success status with truncated/invalid JSON after server commit is unknown, not failure permitting a fresh key. T2 test: frozen attempt retries same path/body/key and blocks new submit until resolved.
3. Two actors/project scopes, role or original-source proof revocation, or a stale original response must never expose old context or grant access. T1/T2 tests cover denied replay and stale scope response/fence.
4. Exact stale CAS and reused key with changed body both return409; neither means the same thing as successful storage. T1/T2 tests cover both and preserve the active-session draft without silent retry.
5. A workspace setup interrupted around physical DB commit/file publish must not publish mismatched or overwritten credentials; later legitimate context writes make an exact original setup receipt conservatively refuse replay. T3 tests cover rollback/pending/committed/published/torn/expired/mismatch and disclose savepoint versus physical crash boundaries.

## Task 1 — browser BA context transport

**Files:** create `backend/app/Http/Controllers/BrowserBaContextController.php` and `backend/tests/Feature/BrowserBaContextTest.php`; modify `backend/routes/browser.php`, `backend/app/Domain/Browser/BrowserProjectReader.php` and existing focused browser capability/route tests only as needed.

**Consumes:** `BaController::write`, `StrictBaJson`, `BaContract`, `BrowserProjectReader::PURPOSES`, existing project-scoped BA tests and session guards. **Produces:** `POST /browser/v1/projects/{project}/ba/clarifications/{clarification}/events` with route default `ba_operation=event`; only `context.add` accepted; response exactly existing BA `data` envelope/status; capability purpose `ba.request.manage` only as a fixed UI hint.

- [ ] TDD red: use PostgreSQL `BaTestCase::baInputs()` plus current browser login to assert 201, exact replay200, unchanged state/current request/answer/review, history event, correct byte limit, 409 stale/key-body mismatch and other event kind422 without writes. Add actor/project/role/source revocation and cross-tenant denial, cookie/API separation, missing/cross-site CSRF, strict headers/unknown/duplicate JSON, no Authorization fallback and safe errors.
- [ ] Run `../.local/tools/php85/php.exe artisan test --colors=never --filter BrowserBaContextTest` from `backend` in authorized native TEST context; demonstrate an absent-route/unsupported-capability red, not infrastructure failure.
- [ ] Implement one typed browser controller/route using existing strict parser/BA service; prevent other BA event operations before delegation. Add only the write-purpose hint; preserve existing read aliases, source and domain authorization, method restrictions, server byte bounds and secret gate.
- [ ] Run focused tests, affected BrowserProjectAccessTest/BrowserReadTransportTest/BaApiTest, scoped Pint; report actual tests/assertions and any failures. Independent Laravel/security reviewer receives exact physical diff and verifies both spec and quality.

## Task 2 — context form, state and localization

**Files:** create `frontend/spa/src/features/ba/BaContextForm.tsx` and focused `frontend/spa/src/features/ba/BaContextForm.test.tsx`; modify `frontend/spa/src/App.tsx`, `frontend/spa/src/api/client.ts`, `frontend/spa/src/features/{QueueWorkspace.tsx,readWorkspace.tsx}`, `frontend/spa/src/features/ba/BaWorkspace.tsx`, `frontend/spa/src/evidence/RecordHistory.tsx`, `frontend/spa/src/i18n/{read.ts,uz.ts,ru.ts,en.ts}`, `frontend/spa/src/styles.css` and their focused tests only where needed.

**Consumes:** T1 exact browser route/capability and BA queue row `clarification_id` plus aggregate `revision`; existing `SessionProvider` scope generation, `useRead`, `RecordHistory`, `ApiError`. **Produces:** one accessible context form for a visible BA row and `ApiClient` operation bounded to the exact path, body and Idempotency-Key; no generic arbitrary header or business POST.

- [ ] TDD red: test authorized versus hidden action, UTF-8 byte count/empty/4000 boundary, context-only body/CSRF/key, 201/replay200 reload without invented revision, duplicate-click, stale409 preserving input, key-body conflict, 403/401 and privacy clearing, and locale/keyboard/focus.
- [ ] Add deferred promise tests where a server commit is followed by abort/network/invalid success JSON/5xx; exact path/body/key replay only, no second attempt until resolution, no stale project/session response overwrites current state. Inject deterministic key generation for tests while production uses secure browser randomness; never persist key/context in storage or log.
- [ ] Implement in smallest components. Pass the already-fetched `ba.request.manage` hint from `ProjectWorkspace` only into BA views, and render a neutral workspace heading where write action is available while other views retain read-only labels. Recheck capability after scope changes; after 201/200 refetch queue and authorized history without claiming the new event must appear on the first page. Correct only human `context.add` history label to neutral context; other source/management contexts retain their accurate labels. Match approved v2 desktop/mobile interaction and explicit synthetic/unverified labels; disable save during conflict until current state is rechecked and user explicitly resubmits.
- [ ] Run all existing SPA tests, `npm.cmd run typecheck`, `npm.cmd run build`; document failures and run independent React/accessibility/privacy review of exact diff. No model/network/service dependencies added.

## Task 3 — isolated fictional BA writer workspace

**Files:** create a distinct `backend/tests/Fixtures/ba-context-demo-v1.json`, `backend/app/Domain/Browser/BaDemo/{BaDemoManifest,BaDemoWorkspaceService}.php`, `backend/app/Console/Commands/SetupLocalBaContextDemo.php`, `backend/tests/Feature/BaContextDemoSetupTest.php`, `docs/operations/local-ba-context-demo.md`; reuse `DemoCredentialOutput` without broadening it or changing F01 manifest/receipt behavior.

**Consumes:** existing `TenantContext`, `LocalManagementDatabase`, native lifecycle/BA/identity/audit services and protected `DemoCredentialOutput`. **Produces:** explicit local-only `app:setup-local-ba-context-demo` with a new fixed synthetic tenant/project/BA writer/token and an exact original-proof producer that has no login/subject/token. A separate versioned protected journal and new output path are mandatory. Worker selects and documents noncolliding fixed UUIDs, exact manifest bytes/hash, roles and project-scoped grants, then reviewer verifies them before root DEV use.

- [ ] TDD red: reject real/nonlocal DB/marker/role, existing tenant/IDs/path, malformed/altered manifest, unsafe ACL/reparse/hardlink, missing original source proof, expired/torn/mismatched journal, untrusted producer identity and F01 tenant/journal changes; assert no secret output. Verify one BA token has only project.read, ba.read, ba.source.read, ba.request.manage and required exact proof; `ba.request.manage` authorizes more than context at the domain API, so only browser alias is context-only. Producer stays tokenless with minimum native source/original-proof grants; remove temporary lifecycle setup grants.
- [ ] Implement guarded local CLI/native setup with reserved owner-only pending file, same-tenant advisory transaction, minimal existing native BA/lifecycle fixture services, canonical snapshot/credential hashes, immutable prior authority proofs, fsync and no-clobber publication. Never widen F01 helpers by changing fixed F01 identity assumptions. A committed business context event legitimately makes an old exact snapshot receipt refuse replay; never rewrite history or journal to force acceptance.
- [ ] Test before/after database commit/publication failures, safe resume and no duplicate facts, replay after mutation, expiry, collisions/concurrency, current proof revocation and no overwrite. Clearly separate savepoint-based tests from physical-commit/crash/power-loss proof. A physical commit harness, if safe and necessary, uses only exact selected TEST IDs/path and reviewed cleanup; no shared/DEV reset.
- [ ] Run focused setup tests, F01 setup regression with its artifact side effects inspected/preserved, scoped Pint and independent security/PostgreSQL review. Root alone authorizes the actual selected synthetic DEV setup after preflight; no credentials printed.

## Task 4 — integration, UAT and handoff

**Files:** create `docs/acceptance/f02-01-ba-context.md`; update relevant `docs/README.md`, `docs/api/openapi.yaml`, BA/browser contracts and `docs/operations/f02-workflow-inventory.md`; add actual sanitized desktop/mobile screenshots under `docs/design-mockups/` after user-approved image comparison.

- [ ] Root confirms restricted local DEV/TEST identities, source code/reviews, protected new output ACL and F01 untouched boundaries; guardedly provisions only the reviewed isolated synthetic tenant. Capture selected tenant IDs and credential locator/expiry, never secret values. Use real local HTTP + browser, not `/up` as proof.
- [ ] UAT authorized writer, read-only and wrong project/tenant/source, CSRF, stale409, exact replay, unknown outcome, session/revocation privacy; visually compare approved v2 desktop/mobile UZ and exercise UZ/RU/EN. Record limitations of incomplete/withheld history, source and audit scopes. No real Kanban or external AI calls.
- [ ] Run appropriate integrated PHP/SPA checks, full Pint, TypeScript/build and docs/OpenAPI validations once on reviewed freeze; do not repeat passing broad suites without a new failure or source change. Preserve earlier measured evidence and do not claim production security/performance/AI readiness.
- [ ] One independent whole-F02 review plus one consolidated fix/re-review wave if real findings; inspect full intended tracked/untracked diff and secret exclusions. Record final status, remaining F02 milestones, risks and local URL; request commit under AGENTS.md. Do not push/merge/deploy without separate authorization.

## Self-review and execution

Spec coverage: route/domain/permissions→T1; UI/fences/retry/history/locales→T2; separate approved synthetic writer/recovery→T3; real browser/HTTP/security/documentation/review→T4. Review-focus cases are pinned in task tests. Shared interfaces are exact route, purpose, body and `ApiClient` context operation; ordered T1→T2→T3→T4 prevents concurrent shared-file writers. The user approved the written design, image and continued implementation on 2026-10-28; plan proceeds without another permission prompt. F02-01 only, other workflows remain inventoried.
