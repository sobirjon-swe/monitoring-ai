# E02 lokal ingestion ma’lumotlar lug‘ati

Versiya: 0.1. Sana: 2026-10-05. Mas’ul: E02 backend/database. Manba: `2026_10_05_000007_create_ingestion_foundation.php`, `2026_10_05_000008_add_ingestion_submitter.php` va [E02 reja](../superpowers/plans/2026-10-05-e02-local-ingestion-plan.md). Lug‘at migratsiya kodidagi I1 tuzilmani tavsiflaydi; migratsiyalar muayyan DBga qo‘llanganini yoki testlar o‘tganini tasdiqlamaydi.

Lead qaydiga ko‘ra I1/I2/I4/I3 mustaqil spec va quality reviewdan o‘tgan, jumladan NUL 422 va sticky ordering_unknown tuzatishlari. Lead full suite: 150 passed, 3154 assertions, 17.61s; full Pint pass. Muallif DB/testni qayta ishlatmagan. Whole-unit review va scoped metadata re-review tasdiqlandi; dev migration/smoke lead tomonidan tekshirildi; [qabul dalillari](../acceptance/e02.md) yakuniy holatni qayd etadi.

Spetsifikatsiya bog‘lanishi: §§5/6/23/26/30.3, FR-040/050/220, AC-02/09/10. I1 connector, I2 qabul/replay, I4 projector/status va I3 processing/recovery implementatsiya/reviewdan o‘tgan; whole-unit review/scoped re-review va dev migration/smoke yakuniy dalillari qabul hujjatida qayd etilgan.

## Umumiy chegaralar

Barcha ichki IDlar UUID; vaqtlar `timestamptz(6)`, display uchun loyiha defaulti Asia/Tashkent. Tenant va project mijoz payloadidan olinmaydi: ishonchli actor va connector bindingi ularning manbai bo‘lishi kerak. Synthetic connector xizmatidagi manba turi `manual`, adapter faqat `synthetic-v1`; `task_tracker` kelajakdagi real adapter nomzodi bo‘lib qoladi. URL, token yoki tashqi API konfiguratsiyasi bu sxemaga kirmaydi. [Manba imkoniyatlari](source-capability-matrix.md) haqiqiy Kanban API chegaralarini qayd etadi.

## `ingestion_connectors`

| Ustun | DB turi / sharti | Ma’nosi |
|---|---|---|
| `id` | UUID PK | Connector ichki IDsi |
| `tenant_id` | UUID, NOT NULL, tenants FK | Ishonchli tenant |
| `project_id` | UUID, NOT NULL | Biriktirilgan loyiha |
| `source_instance_id` | UUID, NOT NULL | Tenantdagi manba instansi |
| `adapter` | varchar(30), default `synthetic-v1`, faqat shu qiymat | Sun’iy adapter chegarasi |
| `status` | varchar(20), default `disabled`; `active`/`disabled` | Qabul/processing uchun konfiguratsiya holati; live sinxronlash isboti emas |
| `revision` | integer, default 1, >0 | Har UPDATEda oldingi qiymatdan aynan 1 katta bo‘lishi kerak |
| `created_at` | timestamptz(6), NOT NULL, default now() | Yaratilish vaqti |

Noyob kalitlar: `(tenant_id,id)`, `(tenant_id,project_id,id,source_instance_id)` va `(tenant_id,project_id,source_instance_id)`. Composite FKlarga `(tenant_id,project_id) → projects(tenant_id,id)` va `(tenant_id,source_instance_id) → source_instances(tenant_id,id)` kiradi. Trigger ID, tenant, project, source, adapter va created_atni o‘zgartirishni rad etadi; status/revision mutatsiyasi uchun revision invariantini tekshiradi. Runtime HTTP settings-update endpointi bu lug‘atda yetkazilgan imkoniyat sifatida ko‘rsatilmaydi.

## `ingestion_receipts`

| Ustun | DB turi / sharti | Ma’nosi |
|---|---|---|
| `id` | UUID PK | O‘zgarmas qabul yozuvi |
| `tenant_id`, `project_id`, `connector_id`, `source_instance_id` | UUID, NOT NULL | Scope va manba bindingi |
| `accepted_by` | UUID, NOT NULL; 000008 | Qabul qilgan app user; `(tenant_id,accepted_by) → users(tenant_id,id)` |
| `source_object_type` | varchar(30), default/fixed `ticket` | Manba obyekt turi |
| `source_object_id`, `source_version` | varchar(200), trim uzunligi 1–200 | Manbadagi obyekt va versiya |
| `event_kind` | varchar(40), fixed `ticket.snapshot` | Qabul qilinadigan hodisa turi |
| `payload_bytes` | text, UTF-8 saqlangan matn hajmi 1–65536 byte | Dastlab qabul qilingan bounded request baytlari; transport lineage |
| `payload_json` | JSONB, NOT NULL, object | Validatsiyadan o‘tgan kanonik envelope |
| `transport_hash`, `canonical_hash` | varchar(64), lowercase hex | SHA256 transport va semantik payload izlari; DB formatni tekshiradi, hisoblashni emas |
| `occurred_at` | timestamptz(6), NOT NULL | Manbada hodisa sodir bo‘lgan vaqt |
| `source_updated_at` | timestamptz(6), nullable | Manba yangilanish vaqti; yo‘q bo‘lsa tartib noma’lum |
| `received_at` | timestamptz(6), NOT NULL | Lokal qabul vaqti |

