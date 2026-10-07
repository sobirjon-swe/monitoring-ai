# Manba imkoniyatlari matritsasi

Versiya: 0.1. Sana: 2026-10-05. Mas’ul yo‘nalish: E02 integratsiya va xavfsizlik. Holat: kuzatilgan UI dalillari va ochiq cheklovlar; haqiqiy Kanban adapteri tasdiqlanmagan.

Ushbu hujjat [E02 lokal ingestion rejasi](../superpowers/plans/2026-10-05-e02-local-ingestion-plan.md) I5 topshirig‘iga tegishli. Spetsifikatsiya v1.0 §§5/6/23/26/30.3, FR-040/050/220 va AC-02/09/10 manba qamrovi, vaqt, doimiy qabul va ruxsat chegaralarini belgilaydi. Quyidagi UI kuzatuvlari bu talablar bajarilganini yoki connector testlari o‘tganini isbotlamaydi.

## Ruxsat va dalil chegarasi

Foydalanuvchi `http://kanban.mo.local` manbasini faqat o‘qishga ruxsat bergan. Joriy brauzer hisobida ko‘rinadigan ma’lumotlar 2026-10-05 tekshiruv kontekstidan qayd etilgan; ushbu hujjatni yozishda manbaga qo‘shimcha tarmoq so‘rovi yoki test yuborilmagan. Ko‘rinish shu hisob va ko‘rilgan loyiha bilan cheklangan; butun manba yoki boshqa hisoblarning qamrovi noma’lum.

O‘qish ruxsati ticket yaratish/tahrirlash, status almashtirish, sozlamalarni o‘zgartirish, token yaratish/o‘rnatish yoki webhook o‘rnatishga ruxsat bermaydi. Hujjatda credential qiymatlari va loyiha metadata qiymatlari saqlanmaydi.

## Kuzatilgan imkoniyatlar

| Obyekt yoki imkoniyat | Dalil | Cheklov va integratsiya holati |
|---|---|---|
| Loyiha | Joriy hisobda LMS nomli loyiha, manba loyiha IDsi `54` ko‘ringan | Nom faqat manba yorlig‘i; monitoring platformasi domeniga LMS tushunchalarini ko‘chirish uchun asos emas |
| Loyiha hisoblagichlari | 4 a’zo, 6 ticket, 1 epic, 0 sprint | Ko‘rilgan UI holati; API orqali to‘liq ro‘yxat va tarix tekshirilmagan |
| Statuslar | 7 status: draft, tasks, in progress, ready, testing, completed, stopped | Manba statuslari; monitoring qabul mezonlari yoki QA natijasiga avtomatik tenglashtirilmaydi |
| Ticketlarning joriy holati | Ko‘ringan 6 ticketning barchasi completed holatida | Tarixiy o‘tishlar, QA dalillari va mezonlarning inson tomonidan qabuli tekshirilmagan |
| Ticket tafsiloti | `2824` raqamli task, inson o‘qiydigan manba identifikatori `lms-WY1BPL` | Raqam va inson o‘qiydigan identifikator alohida saqlash nomzodlari; barqaror API kaliti hali noma’lum |
| Ticket maydonlari | Title, project, status, assignee, creator, due, sprint, story points maydonlari ko‘ringan | API nomlari, turlari, null semantikasi va identity mapping tekshirilmagan; story points haqiqiy ish soati emas |
| Tavsif tuzilishi | Goal, result, acceptance criteria va verification qismlari ko‘ringan | Bu matn mavjudligi bajarilganlik, tekshiruv natijasi yoki tasdiq isboti emas; tavsif qiymatlari bu hujjatga ko‘chirilmagan |
| Qo‘shimcha panellar | Comments, subtasks va status-history panellari ko‘ringan | Panel mavjudligi qayd etilgan; history yozuvlari va ularning to‘liqligi tekshirilmagan |
| Navigatsiya | Timeline, sprint, report va activity menyulari ko‘ringan | Menyu mavjudligi API, eksport yoki ishonchli hodisa oqimini isbotlamaydi |

`100%`, `Done` yoki `completed` ko‘rsatkichlari QA tomonidan qabul qilingan progress isboti emas. Qabul qilingan progress uchun tasdiqlangan baseline, mezon vaznlari va tegishli qabul dalillari talab etiladi. Yetishmayotgan dalillardan xodim ishlamaganligi, aniq ishlangan soat yoki past muddat xavfi chiqarilmaydi.

## Tekshirilmagan texnik imkoniyatlar

