# F01 React website and authenticated API — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the approved local React read workspace, token-to-session login, permitted project navigation and populated synthetic six-persona browser acceptance.

**Architecture:** Laravel hosts the SPA and browser API on one loopback origin. A shared credential verifier supports existing Bearer authentication and new server-side sessions; browser adapters reuse accepted business read controllers, authoritative permissions and RLS. Six sequential reviewable tasks preserve the existing demo/API while adding the approved F01 behavior.

**Tech Stack:** PHP8.5.11/Laravel13.34/PostgreSQL17; React/react-dom19.3.1, TypeScript7.0.2, Vite8.3.5, @vitejs/plugin-react6.1.2, @types/react and react-dom19.3.0. Installed Node24.19.0. Test-only proposed pins: Vitest5.0.5, jsdom30.1.3, @testing-library/react16.3.3, @testing-library/dom10.4.2, @testing-library/user-event14.6.8, @types/node24.19.2; registry engines/peers inspected2026-10-24. Recheck resolved peers/lockfile at T3; optional compiler/canvas/telemetry packages are not requested.

**Spec:** [Approved F01 design](../specs/2026-10-24-frontend-api-integration-design.md); [approved image hashes/scope](../../design-mockups/frontend-api-images-v1.md). Repository requirements DOCX remains authoritative; FR-001/020/030/040/050/090/100/180/200, scoped AC-09/14/15. Full E08/E09 and F02 are not accepted by this plan.

## Global Constraints

- User-approved model policy: every supported dispatch explicitly `model="gpt-6.1-sol"`, `reasoning_effort="medium"`; no escalation/substitution. Thread tools, if authorized separately, use `thinking="medium"`. Workers have no children, branch or commit permission.
- Work on `frontend-api-integration`, base `85cb84cef843060fb44149e3615bb45455099ffe`. Preserve existing uncommitted approved design documents and ignored runtime. One implementer owns writes/TEST at a time; root owns reviews, runtime processes and actual DEV acceptance. No reset/down/stash/clean, push, merge or deployment.
- Existing local cluster is loopback55432, marked DEV/TEST, restricted runtime with separate migrator. **Both server startup and DB/network/test clients require authorized `require_escalated` context** in this environment. Do not restart or duplicate existing listeners. Never print credentials, tokens, environment/DSN dumps or raw evidence logs.
- Use one origin `http://127.0.0.1:18080`; SPA `/app`, static `/ui-assets`, browser contracts `/browser/v1`. Existing `/api/v1` remains Bearer-only. No public signup/self-grants/password/SSO, cross-origin credential relaxation, SSR, external assets or remote AI. All product AI remains local-only/unavailable; Kanban stays read-only.
- Raw token is accepted once in login JSON, never persisted/flashed/logged/returned. Session server-only binding; host-only HttpOnly SameSite=Lax cookie, explicit loopback-HTTP Secure=false, idle limit120minutes capped by underlying token expiry. Logout only this browser session, not the underlying Bearer token.
- Every request rechecks token/subject/user/tenant and current authority. Capability hints never authorize an operation. Preserve project/department/source/privacy and precise `[from,to)` boundaries. No fabricated zero counts, progress, AI, activity, source-health totals or employee rankings.
- F01 includes UZ/RU/EN system text, desktop/mobile and real read workflows plus selected persistent synthetic six-role workspace. F02 selectors/mutation forms remain the following required milestone; no F01 browser business-write routes or unrestricted fixture forms.
- All image samples are fictional; immutable approval hashes in the image index bind implementation. User-facing privacy and accepted-weight semantics override literal mockup sample counts/chrome. Code comments in English. No frontend dependencies/scaffolding before this plan review.

## Review Focus

1. Valid cookie plus invalid/other Bearer header never chooses a different identity; T1 `test_browser_never_falls_back_between_credentials`.
2. Token expiry/revocation/fingerprint changes after login invalidate the next read, including exact microsecond endpoints and HEAD; T1 `test_session_binding_rechecks_current_credential_chain`.
3. A slow response from an earlier project/session never repopulates private UI after switch/logout; T3/T4 `drops_previous_scope_response_after_switch_or_401`.
4. Permission-filtered partial pages/null denominators never become empty success, zero or green; T2/T4 `preserves_partial_null_and_source_privacy`.
5. DB commit/file publication interruption in demo setup neither duplicates grants nor exposes credentials; T5 `test_setup_recovers_protected_pending_output_without_duplicate_facts`.

