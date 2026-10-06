# E04 lokal Windows parser runtime

2026-10-06. FR-060/061 va FR-120ning deterministik qismi, E04-OPS/QA, AC-03/11 foundation doirasi. Bu lokal synthetic diff parser; LLM, source-system integration, AST/static quality, semantic acceptance, effort yoki AC-04 implementatsiyasi emas. AI interpretation `unavailable`.

## Ishga tushirish chegarasi

`DEVELOPER_ANALYSIS_ENABLED` default `false`. Flagning o‘zi yetarli emas: `RunnerAvailability::assertReady()` har admissionda trusted brokerning `--check`ini chaqiradi. Broker har safar copied runtime/parser SHA256, to‘liq inventory, descriptor hashes, exact owned AppContainer SID/path va loopback-exemption holatini qayta tekshiradi. Actual probe metadata ayni manifest hashga bog‘langan bo‘lishi va barcha activation gate o‘tishi kerak. Har execution oldidan shu tekshiruvlar takrorlanadi. Nosozlik HTTP503; bare Python yoki tashqi AI fallback yo‘q.

Laravel fixed absolute owned Python va trusted broker faylini argument-array Symfony Process bilan ishlatadi: `-I -B -S`. API input argv/path/environmentni tanlamaydi. Parent environmentdagi keylar explicit `false` bilan olib tashlanadi; faqat Windows directory va fixed owned/System32 PATH beriladi. Restricted childga Windows talab qilgan `LOCALAPPDATA` exact verified owned profileAC pathdan beriladi; parent credential/environment tiklanmaydi. Actual minimal broker-parent environment bilan native manifest/profile verification o‘tdi. Input1MiB, stdout256KiB, stderr8KiB bounded; raw stderr log/responsega chiqmaydi. Input/output contract Laravelda validatsiyadan o‘tadi; result provenance va authorization durable processorning alohida tekshiruvi.

AppContainer capabilities bo‘sh. Native `STARTUPINFOEX` SECURITY_CAPABILITIES, exact uchta inherited anonymous pipe handles, creation-time bitta JOB_LIST va child-process restriction attribute qo‘llaydi. Child suspended yaratiladi va real token/SID/capabilities hamda job membership/limits parent tomonidan tekshirilib keyin resume qilinadi. Job creation-time binding broker crashdan oldingi orphan windowga yo‘l qo‘ymaydi. No breakaway, active process1, process committed memory128MiB, user-mode CPU3s, kill-on-job-close. Broker wall10s va bounded parallel stdin/stdout/stderr drainingni boshqaradi. Native CPU limiti user-mode CPU, total kernel+user CPUning aniq hard capi deb talqin qilinmaydi. Memory limiti commitni rad etadi; avtomatik process kill kafolati emas.

Parser faqat stdin/stdout va process memory ishlatadi: writable scratch yo‘q. Copied executable/stdlib/DLL/parser tree va newly-created exact owned package profile filesystem (AC va siblings) protected read/execute DACL hamda medium no-write-up mandatory label bilan harden qilinadi. `site-packages`, `.pth`, `sitecustomize.py`, `usercustomize.py` va bytecode/cache nusxalanmaydi; `-S` site startupni ham o‘chiradi. Profile registry uchun universal read-only/no-write kafolati berilmaydi. Oddiy Windows system resourceslariga mutlaq no-host-read kafolati berilmaydi. Trusted provisioning user/admin runtime/evidence fayllarini boshqaradi; hash→launch orasidagi host-admin/ayni user TOCTOU threat boundary ishonchli host hisoblanadi.

## Scoped provisioning

Approved development source Python3.12.14: `C:/Users/sobirjon.qurbonov/.cache/codex-runtimes/codex-primary-runtime/dependencies/python`. Bu observation, production DEC-04 emas. Copies faqat repository `.local/e04-runtime`; source cache ACLlari o‘zgarmaydi. Fixed profile `monitoring-ai-e04-parser-v1`. Existing unknown profile/root collision fail-closed; avtomatik adoption, deletion yoki repair yo‘q. Native API qaytargan owned profile path ownership recordda saqlanadi. Shared Packages/LocalAppData parent ACLlariga tegilmaydi.

