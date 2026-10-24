# Monitoring AI — lokal ishlatiladigan mahsulotgacha qolgan ishlar

2026-10-09. Foydalanuvchi ishni davom ettirish va barcha qolgan ishlarni yakunlashni so‘radi. Ushbu ro‘yxat repository spetsifikatsiyasi v1.0 va amaldagi qabul hujjatlariga asoslangan ketma-ketlik; hali ko‘rsatilmagan dizayn, frontend texnologiyasi, real manba vakolati yoki server deployi tasdiqlangan degani emas.

## Birinchi natija: lokal website orqali ishlash

1. E05-03 boshqaruv ko‘magi: 2026-10-24 tasdiqlangan synthetic backend doirasi yakunlandi. T1–T5 schema/permission, read-only source proofs, workflow, navbat/tarix, strict HTTP va guarded fixture mustaqil ko‘rik hamda tuzatish qayta ko‘rigidan o‘tdi. Umumiy859 test/22557 assertion; undan keyingi alohida CLI xavfsizlik tuzatishi6 test/714 assertion va offline2/40 bilan tekshirildi. DEV34–39 migratsiyalari,287 assertionli oltita rol qabul ssenariysi va107 doirada rollback qoldiq0 tasdiqlandi. [Aniq qabul chegarasi](../acceptance/e05-03.md); commit tasdig‘i kutilmoqda. E05-01 QA va E05-02 BA qayta yozilmaydi.
2. Lokal frontend: mavjud tasdiqlangan dashboard ko‘rinishini haqiqiy lokal APIga ulash; login/logout, loyiha tanlash, talab/vazifa, QA, BA va boshqaruv ekranlari. Demo raqamlari haqiqiy connector/AI natijasi sifatida ko‘rsatilmaydi. Frontend texnologiyasi va yangi ekran/permission/loading/error holatlari dizayn tasdig‘ida tanlanadi.
3. Brauzer qabul tekshiruvi: haqiqiy autentifikatsiyalangan HTTP orqali asosiy ish oqimi, role/tenant/department cheklovlari, bekor qilingan ruxsat, GET/HEAD va xato holatlari. Oldingi HTTP-kernel sinovlari haqiqiy brauzer UAT o‘rnini bosmaydi.

Bu bosqichdagi website sinov ma’lumotlari bilan ishlaydigan lokal biznes oqimidir. Haqiqiy source va AI faollashtirilmaguncha ular uchun unavailable/synthetic holati ko‘rsatiladi.

Frontend oldi tahlili brauzer login/session va ruxsatli loyihalar ro‘yxati endpointlari hali yo‘qligini ko‘rsatdi. Oddiy bootstrap lifecycle ruxsatlarini ham bermaydi. Oldindan provision qilingan token va ma’lum loyiha UUIDsi bilan cheklangan o‘qish ekrani to‘liq website qabul natijasi hisoblanmaydi; autentifikatsiya, loyiha tanlash va ruxsatli provisioning alohida dizaynda hal qilinadi.

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

Amaldagi natijalar: E01/E02/E03ning tasdiqlangan lokal birliklari, qisman safe-disabled E04, E05-01 QA, E05-02 BA va E05-03 synthetic boshqaruv backend; E05-02 commit `2c66c55`, E05-03 hali commit qilinmagan. Frontend mustaqil demo, production API ulanishi yo‘q. Keyingi natija yuqoridagi autentifikatsiyali lokal website; yangi ekranlar va texnologiya/dizayn tasdig‘i alohida. Barcha Codex lead/specialist/implementer/reviewerlar `gpt-6.1-sol`, reasoning `medium` (eng so‘nggi user policy2026-10-13; oldingi Ultra o‘rniga) ishlatadi.
