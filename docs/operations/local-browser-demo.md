# Local synthetic browser workspace — F01

This operator-only tooling supplies fictional local data for the approved F01 React read workspace (FR-001/020/030/040/050/090/100/200; scoped AC-09). It does not establish full E03/E05/E09, live integrations, AI, financial activation or website acceptance. All inference remains local-only/unavailable. It never calls Kanban or a remote source.

The approved `browser-demo-v1` manifest is `backend/tests/Fixtures/browser-demo-v1.json`, SHA256 `733e5758aa5c0ec8e1ffacd4e4d2d5a060697e4552c80df15e024f9e14ff3324`. The command accepts only those reviewed bytes, version, fixed IDs and six names. No arbitrary grant/subject/tenant form exists. Do not change the manifest merely to bypass an existing-state refusal.

## Authority decision

On 2026-10-24 the user explicitly approved “Tasdiqlayman — ikkita tokensiz manba subyekti”, accepting the exact proposal `.superpowers/sdd/2026-10-24-frontend-api-integration-plan/task-5-producer-decision.md`, SHA256 `04242dc29c6d07b3e84591e8df9d99b08777a1c4792a1588f4fa25e06a51790d`. This is an explicit exception to removing all fixture production grants: current source provenance requires narrowly persistent authority on two noninteractive principals. Revocation continues to invalidate source evidence; no historical-proof bypass is introduced.

Exactly six interactive fictional personas are created: PM John, QA Andrew, developer Steve, BA Ivan, technical head Adam and BA head Victoria. All have linked employees, current project membership, explicit project.read, requirements.read, progress.read and same-project ingestion.read. PM reads QA/BA/management; QA reads QA; developer reads QA/management; BA reads BA/management; technical head reads QA/management and its technical department; BA head reads BA/management and its BA department. Module reads include the matching source.read purpose. No interactive persona receives write, fixture-production, organization, connector, identity, financial or tenant-wide grants. Department grants require matching memberships.

T6 native/browser diagnosis found that progress additionally requires EvidenceService's canonical ingestion.read purpose. The missing grant made all six progress responses unavailable despite the known 100-weight baseline. The fixture now includes this single-project read purpose for the six interactive personas only, yielding honest partial progress (0 accepted / 100 in scope, human_acceptance_missing); the two-producer exception is unchanged. The already-published selected DEV workspace requires a separately reviewed root-only additive six-grant/audit repair, never reseeding, editing its protected journal or rotating credentials. That legitimate canonical change means the old setup receipt intentionally refuses later CLI replay; preserve the existing credential files unchanged.

The separate source principal user `f0100000-0000-4000-8000-000000000201` / employee `...000000000200` has pm/qa/ba roles, narrowly project-scoped requirements.read, progress.read, qa.handoff.manage, qa.read, qa.source.read, ba.fixture.manage, ba.request.manage, ba.read, ba.source.read, management.action.manage, management.support.record, management.read and management.source.read. The assignment principal user `...000000000211` / employee `...000000000210` has department_head role, project-scoped requirements.read, progress.read, management.support.assign, management.source.read, management.read and technical-department-only management.department.manage. Both are explicitly labelled SYNTHETIC. They have no auth_subjects, auth_tokens, password or login credentials, and this command never creates any for them.

The source principal's four temporary lifecycle grants (requirements.manage, requirements.approve, policy.approve, baseline.approve) are revoked within the canonical transaction before any website credential publication. All data passes native lifecycle/QA/BA/management services and PostgreSQL constraints. An approved requirement, fictional DoD, 100-weight baseline and Steve's in-progress task are populated. QA has an open support-requested handoff with missing verification; BA is waiting for an unverified fictional customer, coordinated by Ivan. Completed, unverified management support does not accept the criterion or resolve QA/BA obligations. No passed test, customer answer or human acceptance is fabricated.

## DEV operator gate and paths

Implementation tests use only isolated monitoring_ai_test. The actual CLI is stricter: APP_ENV=local and monitoring_ai_dev only, existing private loopback PostgreSQL55432, monitoring_ai_app, public search_path, no URL/read/write override, expected marker, nonowner/NOSUPERUSER/NOBYPASSRLS. Root must independently review this unit and explicitly authorize its selected T6 DEV invocation. Do not run the command automatically, during tests or on application start. Never reset/down/fresh or restart the existing PostgreSQL/HTTP listeners.

Safe planned IDs: tenant `f0100000-0000-4000-8000-000000000001`, project `...000000000002`, policy `...000000000003`, manual synthetic source `...000000000004`, technical department `...000000000005`, BA department `...000000000006`. Six persona IDs are in the exact manifest. No lookup or merge by name/email occurs.