## Shared protocol and file ownership

T1 owns credential/session/browser-runtime plumbing; T2 owns scoped discovery/read adapters and shared access predicate extraction; T3 owns SPA foundation/auth/i18n; T4 owns domain read screens; T5 owns synthetic setup and protected credential output; T6 owns final acceptance/docs and specifically assigned integration repairs only. Earlier files need a narrow root ownership return when later real evidence requires changes. No unbounded refactor of QA/BA/management domains.

Browser JSON uses existing safe `data`/`error` plus request ID conventions; sensitive responses are `Cache-Control: no-store`. These are the exact initial contracts:

| Method/path | Response / authority |
| --- | --- |
| GET `/browser/v1/session` | Anonymous200 `data:{authenticated:false,csrf_token}`; active200 authenticated identity and `expires_at`; a previously bound but invalid session returns401, clears its binding, then a new bootstrap may be anonymous200 |
| POST `/browser/v1/login` | Body exactly `{token:string}`, maximum512bytes token/1024bytes JSON; valid200 authenticated session DTO, generic invalid401, malformed422/oversize413/throttle429; no credential echo or old-input flash |
| POST `/browser/v1/logout` | CSRF-protected session invalidation,204; no underlying Bearer revocation; subsequent session bootstrap provides fresh CSRF |
| GET `/browser/v1/projects?cursor=...` | Fixed25 authorized rows ordered UUID, `data:{items:[{id,name,revision}],next_cursor:string|null}`; no global total; cursor actor/tenant-bound, invalid422 |
| GET `/browser/v1/projects/{project}/capabilities` | `project.read` required; `data:{project_id,purposes:string[],observed_at}` with fixed read-purpose allowlist; no raw policy/grant/hidden-person details |
| GET `/browser/v1/projects/{project}/...` | Mirror ONLY existing F01 requirement/task/progress and QA/BA/management read routes, defaults/query/DTO semantics unchanged; HEAD mirrors denial with empty body; no browser domain POST/PATCH aliases |

Session identity data: `{authenticated:true,csrf_token,user:{id,tenant_id,employee_id,display_name},expires_at}`; no global role/admin switch. CSRF uses installed Laravel13 origin-or-token behavior and explicit UI `X-CSRF-TOKEN`; no login/logout exceptions. Tests must exercise real protection beyond PHPUnit's default console bypass.

### Task 1: Shared credential validation and browser sessions

**Files:** Create `backend/app/Domain/Identity/VerifiedLocalCredential.php`, `LocalCredentialVerifier.php`; modify existing `LocalTokenAuthenticator.php` as a thin compatible wrapper. Create `backend/app/Domain/Browser/BrowserSessionService.php`, `backend/app/Http/Controllers/BrowserSessionController.php`, `backend/app/Http/Middleware/EnsureLocalBrowserRuntime.php`, `AuthenticateBrowserSession.php`, `backend/config/browser.php`, `backend/routes/browser.php`, `backend/tests/Support/BrowserTestCase.php`, `backend/tests/Feature/BrowserSessionTest.php`, `BrowserLocalGuardTest.php`; modify `backend/bootstrap/app.php` and session configuration only for the new browser group.

**Interfaces:** `LocalCredentialVerifier::verifyToken(?string): ?VerifiedLocalCredential`, `verifyBinding(array): ?VerifiedLocalCredential`; immutable result exposes `TrustedActor actor`, token/subject IDs, precise expiry and credential fingerprint, never raw secret/hash. `BrowserSessionService::start(Session $session,string $token): ?VerifiedLocalCredential`, `resolve(Session $session): ?VerifiedLocalCredential`, `end(Session $session): void`; `Session` is Illuminate's session contract. Closed binding version1 stores verified tenant/token/subject/user IDs, keyed fingerprint and last_seen_at. `BrowserTestCase::browserInputs(array $purposes=[]): array` returns keys `actor` (TrustedActor), `tenant`, `project`, `token`, `token_id`, `subject_id`, `user_id`, `employee_id`, `policy_id` (strings), solely isolated synthetic fixtures; never output token values.

