# E02 lokal ingestion operatsiyalari

Versiya: 0.3, 2026-10-05. Mas’ul: lokal operator va E02 backend/database. I1/I2/I4/I3 mustaqil spec va quality reviewdan o‘tgan. Lead full suite: 150 passed / 3154 assertions / 17.61s; full Pint pass. Whole-unit review va scoped metadata re-review tasdiqlandi; dev migration/smoke lead tomonidan tekshirildi; [qabul dalillari](../acceptance/e02.md) final holatni qayd etadi.

Bog‘lanish: spetsifikatsiya §§5/6/23/26/30.3, FR-040/050/220, AC-02/09/10; [E02 reja](../superpowers/plans/2026-10-05-e02-local-ingestion-plan.md), [ingestion lug‘ati](../contracts/ingestion-data-dictionary.md), [manba imkoniyatlari](../contracts/source-capability-matrix.md).

## Muhit va inkremental migration

2026-10-05 lokal muhit: portable PHP 8.5.11 `../.local/tools/php85/php.exe`; dedicated PostgreSQL 17.10 loopback `127.0.0.1:55432`, SCRAM, `monitoring_ai_dev`/`monitoring_ai_test`. Eski 5432 klasteri bu ishga tegishli emas. Private `.env`, `.env.testing`, `.local/db-config.json` qiymatlarini terminal, chat, log yoki Gitga chiqarmang. [Lokal development](local-development.md) joriy runtime yo‘llarini, [PostgreSQL](postgresql-local.md) rollar va provisioning chegaralarini tushuntiradi.

Repo ildizidan readinessni tekshirish uchun:

```powershell
& 'C:/Program Files/PostgreSQL/17/bin/pg_isready.exe' -h 127.0.0.1 -p 55432
```

Backend katalogidan faqat xavfsiz wrapper orqali yangi migrationlarni inkremental qo‘llang:

```powershell
& ../.local/tools/php85/php.exe artisan app:migrate-local --env=testing
& ../.local/tools/php85/php.exe artisan app:migrate-local
```

Wrapper local/testing, loopback, aniq dedicated DB va migrator login/installation markerini tekshiradi; alohida migration connectionda owner SET ROLE ishlatadi va connectionni yakunda purge qiladi. Runtime owner/SUPERUSER/BYPASSRLS bo‘lmasligi kerak. Migration xatosida configuration va mavjud baseline dalillarini lokal tekshiring; guardni chetlab o‘tmang.

000007 ingestion jadvallarini yaratadi. 000008 immutable `accepted_by`ni qo‘shadi; preexisting receiptga actor uydirmaydi va bo‘sh synthetic baseline bo‘lmasa xavfsiz to‘xtaydi. Bunday xato receiptlarni o‘chirish yoki reset qilish uchun ruxsat emas. `migrate:fresh`, rollback orqali ma’lumot yo‘qotish, reset, truncate, qo‘lda receipt/outbox/projection delete yoki lease/status patch ishlatilmaydi. Qayta migration qo‘llash holatini tekshirish va tuzatish alohida nazoratli ish.

## Actor va vakolatlar

Local bearer [lokal autentifikatsiya](local-authentication.md) qoidalariga amal qiladi. Mavjud tenant/user/employee/projectni tanlang; avtomatik admin, source employee yoki yangi grant bootstrap qilmang. Bootstrapning eski grant to‘plami ingestion grantlarini avtomatik bermaydi.

| Purpose | Scope va vazifa |
|---|---|
| `connector.manage` | Current project membership va explicit grant; connector yaratish/list/status |
| `ingestion.accept` | Current project membership va alohida grant; synthetic receipt qabul qilish, I2 reviewdan o‘tgan |
| `ingestion.read` | Alohida project grant; umumiy raw payload HTTP o‘qish endpointi bu birlikda yo‘q |
| `ingestion.process` | Alohida project grant; amaldagi operator processing |

Connector admin avtomatik employee/report/code/finance yoki payload vakolati olmaydi. Body/headerdagi tenant/actor UUID vakolat bermaydi. Missing tenant deny; revocation va disabled connector qabul/processing oldidan qayta tekshirilishi kerak. Immutable receipt `accepted_by` orqali asl submitter accessi processingda qayta tekshiriladi.

