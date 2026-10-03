# Lokal dashboard UI preview

Tasdiqlangan `docs/design-mockups/dashboard-ui-v1.png` asosidagi birinchi mustaqil preview. HTML/CSS/vanilla JS, system font va inline SVG; dependency, CDN, tashqi asset yoki tarmoq API chaqiruvi yo‘q. Production frontend stack tanlovi hali ochiq.

`frontend/index.html`ni brauzerda oching yoki mavjud lokal static server orqali shu katalogni ko‘rsating. Backend yo‘llari, tokenlar, DB va server konfiguratsiyasi o‘zgartirilmaydi.

Davr 30/14/7 kun, loyiha/qidiruv filtri, kunlik/haftalik grafik va qatorni ko‘rsatish tugmalari demo ma’lumotlarni o‘zgartiradi. Signal dalili, loyiha va bo‘lim tafsiloti native dialogda; Escape yopadi va fokus oldingi tugmaga qaytadi. Sidebar bo‘limlari faqat demo sahifalar. Lokal AI tugmasi inference hali ulanmaganini tushuntiradi.

Barcha loyiha/a’zo/hodisa/commit/signallar synthetic fixture. Manbalar 4/4 kartasi haqiqiy connector holati emas. Faollik haqiqiy ish soati, reyting yoki intizomiy xulosa emas. CRUD, haqiqiy login, API integratsiyasi va AI inference yo‘q. Desktop tasdiqlangan rasmga tayangan; tor ekranlarda kartalar qayta joylashadi va jadval gorizontal aylantiriladi.

Tekshiruv: JS syntax, brauzerda filter/dialog/keyboard va desktop/mobile screenshotlarni review qilish. Visual va code review lead tomonidan alohida bajariladi; ushbu fayl hali o‘tmagan tekshiruvni passed deb ko‘rsatmaydi.