- [x] **Step1 — RED:** Add tests for real token-once login/cookie reload/logout, fixation rotation after success, idle120minutes/token-expiry cap, all current chain revocations and credential replacement/rebinding. Reject duplicate/unknown login JSON keys, controls/non-string token and raw byte overflow before credential lookup. Assert invalid login cannot use a prior cookie identity; raw token absent from persisted session, responses, flashed input and captured logs. Add a focused `BrowserLoginPayload::token(string $raw):string` parser file if the existing strict JSON primitive cannot be reused without coupling to a business operation.

```php
// Behavioral anchors; use the actual auth fixtures and precise clock controls.
$this->assertSame(401, $expiredBoundRead->getStatusCode());
$this->assertNull($this->browserSession->resolve($session));
$this->assertStringNotContainsString($rawToken, $serializedSessionAndResponses);
// Logout invalidates the browser binding while the original valid Bearer still authenticates.
```

- [x] **Step2 — verify RED:** From backend run `& ../.local/tools/php85/php.exe artisan test --colors=never --filter 'BrowserSessionTest|BrowserLocalGuardTest'`; record missing implementation failures, not DB-unavailable as behavioral RED.
- [x] **Step3 — GREEN:** Extract one canonical current credential lookup under TenantContext, preserve `LocalTokenAuthenticator::authenticate(?string):?TrustedActor`. Compute domain-separated keyed fingerprint from exact credential chain/secret-hash identity; verify against fresh rows on each session request. Rotate ID/CSRF after successful secret validation, remove prior binding on failed login, never accept a binding JSON as login. Enforce server idle timestamp independent of file-GC timing.
- [x] **Step4 — guard/CSRF:** Mount the dedicated browser web group. Before session/credential DB lookup, reject nonlocal environment/host/configuration and unsafe cookie/session-driver overrides; compose existing `LocalManagementDatabase::assertRuntime()` chain rather than duplicate its DB protections. File storage in local runtime; array allowed only isolated tests. Reject any Authorization header on browser auth routes, retain Bearer-only API semantics. Apply origin-or-token protection including login/logout, bounded login throttle5/minute per configured local client, safe errors and no-store. Test encoded/malformed browser paths, HEAD, forwarded/spoofed identity and invalid configuration with zero credential-query attempts.
- [x] **Step5 — verify/review:** Focused tests plus existing IdentityMappingTest/AuthorizedContextTest and exact installed auth regression suites; scoped Pint. Independent Laravel/security reviewer gets physical preimage/diff, RED/GREEN logs and both spec/quality verdicts. No staging/commit by worker.

### Task 2: Authorized project discovery and browser read transport

**Files:** Create `backend/app/Domain/Access/ProjectReadScope.php`, `backend/app/Domain/Browser/BrowserProjectReader.php`, `backend/app/Http/Controllers/BrowserProjectController.php`, `backend/tests/Feature/BrowserProjectAccessTest.php`, `BrowserReadTransportTest.php`; modify `DatabaseAccessGateway.php`, `routes/browser.php` and only required pre-auth guard path matching. Existing business controllers/contracts stay authoritative.

**Interfaces:** `ProjectReadScope::apply(Builder $projects,TrustedActor $actor,CarbonInterface $instant): Builder` operates inside caller TenantContext and implements the exact current project.read policy. Use it from discovery and equivalent gateway project.read decision; retain other-purpose behavior. `BrowserProjectReader::page(TrustedActor $actor,?string $cursor): array` returns shared page DTO; `capabilities(TrustedActor $actor,string $project): array` returns fixed purpose hints. Builder is Illuminate database Query Builder. Allowlist: project.read, requirements.read, progress.read, qa.read, qa.source.read, ba.read, ba.source.read, management.read, management.source.read; department-specific hints only when that exact requested scope is authorized, never guessed from role names.