## Amaldagi I1 connector interfeysi

API yo‘llari local bearer va `connector.manage` bilan himoyalangan:

- GET/POST `/api/v1/projects/{project}/connectors`;
- GET `/api/v1/connectors/{connector}`.

POST body: `source_instance_id` (shu tenantdagi `manual` manba UUID), `adapter=synthetic-v1`, optional `status=active|disabled` (default disabled). URL yoki token maydoni yo‘q. List ID bo‘yicha max100, cursor yo‘q; bu to‘liq source qamrovi emas. Status connector, receipt_count va outbox status countlarini beradi; raw receipt yoki source watermark yo‘q. HEAD faqat read yo‘lidir; yaratish POST orqali. Ushbu birlikda settings-update/delete endpointi yo‘q.

`synthetic-v1` real Kanban bilan bog‘lanmaydi. `http://kanban.mo.local` uchun faqat read-only tekshiruv ruxsati mavjud; ticket/status/config write, token yaratish/o‘rnatish, webhook o‘rnatish yoki request replay qilish ruxsati yo‘q. Bu runbook Kanban API callini bajarmaydi. Loyiha metadata sir maydonlarini import qilmang; kelajak adapter faqat ticket allowlistini ishlatadi.

## Synthetic qabul va projection — implementatsiya/reviewdan o‘tgan

I2: POST `/api/v1/connectors/{connector}/receipts`, local bearer va `ingestion.accept`. Yangi durable receipt 201, semantik replay 200, bir mantiqiy kalitdagi ziddiyat 409. Envelope `ingestion-v1` va `ticket.snapshot`; max64KiB, qat’iy allowlist, timezone-explicit vaqtlar. Real employee/source matni, description/code/metadata yoki credentialni payloadga kiritmang. Raw/canonical mismatch, duplicate JSON keys va NUL strings 422; oversized request 413 bilan persistence oldidan rad etiladi.

Receipt original baytlari, transport/canonical SHA256 va accepted_by saqlanadi; receipt/outbox bir transactionda commit bo‘lishi kerak. Semantik hash key tartibidan mustaqil; birinchi baytlar qayta yozilmaydi. Source/objectni yangi versiyada boshqa projectga qayta bog‘lash I2 service serialization orqali rad etilishi kerak. Noto‘g‘ri/oversized synthetic envelope **persistence oldidan rad etiladi**; durable quarantine yoki real webhook authentication mavjud deb ko‘rsatilmaydi.

I4 immutable receiptni authorized tenant transactionda o‘qiydi. State payload + nullable attribution {mapping_id, identity_id, employee_id, revision, valid_from, valid_to}, occurred_at RFC3339 microsecond UTC va source_versionni pin qiladi. Mapping keyin o‘zgarsa avtomatik invalidation/recomputation kelajak ishidir. Eski dated snapshot yangi holatni almashtirmaydi; teng timestamp/teng payload equivalent, ziddiyatli payload ordering_conflict. Undan yangi dated snapshot dated conflictni tozalaydi. Missing source_updated_at ordering_unknown beradi va bu tarixiy flag keyingi newer dated snapshotda ham sticky qoladi. Unknown actor identity_unresolved; current receipt replay idempotent. Synthetic processing real freshness/cursor/watermark/reconciliation isboti emas; source freshness **noma’lum**. Done/100% QA qabuli yoki ish soati emas.

Status connector, receipt_count, outbox_counts (empty [] yoki object), last_received_at=max receipts.received_at, last_processed_at=max completed outbox.completed_at, pending_count=pending+processing+retry, dead_letter_count (blocked kirmaydi), coverage_reason_codesni beradi. Vaqtlar nullable PostgreSQL UTC microsecond text. Coverage doim source_freshness_unknown; projectiondan faqat identity_unresolved/ordering_unknown/ordering_conflict qo‘shiladi. Raw receipt/state, watermark yoki healthy holat yo‘q.

