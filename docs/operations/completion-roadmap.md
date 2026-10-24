# Monitoring AI — lokal ishlatiladigan mahsulotgacha qolgan ishlar

2026-10-09. Foydalanuvchi ishni davom ettirish va barcha qolgan ishlarni yakunlashni so‘radi. Ushbu ro‘yxat repository spetsifikatsiyasi v1.0 va amaldagi qabul hujjatlariga asoslangan ketma-ketlik; hali ko‘rsatilmagan dizayn, frontend texnologiyasi, real manba vakolati yoki server deployi tasdiqlangan degani emas.

## Birinchi natija: lokal website orqali ishlash

1. E05-03 tasdiqlangan synthetic boshqaruv backend yakunlangan va `85cb84c` bilan commit qilingan; [qabul chegarasi](../acceptance/e05-03.md) saqlanadi.
2. F01 lokal React read workspace tayyor: token-once/server-session, loyiha tanlash, talab/vazifa/progress va QA/BA/boshqaruv navbat/tarixi, UZ/RU/EN va mobile holatlar. T1–T5 va T6 tor tuzatishlari mustaqil ko‘rikdan o‘tgan; persistent sun’iy olti rol workspace DEVda tayyor. Haqiqiy brauzer/rol/ruxsat/sessiya sinovlari, PHP906/24073, SPA79/type/build va statik tekshiruvlar o‘tgan. [F01 qabul dalillari](../acceptance/frontend-f01.md): yakuniy umumiy review tasdiqlangan, ochiq kod kamchiligi yo‘q. F01 uncommitted; commit uchun alohida ruxsat talab qilinadi.
3. F02 website oqimlari **hali pending**: E03 draft/revision/approval, DoD/task/dependency/evidence/review/baseline/acceptance; QA plan/execution/defect/handoff; BA request/answer/correction/review; management action/assignment/support/outcome formalarini aniq payload, permission, CAS/idempotency/conflict holatlari bilan inventarizatsiya qilish. Avval ruxsatli reference-selector/catalog APIlari va tasvirlar tasdiqlanadi; navbat barcha entitylar katalogi deb olinmaydi, UUIDni qo‘lda kiritish yakuniy oqim emas. F01 rasmlari yangi mutatsiya doirasiga ruxsat bermaydi.

F01 uchun [tasdiqlangan dizayn va image hashlar](../superpowers/specs/2026-10-24-frontend-api-integration-design.md) o‘zgarmagan. Keyin tasdiqlangan ikkita tokensiz producer istisnosi current source-proof revocationni saqlaydi; olti interaktiv rol faqat read-only. [Lokal operator yo‘riqnomasi](local-browser-workspace.md) protected credential locator/expiry va recovery chegaralarini ko‘rsatadi. Synthetic ma’lumot real connector/AI natijasi emas; product AI lokal-only/unavailable, source Kanban read-only.

## Spetsifikatsiyani to‘liq yakunlash

| Navbat | Qolgan ish | Tugallanish dalili |
| --- | --- | --- |
| E02/E04/E05 integratsiya | Tasdiqlangan read-only tracker/GitLab/QA manba kontraktlari va identity mapping; E04 Windows izolyatsiyalangan parser aktivatsiya muammosi | Real vakolatli source bilan read-only import/replay/correction va actual sandbox gate; Kanban yozuvlariga o‘zgartirish yo‘q |
| E04/E05 AI | Lokal model/runtime/hardware/license/weights provisioning va offline sifat tekshiruvi | Internet/inference fallback o‘chirilgan holda lokal natija; sifatsiz yoki uzilgan AI uchun fact-only degradatsiya |
| E06/E08 | Rol mezonlari/effort, permission-aware knowledge, hisobotlar, e’tiroz/tuzatish va ruxsatli eksport | Versiyali kirish dalillari va role/language dataset natijalari; AI taxmini haqiqiy ishlangan soat emas |
| E07 | Bog‘liqlik, tasdiqlangan taqvim/sig‘im va qolgan ish asosidagi ssenariy prognozi/xavf | Input snapshot, reproduksiya seedlari, missing/cycle unknown; kalibratsiyasiz ehtimol va’dasi yo‘q |
| E09 | Xavfsizlik, offline AI quality, load, backup/restore, retention/deletion va rol UAT | FR/NFR/AC bo‘yicha kuzatilgan qabul natijalari; release maqsadlarini measured deb ko‘rsatmaslik |
| E10 sandbox | Evidence/reviewdan keyin fictional mablag‘li, default-disabled taqsimot | Exact decimal/rounding/conservation/eligibility/dispute/concurrent approval testlari |

Real E10 moliyasi DEC-06/07/08, finance/HR va alohida aktivatsiya tasdiqlariga bog‘liq. Lokal natijadan kompaniya serveriga ko‘chirish ham alohida ruxsat talab qiladi. Avtomatik push/merge/deploy yoki tashqi AI faollashtirilmaydi.

## Holatni o‘lchash

Oldingi 25–35% soni taxminiy muhandislik bahosi edi. Talablar bo‘yicha tasdiqlangan vaznli denominator yo‘q; shu sabab u rasmiy qabul progressi emas. Har bir tugallangan tasdiqlangan birlik uchun qabul hujjati, review, test natijasi va commit qayd etiladi. Test soni yoki yozilgan kod hajmi mahsulot tayyorligining foizi emas.

Amaldagi natijalar: E01/E02/E03ning tasdiqlangan lokal birliklari, qisman safe-disabled E04, E05-01 QA, E05-02 BA va E05-03 synthetic boshqaruv backend; E05-02 commit `2c66c55`, E05-03 commit `85cb84c`. F01 lokal APIga ulangan read workspace implementatsiyasi yakuniy qabulni kutmoqda; mustaqil demo ham saqlangan. F02 formalar/selectors va kengroq platforma hali pending. Barcha Codex lead/specialist/implementer/reviewerlar `gpt-6.1-sol`, reasoning `medium` (eng so‘nggi user policy2026-10-13; oldingi Ultra o‘rniga) ishlatadi.
