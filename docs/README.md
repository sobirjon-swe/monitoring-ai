# AI monitoring hujjatlari

2026-10-03: tasdiqlangan diagramma/reja asosidagi T02–T07 lokal backend qismlari implementatsiya va mustaqil reviewdan o‘tdi. Yakuniy fix testlari 97/97 o‘tdi; T08 yakuniy branch review va scoped tuzatish reviewi tasdiqlangan. Dashboard lokal demo va UI reviewi tayyor; haqiqiy API ulash, model/inference, live integratsiya va ishlab chiqarish SSO/OPS alohida ish.

- [Spetsifikatsiya nusxasi](requirements/AI_monitoring_Texnik_spetsifikatsiya_v1_0.docx) — v1.0, 2026-10-02; supplied source bilan SHA256 tekshirilgan.
- [E01 tasdiqlangan dizayn](superpowers/specs/2026-10-03-e01-foundation-design.md) va [diagramma](design-mockups/e01-foundation-architecture.png).
- [T01–T08 reja](superpowers/plans/2026-10-03-e01-local-foundation-plan.md).
- [Qarorlar va ochiq cheklovlar](decisions.md).
- [HTTP OpenAPI](api/openapi.yaml) — amaldagi yo‘llar, local bearer login, validatsiya va xatolar.
- [Umumiy data dictionary](data/e01-dictionary.md) — modul lug‘atlariga havolalar, tenant/vaqt/audit invariantlari.
- [Lokal development](operations/local-development.md), [PostgreSQL](operations/postgresql-local.md), [lokal autentifikatsiya](operations/local-authentication.md).
- [E01 lokal qabul dalillari](acceptance/e01.md) — FR/AC doirasi, tekshiruvlar va qolgan ishlar.
- [E02 lokal qabul dalillari va qilinmagan ishlar](acceptance/e02.md) — reviewed synthetic slice yakunlandi; 150 test/3154 assertion va lokal dev smoke o‘tdi.
- [E02 ingestion lug‘ati](contracts/ingestion-data-dictionary.md), [lokal operatsiyalar](operations/local-ingestion.md) va [manba imkoniyatlari](contracts/source-capability-matrix.md).

Barcha mahsulot AI hisoblashlari faqat shu kompyuter yoki kompaniya boshqaradigan infratuzilmada bajariladi; tashqi AI API va cloud fallback taqiqlangan. Model keyingi bosqichda tanlanib ishlatiladi. Ruxsatli read-only source API integratsiyasi inference xizmatidan alohida.

Avval ishlaydigan lokal natija foydalanuvchiga topshiriladi. Ichki serverga ko‘chirish faqat shu natija tasdig‘idan keyin alohida ruxsat bilan bajariladi; avtomatik deploy yoqilmagan. Sirlar `.env`, `.env.testing`, `.local/db-config.json`da ignored holatda qoladi va hujjatga ko‘chirilmaydi.

- [E03 synthetic lifecycle qabul chegarasi](acceptance/e03.md) — R1–R6 reviewed and locally verified;218 tests/4690 assertions, safe dev incrementals and rollback smoke passed. Commit authorized; full E03 AI/OPS remain open.
- [E03 lifecycle dictionary](contracts/lifecycle-data-dictionary.md) va [local handoff](operations/local-lifecycle.md).
- [E04 tasdiqlangan yozma dizayn](superpowers/specs/2026-10-06-e04-developer-evidence-design.md) va [tasdiqlangan implementation plan](superpowers/plans/2026-10-06-e04-developer-evidence-plan.md) — partial synthetic diff/parser/storage/review implemented; R1–R6 code reviews and final whole-unit scoped re-review approved, actual OS activation blocked/default disabled503.
- [E04 data dictionary](contracts/developer-analysis-data-dictionary.md), [contracts/rules](contracts/developer-analysis-v1.md), [local runbook](operations/local-developer-analysis.md) va [qisman qabul dalillari](acceptance/e04.md). Model absent; synthetic behavioral tests full E04 yoki running vertical analysis isboti emas.

- [E05-01 QA qabul dalillari](acceptance/e05-01.md) — Final E05-01 gate2026-10-07: the single whole-unit fix wave and independent scoped rereview addressed I1–I4/M1 with no new breakage. Fresh bare PHP suite557passed/12987assertions/614.13s exit0; covering192passed/4780assertions; root integrated24passed/705assertions/51.13s exit0. Full Pint, locked Composer and OpenAPI checks passed. Guarded dev19–28 already applied; refreshed authenticated Laravel HTTP-kernel CRM69 static assertions passed with31 scoped absence counts zero, token absent and transaction0. TCP200/401 remains liveness only. The approved synthetic QA backend E05-01 is complete and ready for commit approval; full E05/AC-07, BA/PM/UI, live sources, local inference, production NFR and deployment remain open. E03 acceptance remains separate; image/design scope unchanged. No commit/push/merge/deploy permission inferred. [Contract](contracts/qa-evidence-v1.md), [dictionary](contracts/qa-data-dictionary.md), [local runbook](operations/local-qa-evidence.md), [approved diagram](design-mockups/e05-role-evidence-flow-v1.md).