- [x] **Step1 — RED:** Assert project visibility requires active tenant/user/linked employee/membership/project.read policy/grant, respects tenant/project grant alternatives, not department-only/arbitrary module grants;25-page continuation excludes hidden totals. Add cross-tenant/cursor substitution and revocation between pages; preserve nil employee no-project response.

```php
$this->assertSame([$permittedProject], array_column($page['items'], 'id'));
$this->assertArrayNotHasKey('total', $page);
$this->assertSame(null, $incompleteModuleDto['visible_record_count']);
```

- [x] **Step2 — verify RED:** `artisan test --colors=never --filter 'BrowserProjectAccessTest|BrowserReadTransportTest'` with native PHP/elevated TEST context.
- [x] **Step3 — GREEN:** Set-based RLS queries, stable UUID continuation signed/bound to actor+tenant, parameterized SQL and precise captured UTC. Capability lookup uses bounded queries over fixed purposes, not per-record gateway loops; authoritative endpoint checks remain mandatory. Measure query-count shape for1/25/50 memberships and refuse N+1 growth before adding indexes.
- [x] **Step4 — read aliases:** Bind existing RequirementController/LifecycleTaskController/ProgressController and QA/BA/Management read handlers with identical route defaults and UUID/query validation. Do not call local HTTP or write a generic proxy. Extend affected pre-auth local DB guards to aliases, preserving encoded path and HEAD behavior. Test raw response parity for authorized complete/partial/private fixtures, source revocation at delivery, unauth401/forbidden403 and disallowed business-write methods. Existing Bearer API must still ignore browser cookies.
- [x] **Step5 — verify/review:** Focused two suites plus affected Access/QA/BA/management guard/read regressions, scoped Pint; record measured query shapes. Fresh task review of exact diff, both verdicts. Pass route/DTO names to T3/T4; no commit.

### Task 3: React foundation, authentication, navigation and local translations

**Files:** Create `frontend/spa/package.json`, `package-lock.json`, `tsconfig.json`, `vite.config.ts`, `index.html`, `src/main.tsx`, `src/App.tsx`, `src/api/client.ts`, `src/api/contracts.ts`, `src/auth/SessionProvider.tsx`, `src/auth/LoginPage.tsx`, `src/projects/ProjectPicker.tsx`, `src/layout/WorkspaceShell.tsx`, `src/i18n/{index.ts,uz.ts,ru.ts,en.ts}`, `src/styles.css`, `src/test/setup.ts` and focused auth/API/i18n tests. Create Laravel SPA shell route/controller/view as needed under `routes/web.php`, `Http/Controllers/BrowserAppController.php`, `resources/views/browser-app.blade.php`; mount it in bootstrap. Modify `.gitignore` only for owned node_modules/build artifacts and exclude .env/private credentials.

**Interfaces:** `ApiClient.request<T>(path:string,options:{method?:string,body?:unknown,signal?:AbortSignal}):Promise<T>` uses same-origin credentials, current CSRF and safe typed errors; no Bearer storage. `SessionView` is the shared protocol union; SessionProvider exposes `state`, `login(token:string)`, `logout()`, `reload()` and monotonic `scopeGeneration`. `ProjectSummary={id:string,name:string,revision:number}`; `Locale='uz'|'ru'|'en'`; `t(locale,key,params?):string`. Scope generation is incremented on session change/logout/project switch, and late requests cannot commit older generations.

- [x] **Step1 — dependency/setup with RED:** Inspect exact registry peer/engine metadata and dependency install scripts, then create exact-pinned manifest/lockfile in the owned SPA directory. Install project-local approved packages without global installs; do not blindly execute unreviewed postinstall hooks or download browsers. Include required testing-library/dom peer and Node24 types. Add Vitest+jsdom behavioral tests before components; Vite transpilation does not replace `tsc --noEmit`.

```ts
expect(screen.queryByText(previousProjectPrivateText)).toBeNull();
expect(localStorage.getItem('token')).toBeNull();
expect(sessionStorage.getItem('token')).toBeNull();
// Resolve the older request after switch/401 and assert it still cannot render.
```

