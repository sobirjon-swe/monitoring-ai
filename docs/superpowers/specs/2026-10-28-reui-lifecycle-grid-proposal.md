# Lifecycle jadvali uchun ReUI Data Grid — dizayn taklifi

Holat: foydalanuvchi 2026-10-28 kuni maketni «Tasdiqlayman — qo‘llang» deb tasdiqladi. Rasm: `docs/design-mockups/reui-lifecycle-grid-v1.png`. Undagi loyiha va odamlar sun’iy namuna. Rasmda ko‘rsatilgan «12 ta» umumiy son faqat namuna: API umumiy sonni bermasa interfeys uni hisoblab ko‘rsatmaydi.

## Maqsad va chegaralar

Tasdiqlangan loyiha talablari va vazifalar ro‘yxatiga ReUI Data Grid manbasidan tekshirilgan mos qismlarni qo‘llash. Klaviatura va screen reader bilan ishlash, qidiruv, status filtri va xavfsiz saralashni ta’minlash. Tasdiqlangan F01/F02 uslubi, mavjud detail ko‘rinishi va UZ/RU/EN matnlarini saqlash.

API cursor orqali bir paytda eng ko‘pi 20 yozuv qaytaradi; qidiruv, filtr va saralash **faqat yuklangan joriy sahifaga** tegishli. Buni har doim ko‘rinadigan matnda aytish; bo‘sh natija butun loyihada ma’lumot yo‘q degani emas. «Keyingi sahifa» faqat server cursoridan foydalanadi. Qidiruv/filtr tufayli oxirgi ko‘rinadigan qatorni cursor deb qabul qilmaslik kerak; original server sahifasi cursorini saqlash kerak. Loyiha/sessiya almashishi va ruxsat bekor bo‘lishida eski qatorlar hamda filtrlash holati tozalanadi; kechikkan so‘rov natijalari qabul qilinmaydi.

## Bog‘liqlik va isbot

ReUI’ning ochiq MIT manbasi va konkret commit/litsenziyasi, kerakli paketlar va o‘tuvchi bog‘liqliklari audit qilinadi. ReUI misolidagi tashqi rasm URLlari, hosted xizmatlar va AI kodlari qo‘llanmaydi. Manbani yoki moslashuvni test/build va real desktop/mobil brauzerda tekshirish kerak; ekran o‘qigich, fokus, bo‘sh va xato holatlari, katta matn hamda kamaytirilgan animatsiya qamrab olinadi.

Tanlangan komponent cursor-pagination bilan adashtiruvchi raqamlangan sahifalarni yoki yozish/tahrir funksiyalarini qo‘shmasligi kerak. Zarur source/dependency tekshiruvi uddalanmasa kutubxona integratsiyasi qilingan deb e’lon qilinmaydi. Bu taklif F02 backend xavfsizligini yakunlash va mustaqil ko‘rigi o‘rnini bosmaydi.

## Sintetik brauzer ko‘rigi

Mahalliy Vite komponent sinovida sun’iy requirement ma’lumotlari bilan desktop va 390×844 mobil tasvirlar olindi: `docs/design-mockups/reui-running/requirements-desktop-synthetic.png` va `docs/design-mockups/reui-running/requirements-mobile-synthetic.png`. Mobil ko‘rinish kartochkalardan foydalanadi, qidiruv/saralash ko‘lami ko‘rinadi. Maketdagi och rangli navigatsiya o‘rniga mavjud F01 to‘q rangli qobiq saqlangan; requirement API bermaydigan sarlavha, holat va acceptance-criterion matnlari o‘ylab topilmaydi — ID, versiya va mavjud tafsilotlar ko‘rsatiladi. Mobil kartochkalar maketdan kengroq oraliqqa ega. Ushbu izolyatsiyalangan ko‘rik sessiya, API, sahifalash yoki haqiqiy ma’lumot bilan UAT emas.
