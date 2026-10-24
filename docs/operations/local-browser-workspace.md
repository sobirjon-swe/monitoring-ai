# Local F01 browser workspace

2026-10-24. The implemented React read workspace is served at [local app](http://127.0.0.1:18080/app), on the same Laravel origin as `/browser/v1`. Local build assets are `/ui-assets`; PostgreSQL remains private loopback55432. Preserve existing listeners. [Contract](../contracts/browser-session-v1.md), [dictionary](../data/browser-f01-dictionary.md), [provisioning/recovery runbook](local-browser-demo.md) and [acceptance draft](../acceptance/frontend-f01.md) apply together.

## Operator use

Root completed the reviewed native DEV CLI setup with exit0 after restricted DEV/TEST identity and owner-only output preflight. DEV has43 applied migrations and0 pending; F01 added no DDL. Selected tenant is `f0100000-0000-4000-8000-000000000001`, project `f0100000-0000-4000-8000-000000000002`. The hash-pinned fictional manifest is `backend/tests/Fixtures/browser-demo-v1.json`, SHA256 `733e5758aa5c0ec8e1ffacd4e4d2d5a060697e4552c80df15e024f9e14ff3324`.

The protected operator-only credential locator is `C:\Users\sobirjon.qurbonov\Projects\monitoring-ai\.local\browser-demo-f01\credentials-v1.json`. Exactly six interactive entries were verified internally; never print, attach, commit or log the file or token values. Their fixed expiry is **2026-10-24T15:56:53.003310Z**, **20:56:53.003310 Asia/Tashkent**. This locator is not a website-fetchable asset. The SPA never reads it.

Open the app, choose UZ/RU/EN, intentionally enter the selected local development token and sign in. The token is submitted once in same-origin JSON; the server then supplies a cookie session. Select an authorized project and a permitted read module. Reload revalidates the session; logout invalidates that browser session while leaving the underlying token and independent sessions unchanged. Keep the visible synthetic and AI-unavailable context. The six fictional personas are PM John, QA Andrew, developer Steve, BA Ivan, technical head Adam and BA head Victoria; their exact scoped matrix is in the provisioning runbook.

An invalid/expired/revoked session removes private content. Obtain a fresh bootstrap before another login. No password, signup, role switch or automatic renewal exists. Expired tokens, missing/incompatible protected journals or legitimate later canonical changes require an explicitly reviewed recovery/version decision; do not delete output, alter grants or rerun setup to force access. Replay never extends expiry or restores revoked authority.

Root authenticated UAT found a bounded fixture omission: existing lifecycle evidence reads require project-scoped `ingestion.read`, absent from the initial six-persona grant list. The independently reviewed service/test correction and selected root-only DEV repair added exactly six project read grants and six native audits; the other105 canonical inventory entries and existing grant/audit rows were unchanged. Manifest JSON SHA256 remains `733e5758aa5c0ec8e1ffacd4e4d2d5a060697e4552c80df15e024f9e14ff3324`; the corrected code grant matrix changed. All six native progress reads and the actual PM screen now show partial0/100 with `human_acceptance_missing`. The browser capability allowlist, credentials, token expiry and journal are unchanged. This legitimate canonical grant delta makes the original conservative CLI receipt refuse replay; do not rewrite it or rerun setup to force acceptance.

## Local build and checks

Source is `frontend/spa`; production assets are generated locally into `backend/public/ui-assets`. With the already installed dependencies, run from `frontend/spa`:

```powershell
& 'C:/Program Files/nodejs/npm.cmd' test -- --run
& 'C:/Program Files/nodejs/npm.cmd' run typecheck
& 'C:/Program Files/nodejs/npm.cmd' run build
```

These are verified project commands; package installation, server startup, DB/network clients and tests require the authorized tool context in this environment. Do not introduce cross-origin HMR, remote assets, translation services, telemetry or hosted inference. Product AI remains local-only and unavailable; Kanban/source integrations remain read-only. Runtime guards deliberately support the approved loopback origin, not production deployment.

## Evidence limits and remaining work

The pre-setup old-source inventory covered457 candidate IDs over28 allowlisted tables, all absent with0 observed scopes/rows. Its result is LIMITED and provides no global preservation proof. The actual native setup physically committed; TEST recovery cases use outer savepoints and do not demonstrate OS-kill/power-loss recovery. Protected pending publication and refusal behavior remain as specified in the provisioning runbook.

Anonymous and authenticated UZ/RU/EN,375px mobile and812px landscape, logout/reload/project selection and six-persona navigation/reads were checked. Native drawer section selection restores focus to main after removing inert. Actual loopback HTTP security17 checks passed; a separate six-persona Bearer matrix passed126 GETs/316 assertions. Eight guarded actual-cookie revocation/expiry cases passed56 requests and exact restoration of all107 selected canonical fingerprints. These have distinct cookie/UI/API evidence boundaries. Fresh full PHP906 tests/24073 assertions, SPA79/typecheck/build and full static formatting/dependency/OpenAPI checks pass. One final whole-F01 review approved the bounded local read handoff with no actionable findings. F02 forms/reference selectors, live sources, local model activation, reports, forecasts, E09 release gates and finance remain separate work. F01 has no commit/push/merge/deploy permission.