- [x] **Step2 — verify RED:** From frontend/spa run `npm.cmd test -- --run` with manifest test script `vitest`; expected failures identify absent UI/client behavior. Package/runtime errors are setup failures, not behavioral RED.
- [x] **Step3 — GREEN:** Build approved login/picker/shell using semantic React DOM, existing navy/light palette, local system font/inline SVG. Keep token only in the live input/login call, clear it immediately on submit; never include it in URL/storage/debug tools/error render. Same-origin request helper permits only relative allowlisted `/browser/v1/` paths, sends CSRF, clears auth/private state on401, handles bootstrap without a401 loop. Untrusted evidence URLs are never arbitrary fetch targets. Logout clears all project state even on network uncertainty; stale data never remains presented as current.
- [x] **Step4 — serve/build:** Laravel serves `/app` and valid client navigation paths with a manifest-verified local Vite build at `/ui-assets`; no open file-path route, foreign URLs or cross-origin dev-server dependency. Missing build has explicit safe unavailable response. Preserve original standalone frontend demo. Implement UZ/RU/EN labels/aria/errors/date-number formatting; store only harmless locale preference. Keyboard/focus, paste, loading, invalid-token, no-project and logout states match approved access image; profile role text is not invented from display name.
- [x] **Step5 — verify/review:** `npm.cmd test -- --run`, `npm.cmd run typecheck`, `npm.cmd run build`, dependency tree/lockfile integrity; PHP shell-route tests and scoped Pint. Independent React/accessibility/security task review; do not claim browser UAT yet.

### Task 4: Real E03/QA/BA/management read workspace

**Files:** Create focused `frontend/spa/src/features/{lifecycle,qa,ba,management}/` reader/components/tests, `src/evidence/RecordHistory.tsx`, `src/api/readAdapters.ts`; modify App/shell/local dictionaries and styles only as needed. No domain mutation forms or fabricated aggregate endpoints.

**Interfaces:** Each domain exports `...Workspace({projectId:string,scopeGeneration:number}):ReactElement`. `readAdapters` consumes the exact T2 accepted read DTOs; import shared protocol types rather than redefine mismatched names. Domain response discriminators/coverage/reason codes remain explicit; parsers reject unsupported shapes instead of casting them into complete data. History accepts only authorized typed locator/versions supplied by the current response.

- [x] **Step1 — RED:** Render representative complete/empty/partial/denied/unavailable DTOs for every module. Assert baseline18/30 shows60% only for that supplied example, missing baseline remains unavailable/null; QA coverage is not pass rate/acceptance; management completed leaves underlying QA/BA blocker visible; BA unverified customer/scope obligation persists. Test unknown reason/long translated labels, query cancellation and out-of-order history results.

```ts
expect(screen.getByText(/qamrov|coverage|охват/i)).toBeVisible();
expect(screen.queryByText('0%')).toBeNull(); // missing denominator specimen
expect(screen.getByText(underlyingBlockerLabel)).toBeVisible(); // completed support specimen
```

- [x] **Step2 — verify RED:** `npm.cmd test -- --run src/features src/evidence`; assert actual failure of expected privacy/coverage behavior.
- [x] **Step3 — GREEN:** Requirements/tasks/progress and version/evidence detail; QA verification/handoff queue/typed history; BA clarification queue/history; management queue/history. Preserve each controller's pagination/query contract, names/privacy and null totals. Navigation/action visibility uses hints only, requests remain authoritative. No generic queue-to-all-entities assumption, silent source translation, unsafe HTML rendering, employee rank or fake source-health/activity/AI chart.
- [x] **Step4 — states/responsive:** Match approved workspace image for desktop/mobile composition with per-module headers and explicit current project context. Loading announcements, safe request IDs, contained table scrolling, long-ID wrapping, keyboard history navigation/focus return. Map server reasons through all three local dictionaries; source text stays original. Capability/revocation/data-change failures clear/hide unauthorized content, never preserve a favorable stale panel.
- [x] **Step5 — verify/review:** Focused components plus all SPA tests/typecheck/build; independent React/domain/privacy task review of exact diff and sample DTO provenance. No backend rule rewrite to satisfy UI snapshots.

