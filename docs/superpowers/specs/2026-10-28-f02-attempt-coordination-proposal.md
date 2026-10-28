# F02 kontekst yuborishini sessiyada muvofiqlashtirish — taklif

Holat: foydalanuvchi diagramma va texnik taklifni 2026-10-28 kuni «Tasdiqlayman — amalga oshiring» deb tasdiqladi. Avval tasdiqlangan F02-01 ko‘rinishi va `context.add` maqsadi o‘zgarmaydi.

## Muammo

Javob serverda yozuv saqlanganidan keyin yo‘qolishi mumkin. React xotirasi sahifa yangilanganda yoki ikkinchi tabda mavjud emas. Oddiy faylli sessiyaning yozilishi va PostgreSQL tranzaksiyasi atomik emas. Shuning uchun faqat brauzer xotirasidagi kalit bilan tasdiqlangan «noaniqlik hal bo‘lguncha yangi urinish yo‘q» qoidasini ishonchli ta’minlab bo‘lmaydi.

## Taklif etilgan oqim

1. Browser sessiyasi va joriy tenant, loyiha, actor, BA roli, manba va asl dalil vakolatlari tekshiriladi. Faqat `context.add` qabul qilinadi.
2. PostgreSQL dagi tenant bilan chegaralangan atomik slot bir sessiya va aniqlashtirish uchun bitta faol urinishni band qiladi. Server yaratgan idempotency kaliti va aniq tana/reviziya shu urinishga bog‘lanadi. Turli tablardan raqobatlashuvchi boshqa urinish bloklanadi.
3. Band qilish alohida mustahkam commit qilinadi. Ijro slot qulfi ostida mavjud BA xizmati va receipt bilan bir tranzaksiyada bajariladi. Tizim uzilsa mavjud slot va receipt asosida ayni urinish qayta tekshiriladi; noma’lum natija yangi yozuv yaratishga ruxsat bermaydi.
4. Vakolatli brauzer sessiyasi mavjud urinishning minimal holatini qayta oladi; natija ko‘rilgach alohida CSRF bilan himoyalangan tasdiq slotni yakunlaydi. Takroriy yoki eskirgan tasdiq keyingi urinishni o‘chirmasligi kerak. Holat, ijro va tasdiqda vakolat qayta tekshiriladi.
5. Logout, sessiya almashishi, loyiha almashishi yoki ruxsat bekor qilinishida maxfiy ko‘rinish tozalanadi. Serverdagi sessiya bekor qilish to‘sig‘i parallel so‘rov eski sessiyani qayta jonlantirmasligini ta’minlaydi. Sessiya tugagach kafolatli retry va’da qilinmaydi.

## Ma’lumot va xavfsizlik chegarasi

- Yangi jadval(lar) tenant va loyiha composite foreign key, FORCE RLS, restricted runtime, faol slot uchun unique constraint va tranzaksion qulf bilan himoyalanadi; to‘liq model va qulf tartibi mustaqil PostgreSQL/xavfsizlik ko‘rigidan o‘tadi.
- Sessiya identifikatori server siri bilan HMAC qilinadi. Vaqtinchalik tana va kalit autentifikatsiyalangan shifrlash bilan faqat serverda saqlanadi; plaintext, token, kalit va kontekst URL, log yoki browser storagega kiritilmaydi.
- Tasdiq yoki vakolat/sessiya tugashi vaqtinchalik maxfiy materialni tozalaydi; uzilishdan keyingi tozalash, kalit rotatsiyasi va kechikkan so‘rovlar himoyasi test qilinadi. Sessiya timeoutini umumiy ma’lumot saqlash siyosati deb qabul qilmaymiz; domen receipt va hodisalarining mavjud saqlash qoidalari alohida qoladi.
- Bu yangi jadval va sessiya muvofiqlashtirishidir; real Kanban, F01 fixture, boshqa biznes voqealari, tashqi AI va production deploymentga ruxsat bermaydi.

## Qabul mezonlari

Parallel tablar va sahifa yangilanishi; claim va BA commit oldi/ketidan crash; javob yoki JSON yo‘qolishi; ayni urinishni tiklash; eskirgan/takroriy ACK; rol, manba, actor, tenant yoki sessiya vakolatini bekor qilish; logout poygasi; xatolikda fail-closed va RLS/composite FK izolyatsiyasi; xotira va loglarda maxfiy materialni oshkor qilmaslik. TEST faqat izolyatsiyalangan PostgreSQLda; real browser UAT alohida sintetik workspace bilan bajariladi.

Tasdiqlangan rasm: `docs/design-mockups/f02-ba-attempt-coordination-v1.png`. Tasdiq shu hujjatda ko‘rsatilgan sxema va oqim doirasiga taalluqlidir.