Composite FK `(tenant_id,project_id,connector_id,source_instance_id)` connectorning aynan shu bindingiga bog‘laydi. Noyob mantiqiy hodisa kaliti `(tenant_id,source_instance_id,source_object_type,source_object_id,source_version,event_kind)`; u connector IDsi bilan cheklanmaydi. `(tenant_id,project_id,id)` hamda `(tenant_id,project_id,connector_id,source_instance_id,source_object_type,source_object_id,id)` keyingi FKlarga parent kalitlardir.

Receiptning **barcha ustunlari, jumladan `accepted_by`, o‘zgarmas**: UPDATE/DELETE trigger orqali rad etiladi; runtime uchun UPDATE/DELETE/TRUNCATE ruxsatlari olib tashlangan. 000008 oldingi receiptga taxminiy actor yozmaydi: NOT NULLni qo‘yish imkonsiz bo‘lsa bo‘sh synthetic baseline kerakligi haqida xato beradi. Bu mavjud yozuvlarni o‘chirishga ko‘rsatma emas.

I2 semantik replay kanonik sorted-key JSON hashini solishtiradi; ekvivalent key tartibi bir hodisa hisoblanadi. Bir mantiqiy kalit ostidagi boshqa kanonik payload 409 conflict qaytaradi; birinchi payload baytlari saqlanadi. Receipt/outbox bitta transactionda yaratiladi, muvaffaqiyat javobi durable commitdan keyin beriladi. Raw/canonical mismatch, duplicate JSON keys va NUL strings 422, oversized request 413 bilan persistence oldidan rad etiladi.

Ledger qarori: bir source instance/type/object yangi versiyada boshqa projectga qayta bog‘lanmasligi kerak. Versiyali unique tuple bunga yolg‘iz kafolat bermaydi; I2 source/object acceptance tranzaksiyalarini serializatsiya qilib tarixiy project bindingini tekshirishi shart. `accepted_by` keyingi processingda asl submitterning amaldagi ruxsatini qayta tekshirish uchun saqlanadi, consumer actorni o‘zi yaratish yoki grantni tiklash uchun emas.

## `ingestion_outbox`

| Ustun | DB turi / sharti | Ma’nosi |
|---|---|---|
| `id` | UUID PK | Yetkazish ishining IDsi |
| `tenant_id`, `project_id`, `receipt_id` | UUID, NOT NULL | Immutable receipt scope |
| `status` | varchar(20), default `pending` | `pending`, `processing`, `retry`, `completed`, `dead_letter`, `blocked` |
| `attempt_count` | integer, default 0, 0–5 | Persist qilingan urinish soni |
| `available_at` | timestamptz(6), default now(), NOT NULL | Keyingi urinish mumkin bo‘lgan vaqt |
| `lease_token`, `lease_until` | UUID / timestamptz(6), nullable | Faqat processingda ikkalasi mavjud; boshqa statusda ikkalasi NULL |
| `completed_at` | timestamptz(6), nullable | Aynan completed holatida NOT NULL |
| `reason_code` | varchar(40), nullable | `processing_failed`, `access_revoked`, `connector_disabled`, `identity_unresolved`, `ordering_conflict` |

`UNIQUE(tenant_id,receipt_id)` bitta receipt uchun bitta outboxni chegaralaydi. `(tenant_id,project_id,receipt_id) → ingestion_receipts(tenant_id,project_id,id)` boshqa tenant/loyiha receiptini bog‘lashni rad etadi. Trigger id/tenant/project/receiptni o‘zgartirishni rad etadi.

I3 amaldagi drain 1–100 batch (default20), 60 soniya lease va max5 urinish ishlatadi. Claim alohida tenant transactionda SKIP LOCKED bilan commit bo‘ladi; dispatcher outer transactiondan tashqarida chaqiriladi. Retry 5/30/120/600 soniya, beshinchi failure dead_letter; expired beshinchi claim oltinchi attemptsiz dead_letterga o‘tadi. Fenced unexpired lease bilan projection/completion atomik; rollback, revocation yoki mid-apply expiry projectionni ham bekor qiladi. At-least-once/idempotent effect; exactly-once emas. Fixed counts: claimed/completed/retry/dead_letter/blocked/stale. claimNext/deliver ichki dispatcher boundary, HTTP emas. Limits synthetic safety assumptions, production quvvat/retention siyosati emas.

## `ingestion_projections`