Repository rootdan explicit commands:

```powershell
& 'C:/Users/sobirjon.qurbonov/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe' -I -B -S analytics/runtime/provision_local.py 'C:/Users/sobirjon.qurbonov/.cache/codex-runtimes/codex-primary-runtime/dependencies/python'
& 'C:/Users/sobirjon.qurbonov/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe' -I -B -S analytics/runtime/provision_local.py --refresh-owned
& 'C:/Users/sobirjon.qurbonov/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe' -I -B -S analytics/runtime/probe_worker.py
```

`--refresh-owned` avval current owned manifestni tekshiradi, reviewed parser/probe nusxalarini yangilaydi va activationni bekor qiladi; drifted/unknown tree uchun recovery bypass emas. Sandboxdan per-user profile creation HRESULT0x80070002 qaytardi; scope bo‘yicha tasdiqlangan native user execution kerak bo‘ldi. Provisioner faqat exact owned pathsda native ACL APIs ishlatadi. Dependency/model download, VM/WSL installation, global firewall/service change yoki loopback exemption qo‘shish kirmaydi.

## Actual activation dalillari

Safe records ignored `.local/e04-probes/actual-probes.json` hamda owned runtime `activation.json`da. Unit tests activation evidence emas. Actual startup prerequisite o‘tmasa, keyingi denial probes false/untested qoladi: import/launch failure security pass bo‘lib hisoblanmaydi.

- Actual parser benign synthetic contract fixturega exact expected result berishi; ikki run runtime/profile inventoryni o‘zgartirmasligi.
- Real suspended token/SID/zero capabilities va exact job limits, precise inherited handle policy.
- Broker-positive controlled127.0.0.1 loopback listener; child permission denial.
- Alohida broker-positive, genuinely independent controlled LAN network stack; child permission denial. Same-host Ethernet address loopback bo‘lishi mumkin va outbound proof emas.
- Private synthetic checkout/config canary read denial; hech qanday `.env`/credential content o‘qilmaydi.
- Owned runtime va profile create/modify/rename/delete/ACL-change denial, broker maintenance positive control; child-spawn denial.
- Broker129MiB committed allocation success/free va child8MiB success/129MiB commit denial; CPU/wall/stdout/stderr termination safe measurements.
- Independent observer brokerning creation-return, verified-before-resume va post-resume crashlarini keltirib chiqarib child terminationni tekshiradi; observerga job handle berilmaydi.

Controlled endpoint foydalanuvchi tomonidan tayyor bo‘lsa, operator `.local/e04-probes/outbound-target.json`ga `host`, `port`, `independent_stack=true`, `user_authorized=true`, `topology_evidence`ni yozadi. Target RFC1918 literal IPv4, boshqa host/network stack, ephemeral controlled listener bo‘lishi kerak. Broker reachability positive control zarur. Probe source/public endpointlarga ulanish uchun mo‘ljallanmagan. Topology evidence operator tasdiqlagan provenance; child self-report yoki same-host IPning o‘zi emas.

2026-10-06 joriy dalil: owned provisioning va ACL/hash verification ishladi; Windows error203 uchun exact owned `LOCALAPPDATA` tuzatildi va actual suspended AppContainer token/job limits o‘tdi. Keyin child Python start oldidan `0xc0000142 STATUS_DLL_INIT_FAILED` bilan tugadi. Parser va keyingi actual probes gate hali o‘tmadi. Independent non-loopback target ham hali yo‘q. Runner disabled/503; running vertical E04 completion claim yo‘q. Loader root cause mustaqil investigationda. Qolgan native/probe differences review va actual verification talab qiladi.

## Verification va recovery

### Safe broker failure protocol

Broker exit0 faqat complete valid bounded JSON natijasi uchun. Exit2 faqat trusted brokerning exact `output_json` yoki `output_limit` kategoriyasi uchun; qolgan policy, input, token/job, loader, pipe, timeout va stderr-limit failurelar exit1. Har ikkala failureda stderr ayni fixed `runner_unavailable` matni, hech qanday raw input/output/reason yuborilmaydi. PHP stderrni reason sifatida parse qilmaydi.

