# Uzbek demo v1 concrete activation review

Manifest: `backend/tests/Fixtures/uzbek-demo-v1.json`; SHA256 `bbd0d458fb183a84528d9da7049d7bdc3c98f3923b9b72d6fd3044300f6affe9`.

Tenant: `f1200000-0000-4000-8000-000000000001`. Projects use final UUID components `000000000010` (Savdo CRM), `000000000011` (Ombor boshqaruvi), `000000000012` (Mijozlar portali). All project and tenant names visibly contain DEMO. Exactly twelve fictional employees use the approved full names in the manifest. Every employee belongs to all three projects and has a descriptive catalog role. These catalog roles do not grant system authority.

| Principal | Employee | Authority scope and lifetime |
|---|---|---|
| Interactive PM, user `...200` | Azizbek Karimov | All three new projects: `UzbekDemoGrants::READ` plus `roles.manage`. Tenant: `role_catalog.read`. Token and every grant expire at the same journal timestamp, eight hours after initial preparation. |
| Interactive admin, user `...201` | Behzod Rahimov | All three new projects: `UzbekDemoGrants::READ`. Tenant: `role_catalog.read`, `role_catalog.manage`. Same bounded eight-hour lifetime. |
| Tokenless source, user `...500` | Sardor Ismoilov | All three new projects: exactly `UzbekDemoGrants::SOURCE`. No auth subject or token. Retained original source authority supports canonical QA, BA and management provenance. |
| Tokenless reviewer, user `...501` | Dilnoza Qodirova | All three new projects: exactly `UzbekDemoGrants::REVIEWER`. No auth subject or token. Retained original reviewer authority supports evidence, mandatory lifecycle QA observations, human acceptance, QA definition/plan review and current selection. |
| Tokenless assignment producer, user `...502` | Behzod Rahimov | All three new projects: exactly `UzbekDemoGrants::ASSIGNER`; only new technical department: `management.department.manage`. No auth subject or token. Retained original bounded assignment provenance. |

Technical users have explicit technical display names and reuse employee IDs; they do not create additional displayed employees. Native BA request and management action services require explicit BA and PM system responsibilities, so Sardor additionally has these two system roles on the three new projects. His descriptive catalog role remains Backend dasturchi. System roles are descriptive responsibilities combined with separate user grants, never standalone authorization. No interactive user shares Sardor's employee ID. Technical privileges are never attached to interactive user IDs. No old F01 principal, grant, manifest or receipt is changed.

`SOURCE`: project.read, requirements.read, requirements.manage, progress.read, ingestion.accept, ingestion.read, qa.fixture.manage, qa.execution.record, qa.handoff.manage, qa.read, qa.source.read, ba.fixture.manage, ba.request.manage, ba.read, ba.source.read, management.action.manage, management.support.record, management.read, management.source.read.

`REVIEWER`: project.read, requirements.read, progress.read, ingestion.read, evidence.review, acceptance.review, qa.plan.manage, qa.read, qa.source.read.

`ASSIGNER`: project.read, requirements.read, progress.read, management.support.assign, management.read, management.source.read.

`READ`: project.read, requirements.read, progress.read, ingestion.read, qa.read, qa.source.read, ba.read, ba.source.read, management.read, management.source.read, employees.read, roles.read.

Source setup additionally receives requirements.approve, policy.approve and baseline.approve on the three new projects. All nine temporary grants are revoked with audit revision two before the credential output is published. Retained requirements.manage supports the native task producer and request workflow; technical source is tokenless.

Each project has three approved criteria and four native tasks (done, in_progress, blocked, unstarted), an open QA support request, open customer BA clarification and completed support action. Savdo CRM and Ombor boshqaruvi each have an approved weight-100 baseline, one weight-40 criterion accepted through canonical ingestion receipt → reviewed evidence → mandatory passed QA observation → native human acceptance. Each also has a separately reviewed native three-criterion QA plan, current build/environment, one passed execution, one failed execution and one unexecuted criterion. Mijozlar portali deliberately has no approved baseline, so progress is unavailable.

This is an explicit new fixture authority exception to review independently. It does not inherit the prior fixture exception. Revoking retained original source/review grants still invalidates dependent native results. No external sources or AI calls exist.

Activation is explicit DEV-only CLI `app:setup-local-uzbek-demo --manifest=<absolute reviewed manifest> --credentials-file=<absolute protected file>`. It uses the existing owner-only NTFS credential output primitive. LocalManagementDatabase checks database marker, local endpoint and runtime identity. Neither command nor service returns secrets. TEST uses the service only; the command refuses TEST and production. Replay requires the exact immutable protected receipt and unchanged canonical tenant snapshot, never renews tokens/grants, and refuses expired journals or conflicting rows. Source receipts are immutable native rows with synthetic manual source provenance. The service captures F01's complete tenant-table fingerprint before/after preparation; root also keeps a separate before-activation fingerprint.

No DEV invocation is authorized by this file alone. Root must review this concrete matrix and fresh TEST evidence before selecting the explicit DEV invocation.

TEST evidence (2026-11-02): `php vendor/bin/phpunit tests/Feature/UzbekDemoSetupTest.php tests/Unit/UzbekDemoManifestTest.php` on isolated monitoring_ai_test: 6 tests, 138 assertions passed. Includes accepted weight 40/100, native QA executed criteria 2/3 and all-required-passed 1/3, missing baseline, exact people/catalog membership counts, protected replay without changes, expiry without renewal, revoked original acceptance authority, cross-tenant denial/RLS, conflicting tenant refusal/F01 sentinel preservation, command TEST/production refusal, and empty-marker refusal before output reservation. Unit test observed missing manifest class (red), then passed; empty-marker native guard also observed red before tightening the runtime contract. Pint applied only to these newly added PHP files.
