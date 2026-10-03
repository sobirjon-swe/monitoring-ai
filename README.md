# Monitoring AI

Yangilangan sana: 2026-10-03.

Xodimlar va loyihalar monitoringi uchun lokal AI tizimi.

## Joriy holat

Tasdiqlangan E01 diagramma/rejasi bo‘yicha T02–T07 lokal backend poydevori amalga oshirildi va mustaqil tekshiruvdan o‘tdi. Laravel API hamda ajratilgan PostgreSQL dev/test bazalari ishlaydi: tenant RLS, lokal token, xodim/bo‘lim, loyiha/a’zolik/rol tarixi, tasdiqlangan tashqi identity mosliklari va audit mavjud. T07 faqat sun’iy lokal kontekst/chiqish kontraktini tekshiradi; haqiqiy AI modeli/inference yoki source retrieval ishlamaydi. 2026-10-03dagi yakuniy fix tekshiruvlari 97/97 o‘tdi; T08 yakuniy branch review va tuzatishlarning scoped re-reviewi tasdiqlangan.

Dashboard UI v1 tasdiqlangan. `frontend/` ichida HTML/CSS/JavaScript lokal demo ishlaydi; [preview yo‘riqnomasi](frontend/README.md), [UI tekshiruv dalillari](docs/acceptance/dashboard-preview.md). Production frontend frameworki, haqiqiy API ulash va login alohida qarorlar. Haqiqiy SSO, token rotation, zaxira/tiklash, worker/RAG/export/bildirishnomalar, pilot qarorlari va server deploy keyingi alohida bosqichlardir. Bu to‘liq E01 yoki ishlab chiqarish tayyorligi da’vosi emas.

- [Hujjatlar](docs/README.md)
- [Loyiha yo‘riqnomasi](AGENTS.md)
- [E01 dizayni](docs/superpowers/specs/2026-10-03-e01-foundation-design.md)
- [E01 vazifalar rejasi](docs/superpowers/plans/2026-10-03-e01-local-foundation-plan.md)
- [Lokal ishga tushirish](docs/operations/local-development.md)
- [OpenAPI](docs/api/openapi.yaml)
- [Ma’lumotlar lug‘ati](docs/data/e01-dictionary.md)
- [Qabul dalillari va chegaralar](docs/acceptance/e01.md)

## Ishlash tartibi

Avval ilova, PostgreSQL va barcha AI hisoblashlari shu kompyuterda ishlaydi. Lokal natija tasdiqlangach ichki serverga ko‘chiriladi. Tashqi tayyor AI APIlari va cloud fallback ishlatilmaydi. GitLab kabi ruxsatli ma’lumot manbalari alohida read-only integratsiya hisoblanadi.

AI xulosalari dalillar va inson tekshiruviga tayanadi. Davomat, commitlar yoki LOCdan xodimning haqiqiy ishlagan soatlari yoki universal qiymat balli chiqarilmaydi. Moliyaviy taqsimot deterministik, tasdiqlanadigan va dastlab sandboxda ishlaydi; tizim to‘lov bajarmaydi.

## Git workflow

Ish `monitoring-platform-foundation` branchida. Dastlabki main commit 131880f push qilingan; xodim/bo‘lim/audit unitining 36fc138 commiti mavjud. Qolgan o‘zgarishlar uchun commit, keyingi push/merge va server deploy alohida ruxsat talab qiladi. Avtomatik deploy sozlanmagan.
