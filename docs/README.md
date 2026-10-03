# AI monitoring hujjatlari

- requirements/AI_monitoring_Texnik_spetsifikatsiya_v1_0.docx — boshlang‘ich texnik spetsifikatsiya.
- superpowers/specs/2026-10-03-e01-foundation-design.md — ko‘rib chiqish uchun E01 dizayni.
- design-mockups/e01-foundation-architecture.png — dizaynning tizim diagrammasi.
- superpowers/plans/2026-10-03-e01-local-foundation-plan.md — T01–T08 kichik vazifalar va qabul tekshiruvlari.

2026-10-03 qarori: mahsulot AI funksiyalari faqat lokal muhitda ishlaydi; tashqi tayyor AI APIlari va cloud fallback taqiqlanadi. Bu qaror spetsifikatsiyadagi tashqi model variantini almashtiradi. Hujjatning qolgan talablari saqlanadi. Dizayn tasdiqlanmaguncha mahsulot kodi yozilmaydi.

Joriy etish tartibi: avval ilova, PostgreSQL va AI shu kompyuterda ishga tushiriladi. Lokal natija foydalanuvchi tomonidan tasdiqlangach ichki serverga ko‘chiriladi. Serverga deploy alohida ish; muhit sozlamalari kodga qotirib yozilmaydi.
