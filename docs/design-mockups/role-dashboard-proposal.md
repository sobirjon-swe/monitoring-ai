# Rollar, tillar va loyiha tafsiloti — dizayn taklifi

2026-10-03. Holat: foydalanuvchi tasdig‘i kutilmoqda. Bu maketlar ishlayotgan yangi ekranlar emas. Avvalgi dashboard v1 tasdig‘i yangi ekranlarni avtomatik tasdiqlamaydi.

Foydalanuvchi: Developer, PM, BA, QA, bo‘lim boshlig‘i, umumiy boshliq uchun dashboardlar; UZ/RU/EN; loyiha ichida kim nima qilayotgani va boshlanishdan yopilishgacha bosqichlar dinamikasi.

## Maketlar

- [Developer / BA / QA](roles-specialists-v1.png)
- [PM / bo‘lim boshlig‘i / umumiy boshliq](roles-management-v1.png)
- [Loyiha ichki sahifasi](project-detail-v1.png)

## Ko‘rinishlar

| Rol | Asosiy vazifa va dalillar |
| --- | --- |
| Developer | Tayinlangan vazifalar, MR/CI, review, bloklovchi va o‘z konteksti. |
| PM | Qabul qilingan hajm, QA/review navbati, bog‘liqliklar, bosqichlar va qaror mas’ullari. |
| BA | Talab/mezon versiyalari, aniqlashtirish, mijoz qarori va o‘zgarish ta’siri. |
| QA | Mezon → test → build/muhit, bajarilgan/muvaffaqiyatsiz/to‘silgan/eskirgan holatlar, retest. |
| Bo‘lim boshlig‘i | Ruxsatli loyihalar bo‘yicha resurs ajratilishi, navbatlar, yordam va eskalatsiya. |
| Umumiy boshliq | Ruxsatli loyiha portfeli, milestone, umumiy to‘siqlar va tashkilot qarorlari. |

Bir xil shell ichida rolga mos kontent tavsiya etiladi: bitta universal jadval rollarning turli mas’uliyatini yetarli ko‘rsatmaydi; butunlay alohida ilovalar esa navigatsiya va tarjima takrorini oshiradi. Demo rol tanlovi faqat namunani o‘zgartiradi, real access grant bermaydi. Haqiqiy role/scope server tomonidan tekshiriladi.

UZ lotin, RU kirill, EN inglizcha. Menyu, holat, dialog, aria label, sana/son formatlari birga almashadi. Asl manba matni tarjima qilinmagan bo‘lsa o‘z tili bilan saqlanadi; tashqi tarjima/AI xizmatiga yuborilmaydi. Ma’lumot modeli va identifikatorlar tillar orasida bir xil.

## Loyiha ichki sahifasi

## Bajarilgan ishlar va davriy hisobotlar — qo‘shimcha talab

2026-10-03 kuni foydalanuvchi Developer, BA, PM va QAning nima ish qilgani, kunlik/haftalik/oylik hisobotlari maketlarda yetishmayotganini ko‘rsatdi. Ushbu talab implementatsiya qilinmagan va mavjud maketlar uni to‘liq tasvirlamaydi; yangilangan hisobot maketi tasdiqdan oldin qo‘shiladi.

Har bir rol uchun Kunlik / Haftalik / Oylik tablari, sana oralig‘i va loyiha filtri. Ish yozuvi: nima qilindi, loyiha/vazifa/mezon, voqea vaqti, natija, manba dalili, tekshiruv holati va yetishmayotgan kontekst. Developer: vazifa, kod/MR/review/test/tadqiqot; BA: talab/mezon versiyasi, aniqlashtirish va tasdiq; PM: reja/qaror, bloklovchi/eskalatsiya va milestone; QA: test, nuqson, qayta test va tekshirilgan mezon. Haftalik/oylik jamlanma tugallangan ish, keyingi davrga o‘tgan ish, bloklovchi va taqqoslanadigan dinamikani ko‘rsatadi. Qabul qilingan natija bajarilgan ishdan ajratiladi; dalil yo‘qligi ishlamaganlik deb talqin qilinmaydi.

## Loyiha ichki sahifasi tafsilotlari

Boshlash → talablar → rejalash → dasturlash → review/CI → QA → qabul/reliz → yopish — boshlang‘ich sozlanadigan workflow taklifi. Bosqichlar parallel yoki takror bo‘lishi mumkin. Reja va kuzatilgan fakt alohida; kelajakdagi fakt noma’lum. Maketdagi mayda sana/yorliqlar synthetic vizual misol; kanonik demo fixturelar implementatsiya rejasida izchil aniqlanadi.

Mas’uliyat jadvali: vazifa/mezon, ijrochi va sanali loyiha roli, bosqich, holat, bog‘liqlik, keyingi mas’ul va dalil. Tafsilot yo‘li: metrika → vazifa/mezon → artefakt → ruxsatli snapshot/versiya.

Qabul progressi tasdiqlangan mezon vazni/maxraj va baseline versiyasiga bog‘liq. Namunada 67 qabul +15 bajarilgan, tekshiruv kutilmoqda +8 jarayonda +5 bloklangan +5 boshlanmagan =100. Bajarilgan jami82 qabul67ni o‘z ichiga oladi; ular qo‘shib yuborilmaydi. Tarixiy grafik faqat 03.10.2026gacha; kelajak reja sifatida alohida. Baseline o‘zgarsa eski/yangi maxraj va versiya ajratiladi. Done yoki MR merge majburiy QA/qabulni almashtirmaydi; reliz tayyorligi alohida gate.

## Kichik implementatsiya birliklari (tasdiqdan keyin)

1. I18N: yagona lokal tarjima lug‘ati va UZ/RU/EN switch, barcha accessible label/formatlar.
2. ROLES: olti demo dashboard va aniq rol/scope holati.
3. PROJECT: loyiha IDsi orqali ichki sahifaga o‘tish, vazifa/ijrochi/bosqich tafsilotlari.
4. LIFECYCLE: parallel/takror bosqichlar, reja/fakt timeline, versiyalangan progress dinamika va bo‘sh/cheklangan holatlar.
5. QA: til almashishi ma’lumotni yo‘qotmasligi, barcha loyihalar drill-downi, denominator/QA invariantlari, desktop/mobil screenshot va mustaqil review.

Birinchi UI iteratsiyasida aniq belgilangan synthetic fixturelar ishlatiladi. Haqiqiy talab/test/event ingestion va progress API modullari mavjud deb ko‘rsatilmaydi; ularni ulash E02/E03 hamda tegishli E04–E09 ishlarining alohida doirasi. Production frontend tanlovi ochiq; hozirgi lokal HTML/CSS/JS previewning cheklovi saqlanadi.

Trace: specification §§3–4,7–11,19; FR-001/020/030/070/080/090/100/130/180/200, AC-05/06/07/08/09/10. UZ/RU/EN — yangi foydalanuvchi talabi; spetsifikatsiyaning tahlil tili bilan tenglashtirilmaydi. Umumiy xodim reytingi, aniq ishlangan soat, avtomatik moliya/reliz/intizomiy qaror yo‘q. Mahsulot AI faqat lokal; ulanmagan holat aniq ko‘rsatiladi.

UI/UX mutaxassisi mockupdan oldin rol panellari va loyiha workflow briefini berdi. Yakuniy maket tasdig‘i, yozma spec/implementatsiya rejasi, ishlayotgan screenshot va kod review hali kelgusi gate; hech biri bajarildi deb ko‘rsatilmaydi.