The planned operator-owned directory is `C:\Users\sobirjon.qurbonov\Projects\monitoring-ai\.local\browser-demo-f01`; the new output is `credentials-v1.json` there. It must already exist as a real directory, owned by the current Windows SID, with protected DACL allowing only that SID FullControl. Its ancestor chain must have no reparse points. Only a fixed NTFS volume is admitted. No network/removable drive, URL, UNC, device alias, ADS, control, wildcard, dot traversal or ambiguous trailing dot/space is admitted. The CLI never repairs a shared directory's ACL. Root can prepare this NEW EMPTY directory explicitly, verify its owner/protection and exact target absence, then run from backend:

```powershell
& ../.local/tools/php85/php.exe artisan app:setup-local-browser-demo --manifest="C:\Users\sobirjon.qurbonov\Projects\monitoring-ai\backend\tests\Fixtures\browser-demo-v1.json" --credentials-file="C:\Users\sobirjon.qurbonov\Projects\monitoring-ai\.local\browser-demo-f01\credentials-v1.json"
```

This command has NOT been run against DEV during T5 development. In this environment DB/test/network/process execution requires the authorized escalated tool context. Root owns the actual DEV gate and later browser acceptance.

## Protected output and recovery

The output contains secrets. Never print it, attach it, inspect raw tokens in chat, commit it or send it to logs. CLI output contains only safe tenant/project IDs and the locator. Tokens are opaque existing local-development tokens with eight-hour validity measured once when the protected journal is created. There is no rotation, renewal, shared default or password. Browser login consumes a selected token once into a server-only session; the website never stores the raw token.

The command preflights Windows owner/DACL/directory/reparse protections and reserves `<output>.pending` exclusively as a new regular file. The empty reservation is restricted to the current SID before generating any token, identity or grant. The protected pending journal stores the manifest hash, fixed identity bindings, expiry, six tokens and canonical recovery receipt. PostgreSQL and NTFS are separate resources: this is recoverable publication, not a cross-resource atomic transaction.

Canonical writes occur in one tenant-context transaction under an exact-workspace advisory lock. A complete per-tenant public-base-table inventory records ordered row counts/SHA256 hashes before commit. Only canonical records and the protected journal are used; no new schema or migration is needed. Source/policy/grant/token mutations cause conservative refusal on replay. Unknown existing tenants, incompatible identities, missing journals, changed manifest and mismatched credentials never trigger adoption or replacement.

Before DB commit, interruption rolls back canonical writes. Retain the protected pending journal and rerun the exact command with the same manifest/output locator; secrets are reused. After DB commit but before publication, the exact journal credential hashes, user/token IDs/expiry and canonical per-tenant table inventory must match before resuming. After publication, a hardlink publishes the pending file at the new final name without overwriting an existing file; the protected pending name is retained for recovery. Repeating the exact command verifies state and returns replayed without adding grants/facts or extending credentials. No automatic cleanup, deletion or implicit credential replacement occurs.

Malformed/torn journals, invalid snapshot types, more-than-eight-hour future validity and incompatible files fail closed. Do not edit a protected journal or remove a conflicting target to force progress; preserve both and request scoped operator inspection. Losing the journal or expiring credentials requires a separately reviewed explicit recovery/version decision; this command does not recover forgotten secrets or silently grant fresh access.

## Verification boundary

`BrowserDemoSetupTest` targets native services in TEST with outer transactions/savepoints and operator-only temporary local files. It covers manifest/path rejection, CLI TEST refusal, owner/ACL preflight, real canonical source records, read-only persona grants, tokenless producers, lifecycle grant removal, RLS and preservation of explicitly seeded unrelated records, exact replay and simulated interruptions before commit/after logical commit/before and after publication. Savepoint tests demonstrate the orchestration branches; they are not OS-kill/power-loss or persistent DEV/browser UAT. Credentials used by those tests are fictional TEST-only and never appear in assertion values or logs. Windows PowerShell is invoked at the explicit C:\Windows\System32\WindowsPowerShell\v1.0 path with a fixed system module directory; other Windows installations require an explicit reviewed adaptation. The trust boundary excludes a malicious current operator or administrator changing protected files/ancestor paths. Mid-write torn journals fail closed for operator inspection rather than claiming power-loss recovery. Replay after legitimate canonical changes is deliberately refused; it never overwrites changed facts or restores revoked grants.

Focused commands from backend:

```powershell
& ../.local/tools/php85/php.exe artisan test --colors=never --filter 'BrowserDemoSetupTest'
& ../.local/tools/php85/php.exe vendor/bin/pint --test app/Console/Commands/SetupLocalBrowserDemo.php app/Domain/Browser/Demo tests/Feature/BrowserDemoSetupTest.php
```

Never run the unannotated DEV-switching `LocalAuthenticationTest::test_explicit_bootstrap_creates_hashed_token_and_audit_atomically` during this task. Broad test scope and any actual DEV setup remain root-owned gates.