`LocalParserRunner` exit2 yoki stdout size/JSON/schema violationni no-argument `InvalidParserOutput` domain exceptionga o‘tkazadi. Exception faqat fixed `Invalid parser output.` message saqlaydi; raw payload, stderr va previous exception yo‘q. Exit1, process timeout/transport va stderr quota safe HTTP503 bo‘lib qoladi. `RunnerAvailability`ning public admission gate o‘zgarmaydi: barcha unavailable holat503. R5 typed invalid-output exception uchun ayni attempt budgetda faqat bitta bounded rerun, keyin terminal invalid-output handling ishlatadi; infrastructure retry alohida. Bare Python/model repair/remote fallback yo‘q.

Parser activation qualification ikki actual outputning har ikkalasi expected benign fixture bilan mos bo‘lishi hamda har invocationdan keyin manifest/inventory tekshiruvi o‘tishini talab qiladi. Birinchi passdan keyingi failure, mismatch yoki drift parser gatefalse qiladi. Native lifecycle testlari real `Native.launch` orchestrationni fake Win32 adapters bilan tekshiradi; actual pending writer cancellation/handle ownership ham shu unit seamda sinaladi. PHP process adapter tests timeout/nonzero/decode/schema/quota kategoriya boundarysini tekshiradi. Bu doubles OS activation dalili emas.

```powershell
& 'C:/Users/sobirjon.qurbonov/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe' -I -B -m unittest discover -s analytics/tests -p 'test_*.py' -v
```

Backenddan: `& '../.local/tools/php85/php.exe' artisan test --colors=never --filter=DeveloperRunnerAvailabilityTest`. Actual API new-request unavailable503 durable run yaratmasligi kerak; existing authorized run retry/read availabilitydan mustaqil API qoidalariga bo‘ysunadi. AppContainer profile/root ownership collisionni operator tekshiradi; unknown existing profile ACLlarini avtomatik tuzatmang. Recopy/manifest refresh actual gate dalillarini invalidate qiladi, yangi probe run kerak. Local model yoki network gate ishlamasa remote fallback qo‘shmang.

## Durable admission, drain va review runbook v1

R1–R6 storage/API/parser/processor/review code implemented; R6/final review pending. Actual disabled state above is unchanged: KERNELBASE/0xc0000142 startup and no independent controlled network endpoint. New request503/no run; no model installed and AI interpretation unavailable. Full production/local vertical fixture→actual broker→read→review→force-push/revoke smoke remains blocked. Behavioral tests explicitly inject test-only parser results through the real PHP validator; never inject that fixture into a production runner to bypass activation.

Actual dependencies are locked Laravel13/PHP8.5.11/PostgreSQL17.10 and existing Python3.12.14 stdlib. No dependency update/download or model provisioning was performed. Current OpenAPI1.3.0; input/output/authority/analyzer/taxonomy/secret versions remain v1. Guarded `app:migrate-local` applied dev incrementals15–18 after isolated PostgreSQL verification; no reset/rollback/history rewrite. Migration down intentionally refuses loss of immutable evidence.

Commands from `backend/` for an already-authorized synthetic operator/project (substitute `$projectId` and explicit ignored `$tokenFilePath`; never print token or put it in argv). The CLI checks local/testing, loopback dedicated dev/test DB marker/nonowner runtime, current bearer subject and purposes. Missing scope is a403/guard failure; do not add grants or self-admin to make smoke pass.

```powershell
# Generate only the checked-in synthetic fixture as JSON; not a real source import.
& '../.local/tools/php85/php.exe' -r 'echo json_encode(require "tests/Fixtures/developer-mr.php", JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);' | Set-Content -Encoding utf8NoBOM '../.local/e04-fixture.json'
& '../.local/tools/php85/php.exe' artisan app:developer-fixture $projectId '../.local/e04-fixture.json' --token-file=$tokenFilePath --expected-revision=0
& '../.local/tools/php85/php.exe' artisan app:drain-developer-analysis $projectId --token-file=$tokenFilePath --limit=20
```