| Ustun | DB turi / sharti | Ma’nosi |
|---|---|---|
| `id` | UUID PK | Joriy synthetic fact yozuvi |
| `tenant_id`, `project_id`, `connector_id`, `source_instance_id` | UUID, NOT NULL | Immutable scope/manba |
| `source_object_type` | varchar(30), fixed/default `ticket` | Obyekt turi |
| `source_object_id` | varchar(200), NOT NULL | Manba obyekt IDsi |
| `current_receipt_id` | UUID, NOT NULL | Joriy holatning o‘zgarmas receipt dalili |
| `source_updated_at` | timestamptz(6), nullable | Joriy manba vaqt chegarasi |
| `state` | JSONB, NOT NULL, object | Payload + attribution/occurred_at/source_version; I4 implementatsiyasi |
| `coverage_flags` | JSONB, NOT NULL, array | To‘liqlik cheklovlari; DB faqat array turini tekshiradi |

Noyob kalit `(tenant_id,connector_id,source_object_id)`. Yetti ustunli FK `(tenant_id,project_id,connector_id,source_instance_id,source_object_type,source_object_id,current_receipt_id)` receiptning xuddi shu obyekt/bindingiga bog‘laydi. Trigger id/tenant/project/connector/source/type/objectni o‘zgartirishni rad etadi; current_receipt, source_updated_at, state va coverage yangilanishi mumkin.

I4 authorized tenant transactionda persisted immutable receiptni qayta o‘qiydi; callerning boshqa fieldlari projected bo‘lmaydi. State payload + nullable attribution {mapping_id, identity_id, employee_id, revision, valid_from, valid_to}, occurred_at RFC3339 microsecond UTC va source_versionni saqlaydi. Approved event-time mapping pin qilinadi; mapping keyin o‘zgarsa avtomatik invalidation/recomputation kelajak ishidir. Eski dated receipt joriy holatni almashtirmaydi. Teng timestamp/teng payload equivalent_snapshot; ziddiyatli payload ordering_conflict. Missing source_updated_at ordering_unknown beradi va keyingi newer dated snapshotda ham sticky qoladi. Dated conflict undan yangi dated snapshotda tozalanadi. Current receipt replay already_current sifatida idempotent. Unknown actor identity_unresolved. Receipt soni source freshness yoki watermark emas; Done/100% QA qabuli yoki ish soati emas.

## RLS, ruxsatlar va sintetik envelope chegarasi

To‘rtta jadvalda ENABLE va FORCE RLS. `USING` va `WITH CHECK` tenantni `nullif(current_setting('app.tenant_id',true),'')::uuid` bilan solishtiradi. Kontekst yo‘q bo‘lsa scoped read bo‘sh va write rad etiladi. RLS tenant himoyasi; loyiha membership/purpose tekshiruvini almashtirmaydi. Runtime `monitoring_ai_app` nonowner/nonSUPERUSER/nonBYPASSRLS bo‘lib qolishi shart. Barcha to‘rtta jadval uchun runtime DELETE/TRUNCATE revoke qilingan.

I1 connector.manage, I2 ingestion.accept va I3 operator ingestion.process current grant/membership bilan tekshiriladi. I3 payload/projector oldidan va completion oldidan operator processing hamda original accepted_by submitter acceptance authoritysini alohida qayta tekshiradi; connector status shared lock bilan ushlanadi. Revocation/disable blocked, expired/replaced fence stale. ingestion.read alohida grant; raw payload HTTP endpointi yo‘q. No automatic blocked/DLQ redrive.

I1 connector listi ID bo‘yicha max100, cursor yo‘q. Status connector, receipt_count, outbox_counts (empty [] yoki object), last_received_at=max receipts.received_at, last_processed_at=max completed outbox.completed_at, pending_count=pending+processing+retry, dead_letter_count (blocked kirmaydi) va coverage_reason_codesni beradi. Vaqtlar nullable PostgreSQL UTC microsecond text. Coverage doim source_freshness_unknown; projectiondan faqat identity_unresolved/ordering_unknown/ordering_conflict qo‘shiladi. Raw receipt/state, watermark yoki healthy holat yo‘q.

I2 amaldagi `ingestion-v1` envelope: source_object_id/version max200, event_kind `ticket.snapshot`, timezone-explicit occurred_at va nullable source_updated_at; payloadda external_project_id, external_ticket_id, title max500, status max100, external_actor_id nullable max200, due_at nullable timezone-explicit. Nullable maydonlar ham required. Qo‘shimcha xossalar, tenant_id/project_id, metadata/description/code taqiqlanadi. Max64KiB synthetic request, sha256ni server hisoblaydi. Noto‘g‘ri envelope persistence oldidan rad etiladi; durable malformed quarantine/live delivery-attempt tarixi yo‘q.

Haqiqiy source API/auth/webhook, pagination/backfill/reconciliation, encrypted object store, quota scheduler, bildirishnomalar va production worker service-principal sxemasi ochiq. Bu lug‘at to‘liq E02/MVP/production yoki to‘liq AC-02/09/10 bajarilganligini da’vo qilmaydi.