## Lokal processing va recovery — implementatsiya/reviewdan o‘tgan

Amaldagi CLI, backend katalogidan:

```powershell
& ../.local/tools/php85/php.exe artisan app:ingestion-drain <tenant-uuid> <app-user-uuid> <connector-uuid> --limit=20
```

Bu credential talab qilmaydigan UUID argumentlaridir; sirlarni argumentga qo‘ymang. Buyruq local/testing dedicated DB guardi, OS lokal operator authoritysi va faol app userning current membership/explicit `ingestion.process` grantini talab qiladi. UUID kiritish grant yaratmaydi yoki revocationni bekor qilmaydi. Bu production autonomous worker/service principal sxemasi emas.

Amaldagi safety limits: default20/max100 batch, 60 soniya lease, max5 urinish; retry 5/30/120/600 soniya, beshinchi failure dead_letter. Expired beshinchi claim oltinchi urinishsiz dead_letterga o‘tadi. Claim alohida transactionda SKIP LOCKED bilan commit qilinadi; internal dispatcher outer transactiondan tashqarida ishlatiladi. claimNext/deliver ichki, HTTPga ochilmagan. Fixed output counts: claimed/completed/retry/dead_letter/blocked/stale. Lease fence eskirgan tokenni rad etadi; projection+completion atomik. Operator ingestion.process va original accepted_by submitter ingestion.accept/user/membership oldin va completion oldidan qayta tekshiriladi. Revocation/disable bloklaydi; mid-apply expiry yoki xato projectionni rollback qiladi. At-least-once/idempotent effect, exactly-once emas.

Uzilishdan so‘ng ayni authorized scope bilan drain qayta ishga tushiriladi: expired lease keyingi claimda tiklanadi. Active lease tugamagan bo‘lsa uni qo‘lda qisqartirmang/statusni almashtirmang. Recovery dalili committed abandoned claim, injected rollback/expiry va ikki PostgreSQL sessiyasi orqali olingan; literal OS process-kill yoki parallel subprocess load testi bajarilgan deb ko‘rsatilmaydi. CLI loopback exact marked dev/test, runtime login/current role, nonowner/nonSUPERUSER/nonBYPASSRLSni tekshiradi; URL/read/write overrides va production environmentni rad etadi. OS lokal operator trust chegarasi production principal emas.

Dead_letter/blocked yoki davomli xatoda operator faqat count, fixed reason_code, correlation ID va tegishli UUID bilan tekshiruv qaydi ochadi. `processing_failed`, `access_revoked`, `connector_disabled`, `identity_unresolved`, `ordering_conflict` sabablari bo‘yicha authorized konfiguratsiya/coverage tekshiriladi; raw SQL, payload, prompt yoki credential chiqarmang. Qo‘lda DB patch/delete, automatic DLQ replay yoki yangi replay command bu birlikda berilmagan. Sabab tuzatilgach qayta yetkazish yo‘li alohida ko‘rib chiqiladi; immutable receipt saqlanadi.

## Yakuniy topshirish chegarasi

Hujjat muallifi DB/test/browser buyruqlarini bajarmagan. Lead full suite va Pint natijasi yuqorida; whole-unit review/scoped re-review tasdiqlandi, dev migration/smoke tekshirildi. Concurrency testning to‘rt committed synthetic fixture to‘plami test DBda saqlangan: 4 receipt, 4 completed outbox, 4 projection, supporting+ingestion jami60 qator. Immutable delete/trigger bypass bajarilmagan; keyingi run har safar bitta to‘plam qo‘shishi mumkin. `/up` liveness connector/DB/model/queue readiness isboti emas.

Haqiqiy source API/auth/pagination/webhook, backfill/reconciliation/deletion, encrypted object payload store, quota scheduling, bildirishnomalar, production worker credentials, backup/restore va retention siyosati ochiq. Barcha product AI faqat lokal/kompaniya infratuzilmasida; ushbu birlik inference ishlatmaydi. Serverga deploy, commit/push/merge alohida ruxsat talab qiladi. To‘liq E02/AC-02/09/10 yoki production tayyorligi da’vo qilinmaydi.