### Task 5: Guarded persistent local synthetic workspace

**Files:** Create `backend/app/Console/Commands/SetupLocalBrowserDemo.php`, `backend/app/Domain/Browser/Demo/{DemoManifest.php,DemoWorkspaceService.php,DemoCredentialOutput.php}`, `backend/tests/Feature/BrowserDemoSetupTest.php`, `backend/tests/Fixtures/browser-demo-v1.json`, `docs/operations/local-browser-demo.md`. An additive schema file is permitted only if reviewed evidence shows existing canonical records cannot supply durable idempotency; never bypass source constraints or alter prior applied migrations.

**Interfaces:** `DemoManifest::fromFile(string):DemoManifest` validates closed bounded manifest; `DemoWorkspaceService::prepare(DemoManifest,string $outputPath):array` returns safe IDs/status/output locator, no tokens. CLI `app:setup-local-browser-demo --manifest=<local-file> --credentials-file=<new-local-file>` is local-only DEV, explicitly operator-run. Native domain fixture/services produce real accepted E03/QA/BA/management records, not test-class calls in production. Later T6 consumes safe persona/project IDs and protected credential-file paths.

- [x] **Step1 — RED:** Isolated TEST service tests prove six linked fictional personas, explicit project.read and role-appropriate source/read grants, populated baseline/tasks/QA failed or open handoff/BA waiting obligation/management support without changing underlying acceptance. Reject foreign existing tenant/user, incompatible manifest/version/hash, duplicate source facts, wrong DB/role/marker/config, URL/UNC/device input, unsafe credential target and self-grants. Existing unrelated source snapshots must be nonempty where a preservation claim is made.
- [x] **Step2 — verify RED:** `artisan test --colors=never --filter 'BrowserDemoSetupTest'`; guard actual DEV-only CLI rejection separately from injectable isolated-TEST service fixtures. No persistent DEV setup during task development.
- [x] **Step3 — GREEN:** Build a versioned reviewed exact-ID manifest for PM John, QA Andrew, developer Steve, BA Ivan, technical head Adam and BA head Victoria, fictional tenant/project and stable source versions. Reuse accepted domain services with explicit temporary fixture producer permissions; remove temporary setup authority before website credentials are released. No tenant-wide org admin grants copied to all roles. All data visibly synthetic; no Kanban call, real identity merge, automatic seed or app-start provisioning. Tokens use existing eight-hour local credential validity, no auto-renewal.
- [x] **Step4 — file/DB recovery:** Preflight a new operator-owned credential file with restricted Windows ACL before generating secrets. Never stdout/flash/log tokens. Use protected pending output plus transactional canonical writes and final publication; do not claim atomicity across filesystem and PostgreSQL. Test interruption before/after DB commit and before publication: rerun exact manifest verifies pending credential hashes/IDs and existing canonical state, resumes without duplicate grants/facts; incompatible state refuses safely. No implicit credential replacement, overwritten user file or destructive cleanup. Report failed credential protection before any committed identity/grant setup.
- [x] **Step5 — verify/review:** Focused recovery/idempotency/least-privilege/RLS tests, scoped Pint, exact manifest/hash/DDL inventory; independent Laravel/PostgreSQL/security review. Root separately inspects reviewed code/config/source coverage and explicitly authorizes the actual selected local DEV setup during T6 under existing user scope.

### Task 6: Integrated browser acceptance, final verification and handoff

**Files:** Create `docs/acceptance/frontend-f01.md`, `docs/contracts/browser-session-v1.md`, browser API/schema additions in `docs/api/openapi.yaml`, browser data dictionary entries and runbook; update docs README/decisions/roadmap. Store screenshots under `docs/design-mockups/frontend-f01-running/` without credentials. Ignored bounded DEV preflight/UAT scripts and sanitized evidence live in this plan's SDD workspace; no credentials in tracked artifacts.

