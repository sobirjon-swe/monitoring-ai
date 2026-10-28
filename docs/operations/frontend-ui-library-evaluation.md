# Frontend UI kutubxonalari baholashi

Sana: 2026-10-28. Qamrov — foydalanuvchi ko‘rsatgan o‘nta manba va mavjud React/Vite F01/F02 interfeysi. **Hech bir kutubxona hali integratsiya qilinmagan.** SPA runtime bog‘liqliklari React va React DOM; Tailwind, shadcn va chart engine mavjud emas.

| Manba | Tekshirilgan holat va joriy moslik | Litsenziya |
| --- | --- | --- |
| [ReUI](https://reui.io/) | Dashboard, forma va jadval komponentlari; React/shadcn/Tailwind ekotizimi. Bepul kod va pullik Pro tarkib alohida tekshiriladi. | Ochiq repozitoriy [MIT](https://github.com/keenthemes/reui/blob/main/LICENSE.md), Keenthemes Inc. Pro tarkibga bu xulosani avtomatik tatbiq etmang. |
| [21st](https://21st.dev/) | Turli mualliflar komponentlari katalogi. Tanlangan komponent manbasi, bog‘liqligi va huquqlari alohida tekshirilishi kerak. | Katalog bo‘yicha yagona litsenziya tasdiqlanmagan. |
| [Spectrum UI](https://ui.spectrumhq.in/) | React komponentlari; sayt shadcn, Tailwind, Motion va Next.js stackini ko‘rsatadi. | Loyiha [Apache-2.0](https://ui.spectrumhq.in/llm-info) deb ko‘rsatilgan; olinadigan komponent va unga tegishli assetlar alohida tekshiriladi. |
| [ReactDrops](https://github.com/crisjavr/reactdrops) | Kreativ vizual effektlar; mavjud ishchi ekranlarga mosligi cheklangan. Namuna rasmlari alohida huquqlarga ega. | Repozitoriy ReactDrops Component License 1.0 deb ko‘rsatadi: tijoriy ilovalarga ruxsat beradi, lekin kutubxona, shablon yoki registry sifatida qayta tarqatishni cheklaydi; OSI open-source litsenziyasi emas. |
| [Bencho](https://bencho.dev/) | Saytda interaktiv React bloklari va mikrointeraksiyalar katalogi ochildi. Kod, bog‘liqlik va klaviatura/reduced-motion xatti-harakatini komponent bo‘yicha tekshirish kerak. | Birlamchi litsenziya tasdiqlanmagan. |
| [Transitions](https://transitions.dev/) | O‘tishlar kutubxonasi, builder va AI agent; foydali harakat bo‘lsa lokal komponent va reduced-motion holati alohida baholanadi. | Aniq komponent litsenziyasi tasdiqlanmagan. |
| [FeralUI](https://feralui.dev/) | Fizikaga asoslangan eksperimental React elementlari; o‘yinchan uslub joriy tasdiqlangan ishchi ekranga mos deb baholanmagan. | Aniq komponent litsenziyasi tasdiqlanmagan. |
| [Kinetics](https://kinetics.colorion.co/) | CSS va React animatsiya namunalari; faqat maqsadli va o‘chirib qo‘yiladigan harakatlar uchun ko‘rib chiqiladi. | Aniq kod litsenziyasi tasdiqlanmagan. |
| [8bitcn/ui](https://8bitcn.com/) | Ochiq kodli 8-bit komponentlar; retro ko‘rinish joriy tasdiqlangan dizaynga mos emas. | Sayt ochiq kodni ko‘rsatadi; bu tekshiruvda aniq litsenziya matni tasdiqlanmagan. |
| [EvilCharts](https://evilcharts.com/) | React chart komponentlari. [O‘rnatish yo‘riqnomasi](https://evilcharts.com/docs/recharts/installation) Recharts hamda shadcn/Tailwind sozlamasini talab qiladi. | Aniq komponent litsenziyasi tasdiqlanmagan. |

## Ko‘rib chiqishga eng kuchli nomzod

[ReUI Data Grid (Base UI)](https://reui.io/docs/components/base/data-grid) mavjud `frontend/spa/src/features/lifecycle/LifecycleWorkspace.tsx` requirements/tasks jadvalida saralash va filtrlash zarurati tasdiqlansa foydali bo‘lishi mumkin. [Aniq upstream commit](https://github.com/keenthemes/reui/commit/067aa0dc7f8bbfec1cd76fc5ad31f842be5f569a) va [registry manbasi](https://github.com/keenthemes/reui/blob/067aa0dc7f8bbfec1cd76fc5ad31f842be5f569a/public/r/styles/base-vega/data-grid.json) topildi. Unda Base UI, TanStack, dnd-kit va bir qancha shadcn komponentlari bor; ayrim generatsiyalangan fayllar upstream ilovasiga xos `IconPlaceholder` importiga tayanadi. Bu React-only SPA uchun kichik, tayyor bog‘liqliksiz almashtirish emas. ReUI namunasidagi tashqi rasm URLlari mahsulotga ko‘chirilmaydi. To‘liq transitive bog‘liqlik, shadcn registry versiyalari va litsenziyalar hali tasdiqlanmagan.

Integratsiyadan oldingi ishlar:

1. Aniq versiya/commit va zarur komponent manbalarini tekshirish; bog‘liqliklar, ularning litsenziyalari va React/Vite mosligini audit qilish; MIT copyright va permission matnini ko‘chirilgan kod bilan saqlash.
2. Talab va tasdiqlangan F01/F02 ekran doirasini tekshirish. Moddiy yangi boshqaruv yoki ko‘rinish bo‘lsa, rasm yaratib foydalanuvchi tasdig‘ini olish; tasdiqlangan vizual uslubni saqlash.
3. Filtr va saralash to‘liq datasetmi yoki faqat yuklangan sahifami qamrashini aniq ko‘rsatish. Server pagination, ruxsat va bekor qilingan ruxsat semantikasini saqlash; to‘liq natija da’vo qilmaslik.
4. UZ/RU/EN matnlari, klaviatura va fokus, screen reader, mobil ko‘rinish, katta matn va reduced motion holatlarini tekshirish; mavjud tafsilotlar, bo‘sh/xato va qisman ma’lumot holatlarini saqlash.
5. Faqat lokal paket va assetlardan foydalanish; namuna tashqi URLlari, telemetry, hosted AI va runtime tarmoq chaqiruvlarini kiritmaslik. Tanlangan komponent uchun test, build va ishlayotgan ekran tekshiruvlarini bajarish.

Bu baholash mahalliy AI talabini, mavjud authorization chegaralarini yoki alohida deployment ruxsati talabini o‘zgartirmaydi.
