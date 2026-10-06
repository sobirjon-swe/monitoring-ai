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
