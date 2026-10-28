# Sintetik BA tokenini himoyalab chiqarish — taklif

Holat: foydalanuvchi 2026-10-28 kuni diagramma va taklifni «Tasdiqlayman — ishlab chiqing» deb tasdiqladi. Texnik tekshiruv talab qilinadi. Rasm: `docs/design-mockups/f02-synthetic-token-protection-v1.png`. Bu taklif faqat alohida F02 lokal sintetik tenant va uning writer tokeniga taalluqli; mavjud F01 tenant, journal va fayllariga tegilmaydi.

## Muammo

Mavjud credential helper oldindan yaratilgan pending faylga yozishda shu Windows foydalanuvchisiga tegishli boshqa fayl bilan NTFS hardlink orqali bog‘lanishi mumkin. Tekshirilgan sintetik tajribada bu boshqa faylning ustiga yozishga olib keldi. Path orqali tekshirib keyin yozish yoki publish qilish parallel almashtirish xavfini yopmaydi.

## Taklif etilayotgan chegaralar

1. Yangi alohida sintetik workspace va qisqa muddatli token yaratiladi. Token log, URL, command-line argument, environment, chat yoki ma’lumotlar bazasidagi ochiq matnga yozilmaydi.
2. Token Windows CurrentUser DPAPI orqali xotirada shifrlanadi. Yangi cheklangan fayl faqat eksklyuziv yaratiladi; oldindan mavjud pending fayl hech qachon yozish yoki truncate uchun ochilmaydi. Ruxsatlar va fayl identifikatori handle orqali tekshiriladi.
3. Token hash va shifrlangan fayl hashini bog‘laydigan versiyalangan receipt sintetik yozuvlar bilan bitta PostgreSQL tranzaksiyasida saqlanadi. Commitdan keyin shu ochiq fayl handle orqali replacement taqiqlangan holda yakuniy nomga chiqariladi. Buning uchun Windows handle-based rename no-replace usuli va himoyalangan faylning aniq identifikatori mustaqil tekshiriladi.
4. Uzilishdan so‘ng faqat o‘qish uchun ochilgan pending fayl receipt, fayl identifikatori, ciphertext hash, tenant snapshot va amal qilish muddati bilan tekshiriladi; noma’lum yoki precommit qoldiq qayta yozilmaydi va avtomatik qabul qilinmaydi. Hech qanday mavjud fayl almashtirilmaydi.
5. Tasdiqlangan lokal foydalanuvchi uchun tokenni xotirada deshifrlash va bir martalik login ekraniga kiritish yo‘li alohida tayyorlanadi; token agent chatiga chiqarilmaydi. UI/terminal ko‘rsatish usuli va secret saqlanishi koddan oldin aniq ko‘rib chiqiladi. DPAPI shu Windows akkauntida ishlayotgan boshqa jarayondan himoya deb da’vo qilinmaydi.

## Bajarilish shartlari

Bu rasm konsept; handle/ACL, DPAPI va recovery yechimi hali isbotlanmagan. Mustaqil Windows xavfsizlik ko‘rigi va alohida TEST da hardlink, path almashtirish, no-replace collision, ACL, buzilgan ciphertext, noto‘g‘ri receipt, oldingi/ketingi commit uzilishi, qayta urinish va maxfiy ma’lumot chiqmasligi sinovlari talab qilinadi. Fizik crash sinovi va savepoint simulyatsiyasi aniq farqlanadi. Agar texnik himoya isbotlanmasa, haqiqiy lokal workspace provision qilinmaydi. Avtomatik server deployment, real Kanban ma’lumotlari va tashqi AI yo‘q.
