# F02 anonim sessiya to‘sig‘i — qo‘shimcha taklif

Holat: foydalanuvchi 2026-10-28 kuni diagramma va texnik taklifni «Tasdiqlayman — amalga oshiring» deb tasdiqladi. Bu `2026-10-28-f02-attempt-coordination-proposal.md` dagi barcha yangi jadvallar tenant bilan bog‘lanadi degan chegarani faqat kirishdan oldingi shaxsiy bo‘lmagan sessiya nazorati uchun aniqlashtiradi; tenantga tegishli BA ma’lumotlari tenant RLS va composite FK ostida qoladi.

## Nima uchun kerak

Brauzer hali tizimga kirmagan sessiya nusxasi bilan login so‘rovini boshlashi mumkin. Shu vaqtning o‘zida boshqa so‘rov logout qilsa, tenant hali aniqlanmaganligi sabab faqat tenant jadvaliga asoslangan nazorat logoutni yozib qola olmaydi. Kechikkan login keyinchalik yangi sessiya yaratib yuborishi mumkin. Bu tasdiqlangan logout va parallel so‘rov himoyasiga zid.

## Taklif

- Tenant aniqlanishidan oldin server sessiya IDning maxfiy server kaliti bilan hisoblangan HMACi, holat/generatsiya va qat’iy tugash muddatini maxsus global sessiya nazoratida qayd etadi. Unda token, foydalanuvchi, tenant, kontekst va biznes ma’lumoti saqlanmaydi.
- Jadval FORCE RLS va sukut bo‘yicha rad etish bilan himoyalanadi; tranzaksion kontekst faqat server sessiyasidan olinadi. Ushbu jadval uchun yangi umumiy tenant o‘qish vakolati yoki RLS bypass yaratilmaydi. HMAC va session key versiyalari, sessiya yangilanishi va bog‘lanishi fail-closed tekshiriladi.
- Login, logout va sessiya tiklanishi bir xil tranzaksion nazoratdan o‘tadi. Logout birinchi bo‘lsa undan oldingi sessiya nusxasidan keyinchalik login muvaffaqiyatli yakunlanmaydi. Authenticated sessiya uchun tenant/actor vakolatlari va BA yozuvi avval tasdiqlangan tenant doirasida qayta tekshiriladi.
- Holatlarning amal qilish muddati va tozalash jarayoni kechikkan so‘rovlar qayta jonlanmasligini isbotlashi kerak. Limit, kalit almashtirish va cleanup to‘liq loyihalanib, test va mustaqil xavfsizlik ko‘rigidan o‘tmaguncha bu kafolat bajarildi deb e’lon qilinmaydi.

## Qabul mezonlari

Kirishdan oldingi va keyingi parallel login/logout, eskirgan fayl-sessiya nusxasi, credential/tenant almashishi, xato login, vaqt tugashi, kalit almashishi, avariyadan tiklanish, RLS bo‘sh kontekst, o‘zga sessiya/tenant va o‘qish imkoni yo‘qligi alohida PostgreSQL tranzaksiyalari bilan sinovdan o‘tkaziladi. Noaniq holatda sessiya ochilmaydi; hech qanday tashqi xizmat yoki real ma’lumot ishlatilmaydi.

Tasdiqlangan rasm: `docs/design-mockups/f02-anonymous-session-fence-v1.png`. Sessiya kodi xatolarni bartaraf etmaguncha yakunlangan yoki deploy uchun tayyor deb hisoblanmaydi.