**Interfaces:** Consume T1 session protocol, T2 route/DTO inventory, T3/T4 built UI and T5 exact reviewed manifest/output. No new feature design in this task; real introduced defects return to their original implementer with narrow ownership and fresh scoped re-review.

- [x] **Step1 — root preflight:** Inspect current source hashes, all task review verdicts, restricted actual DEV/TEST identity, exact pending migrations if any, configuration and protected output ACL. Existing PG/HTTP listeners are reused, not killed. Snapshot meaningful known DEV source scopes before/after; disclose zero/unobserved scope limits instead of asserting global preservation. Run the selected reviewed synthetic setup only after root records this gate; do not print credential files.
- [x] **Step2 — browser UAT:** Use available purpose-built browser tools/skill, actual loopback HTTP and six named personas. Read each token only inside bounded local browser entry automation without returning it to chat/tool logs. Verify login/reload/project selection, each permitted F01 module and denied scopes, logout/old-session rejection, expired/revoked access, CSRF cross-origin failure and same-origin/token semantics beyond PHPUnit bypass. Desktop/mobile UZ/RU/EN, keyboard/focus and all loading/empty/partial/unknown/unavailable states; compare running screenshots against approved images. No real source writes or model requests. Do not treat /up200 or kernel tests as browser UAT. Evidence boundaries, including HTTP rather than native-browser empty state, are explicit in acceptance.
- [x] **Step3 — final code gate:** Fresh full PHP suite once after integrated changes, all SPA tests/typecheck/build, full Pint, strict Composer/locked platform, npm lock/engine/peer checks and local OpenAPI/schema/route references. Preserve actual failures and fixes; repeat affected checks for new fixes, not cosmetic evidence edits. No favorable production latency/security/AI-quality claims from local functional tests. Fresh PHP906/24073, SPA79/type/build and static gates passed; no product source drift during full suite.
- [x] **Step4 — independent final review:** One whole-F01 branch/spec/quality/security review of complete tracked and untracked physical diff plus evidence; one consolidated real-fix wave and scoped re-review if needed. Final review approved spec and quality with no actionable findings, so no fix wave/repeated passing suite required. Final integrated source freeze unchanged; prior migrations and existing Bearer behavior preserved. Every resumed reviewer remained explicitly Sol Medium.
- [x] **Step5 — handoff:** Actual approved scope, code/tests/reviews/DEV setup/credential locator/expiry, coverage limits and UI differences recorded in acceptance/runbooks. F02 inventory remains separate. Intended98-file diff/private paths/structural secret signatures inspected without findings; staged set empty, branch preserved. Website opened on actual fictional PM progress view and sanitized screenshot saved. Completed F01 commit request is the final user handoff; approval remains pending, with no staging/commit/push/merge/deploy performed. This checkbox records the delivered milestone and request, not commit authorization.

## Plan self-review and execution handoff

Root self-review: approved token/session and image scope→T1/T3; project/scoped reads→T2/T4; UZ/RU/EN+mobile→T3/T4/T6; selected persistent nonempty workspace→T5/T6; RLS/revocation/CSRF/precise time→T1/T2/T5/T6; all five Review Focus items have named task tests. Existing source semantics are reused rather than duplicated, and all cross-task exported names above have an owning task. F02 is deliberately tracked outside this F01 plan.

Execution method is preserved: specialist implementers, one task at a time, fresh independent task reviews and final whole-unit review, all Sol Medium. Reuse this feature branch/current checkout to preserve approved uncommitted design and private local runtime; no parallel implementation writer. A separate worktree is only needed if actual isolation requirements arise, with existing user/repository constraints honored. Native SDD helpers may need a small PowerShell/Python portability wrapper on Windows; preserve their workspace/brief/preimage/diff/ledger semantics, never read another plan's ledger as this one's state.

**Status: user authorized execution on 2026-10-24 with “Ishni boshla” after this plan was presented.** Preserve specialist SDD/Sol Medium execution. T1 starts after its scoped baseline and conflict scan; later task checkboxes record actual reviewed completion. Do not ask again about already approved plan/images/React/token-session/synthetic-workspace choices. Dependency installation and persistent DEV setup occur only in their assigned reviewed tasks.