Registration requires analysis.fixture.manage; source pointer expected-revision0 for first registration, then actual returned CAS revision. API POST `/api/v1/projects/{project}/analysis-runs` uses snapshot ID/mode=deterministic/current criterion/task pins and printable ASCII Idempotency-Key1–128. Project request+source permissions; retained retry also read+exact original proof. Admission≤100 queued/running per project; saturation429 Retry-After5. Default-disabled runtime rejects new admission503. Registration itself is not analysis execution or source freshness evidence.

Drain requires process+source; batch1–20. Source and invalidation outboxes are separate and never execute parser. Claim commits run/outbox matching UUID token, actual60-second lease, incremented attempt and exact durable processing proof/hash before compute. Publication independently reconstructs output/locators/counts, rechecks original+operator exact authority, current source receipt watermark/pins/closure and both lease tokens/expiry. Late/lost worker cannot publish. Infrastructure runner_unavailable/parser_failed retries5/30/120/600 seconds, at most5 attempts, then attempts_exhausted/dead_letter. Invalid structured output gets at most one bounded same-attempt rerun, then invalid_output terminal. Raw exception/stdout/stderr never persist. Expired fifth lease terminates without a sixth attempt.

Recovery: restore reviewed runtime prerequisites without bypass, then normal drain respects available_at/lease. Crash after committed claim/computation retains proof and waits for safe expiry/reclaim; never clear lease/attempt manually or replay source/invalidation as execution. Terminal run/outbox cannot restart. Corrected source/rule/requirement context needs new immutable snapshot/run and new idempotency key. Observed source_changed/pins_changed/access_revoked/project_closed is sticky; reopened project or replacement grant never revives old run. Bounded internal refresh is maintenance only, no content privilege. Same-row revoke+restore wholly between observations remains unobservable; record policy/operations limitations, not favorable assessment.

GET run/finding requires current read+source and exact original requester proof on every delivery. Closed projects allow authorized historical metadata. Stale/unknown output/facts/summary null. Run verified_findings contains only current verified human-reviewed analysis fact summaries,≤3, excluding rejected/pending/stale; it has no E03 acceptance/release/financial meaning. POST finding reviews requires additional review purpose, expected_revision, verified/rejected/context-added and safe nonblank≤2000-byte UTF-8 reason. CAS conflict409; closed new review409. Context preserves disposition; verified stale cannot change applicability or counts. Human correction of taxonomy facts requires new approved rules/snapshot/run.

GET finding history accepts limit1–100/default50 and bounded finding-bound opaque cursor; order revision ascending with no duplicates/omissions across immutable rows. Follow review_history.next_cursor until null. Concurrent append may appear later, not a fixed full-history snapshot. Unknown/foreign cursor422, revoke403; no read-side snapshot rewrite. Keep source/link/claim confidence labels separate and disclose synthetic SHA/logical-locator and partial coverage uncertainty.

Verified R6 commands (backend unless noted):

```powershell
& '../.local/tools/php85/php.exe' artisan test --colors=never --filter='DeveloperFindingReviewTest|DeveloperAnalysisApiTest|DeveloperInvalidationTest'
& '../.local/tools/php85/php.exe' artisan test --colors=never
& '../.local/tools/php85/php.exe' vendor/bin/pint --test
& '../.local/tools/php85/php.exe' C:/php/php84/composer.phar validate --strict
& '../.local/tools/php85/php.exe' C:/php/php84/composer.phar check-platform-reqs --lock
```

Full313 PHP tests/6714 assertions110.63s; Python51 tests1.329s; full Pint and both strict/locked Composer checks passed. Retained separate-process test fixtures exist only in guarded test DB; no immutable history cleanup. Health/up200 and no-bearer401 are liveness/denial checks, never analysis acceptance. No source-network request, public probe, push, merge, deployment or new frontend. [Partial acceptance and gates](../acceptance/e04.md).