| Imkoniyat | Holat | Keyingi adapter uchun zarur dalil |
|---|---|---|
| O‘qish API endpointlari va sxemasi | NOMA’LUM | Ruxsatli hujjat yoki nazoratli o‘qish orqali aniq endpoint, maydonlar va versiya |
| API autentifikatsiyasi va scope | NOMA’LUM | Mavjud ruxsatli o‘qish mexanizmi, credential boshqaruvi va bekor qilish semantikasi |
| Sahifalash, cursor va tezlik cheklovi | NOMA’LUM | To‘liq qamrov, tartib, inkremental olish va retry chegaralari |
| Webhook va imzo tekshiruvi | NOMA’LUM | Qo‘llab-quvvatlash va protokol dalili; o‘rnatish uchun alohida ruxsat |
| O‘chirish va ruxsat bekor qilinishi | NOMA’LUM | Tombstone/reconciliation hamda hosila natijalarni bekor qilish qoidalari |
| Status tarixi va tarix eksporti | NOMA’LUM | Hodisa IDsi, actor, occurred_at/source_updated_at, versiya, eksport to‘liqligi |
| Comments/subtasks eksporti | NOMA’LUM | Ruxsat va sezgirlik chegaralari, API sxemasi, pagination |
| Tarixiy to‘ldirish va solishtirish | NOMA’LUM | Watermark, kech hodisalar, yo‘qolgan yozuvlar va qamrov isboti |

API transporti, imzolar, pagination yoki history eksporti UI kuzatuvlaridan taxmin qilinmaydi. Tekshirilmagan freshness, source watermark yoki muvaffaqiyatli sinxronlash holati ko‘rsatilmaydi.

## Xavfsiz integratsiya chegarasi

Ko‘rilgan loyiha metadatada sirga oid nomlangan maydonlar mavjud. Keyingi connector ticket maydonlarini qat’iy allowlist orqali ajratishi va loyiha metadatani chiqarib tashlashi shart. Butun loyiha obyektini xom import qilish yoki metadata qiymatlarini payload, fixture, log, audit, prompt yoxud hujjatga ko‘chirish mumkin emas. Sirlarni tekshirish/redaksiyalash allowlist o‘rnini bosmaydi; kutilmagan maydonlar qabul qilinmaydi.

Mavjud `source_instances`dagi `task_tracker` turi kelajakdagi ushbu manbani ifodalash uchun mos nomzod. Bu amaldagi Kanban connectori mavjudligini bildirmaydi. Joriy E02 lokal birlik chegarasi `synthetic-v1` adapteri va sun’iy ma’lumotdir; aniq API, autentifikatsiya, vaqt/versiya va ruxsat shartnomasi tekshirilmaguncha real adapter yoki import yoqilmaydi. Synthetic connector bindingi rejaga ko‘ra `manual` turdagi manbani ishlatadi.

Keyingi adapter tenant/project bindingini ishonchli konfiguratsiyadan olishi, approved identity mappingdan foydalanishi, source instance/object/versionni saqlashi, UTC vaqtlarni ajratishi va noto‘liq qamrovni ochiq ko‘rsatishi kerak. Ism yoki email asosida shaxslar avtomatik birlashtirilmaydi. Ruxsatli source o‘qish mahsulot AI inference xizmatidan alohida; barcha mahsulot AI faqat lokal yoki kompaniya boshqaradigan infratuzilmada bajariladi.

Bu matritsa E02 implementatsiyasi, test natijasi yoki to‘liq AC-02/09/10 qabul dalili emas. Real source pagination, backfill/reconciliation, credential/webhook tekshiruvi va real importlar ochiq qoladi.

## 2026-10-06: E04 synthetic developer evidence

Matrix supplement v0.2: approved partial E04 uses guarded local `app:developer-fixture`, source-instance/repository/MR logical IDs, immutable revision/base/head SHA and safe diff snapshot. No GitLab/Kanban network call, checkout, webhook/source credential or real SHA resolution is implemented. open/merged/released are fixture metadata, never release acceptance. Existing Kanban read-only authorization is unchanged; no further source request was made for E04.

## 2026-10-06: E05-01 synthetic QA evidence

Matrix supplement v0.3: separate `qa-evidence-v1` synthetic manual-source contracts for immutable build/environment/definition/execution/defect/handoff; finite reviewed plan/selection/human CAS decisions. [Contract](qa-evidence-v1.md) and [local runbook](../operations/local-qa-evidence.md) specify HTTP and fixture-token-file registration. Existing E02 ticket.snapshot v1 is unchanged. No real QA/CI/GitLab/Kanban/attendance source API or write, webhook/credential provisioning, recording or artifact execution. Synthetic source_updated_at/build/SHA/locator are supplied fixture facts, not measured live freshness or real fix/release proof.

Observed source/original-authority invalidity is sticky across exact retained/natural-alias retries; current read/queue checks do not wait for maintenance. This does not reconstruct wholly unobserved same-row revoke/restore. Live-source authenticity/order/completeness, read-only adapter, production source/build DoD and retention remain DEC-03/05 gates. All AI unavailable; no E04 parser activation or external inference. Full E05/AC-07 acceptance is not established by this supplement.

Durable request/process/read/review and exact scoped patch-group metadata are implemented; real parser activation stays disabled503 because native Python startup fails0xc0000142 and independent controlled non-loopback probe endpoint is absent. Behavioral fake test workers and standalone trusted parser fixtures are not production/live source or activation evidence. AI interpretation unavailable; model absent. [Data dictionary](developer-analysis-data-dictionary.md), [runbook](../operations/local-developer-analysis.md), [partial acceptance](../acceptance/e04.md).
