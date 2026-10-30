# IT jamoasi uchun boshqaruv interfeysi maketlari

Sana: 2026-10-29. Holat: ko‘rib chiqish uchun v1 taklif; tasvirlar hali tasdiqlanmagan va mahsulot kodi o‘zgarmagan.

## Tasdiqlangan mahsulot qarorlari

- Dastlabki MVP IT jamoasi uchun. Boshqa sohalar keyingi kengayishdir.
- Rol ta’riflarini faqat administrator yaratadi. PM faqat o‘zi boshqaradigan loyiha doirasida mavjud rollarni xodimlarga biriktiradi.
- Lavozim/loyiha roli, amaliy ruxsat va AI tahlil mezoni alohida tushunchalardir. Rol yaratish avtomatik vakolat oshirish emas.
- Mavjud loyiha a’zoligi, sanali ko‘p rolli tayinlash, audit va tenant izolyatsiyasi saqlanadi.
- AI lokal ishlaydi. Ushbu maketlarda model hali ulanmagan; barcha ko‘rsatkich va shaxslar namunaviy.

## Dizayn yo‘nalishi

Foydalanuvchi bergan [video](https://x.com/heydamir/status/2115063463063998736/video/1) va [to‘liq post](https://x.com/gkxspace/status/2115408503133307157) o‘rganildi. Postdagi [Impeccable](https://github.com/pbakaus/impeccable) layout, typography, distillation va review tamoyillari manba sifatida hisobga olindi; paket o‘rnatilmadi yoki uning buyruqlari bajarildi deb da’vo qilinmaydi.

Taklif: sokin charcoal asos, bitta teal urg‘u, yupqa chegaralar, aniq sarlavha/matn ierarxiyasi va 8px asosidagi oraliqlar. Tipografiya oilasi hali texnik tanlov sifatida qat’iy belgilanmagan. Rang faqat holat va asosiy amalni ajratadi. UUID va texnik hashlar asosiy jadval o‘rniga tafsilotlarga joylashtiriladi. Dark ko‘rinish ushbu taklifning doirasi; light variant hali chizilmagan.

## Tasvirlar

1. [Umumiy panel va xodimlar](it-admin-overview-employees-v1.png): qabul qilingan mezonlar, loyihalar, to‘siqlar, AI unavailable holati; xodimlar qidiruvi, bo‘lim/loyiha filtrlari va rollar jadvali.
2. [Administrator va PM](it-admin-roles-assignment-v1.png): administrator rol katalogi va yangi rol drawer; PM loyiha jamoasi va mavjud rollardan tanlash. PM ekranida rol yaratish amali yo‘q.
3. [Mobil ekranlar](it-admin-mobile-v1.png): mobil panel va sanali rol biriktirish bottom sheet.

## Ko‘rib chiqish natijasi va chegaralar

Tasvirlar vizual tekshirildi: admin/PM vazifalari ajratilgan, namunaviy ma’lumotlar belgilangan, AI faol deb ko‘rsatilmagan, xodimlar uchun universal ball yo‘q. Bu generatsiya qilingan konsept tasvirlar; accessibility/kontrast, klaviatura, haqiqiy responsive o‘lchamlar va API ishlashi tasdiqlanmagan.

Tasvirlar o‘rtasidagi mayda logo, avatar va navigatsiya nomlari farqlari implementatsiyadan oldin yagona tizimga keltiriladi: mahsulot nomi Monitoring AI; kanonik bo‘limlar Umumiy panel, Xodimlar, Loyihalar, Rollar, Hisobotlar, Sozlamalar. PMga Rollar katalogi ko‘rsatilmaydi. Xodim avatari uchun standart initials; surat ixtiyoriy. Mobil qo‘shimcha tablar barcha modullar yetkazilganini anglatmaydi.

Maketlar ma’lumot arxitekturasi va vizual yo‘nalishni ko‘rib chiqish uchun. Ular yangi admin privilegiyalari, source integratsiya, AI inference yoki keyingi implementatsiya uchun o‘z-o‘zidan ruxsat emas. Keyingi bosqich tasvir/doira tasdig‘i, aniq yozma dizayn va bosqichli implementatsiya rejasidir.

Built-in image_gen ishlatildi. To‘liq promptlar: [it-admin-prompts-v1.json](it-admin-prompts-v1.json). Rasmlar repositoryda saqlangan; asl generatsiyalar ham saqlangan. Commit/push bajarilmadi; P0ning untracked hujjat va startup skripti saqlangan.
