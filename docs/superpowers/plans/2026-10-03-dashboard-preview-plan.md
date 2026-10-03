# Dashboard lokal preview rejasi

Manba: [tasdiqlangan UI v1](../../design-mockups/dashboard-ui-v1.png), [tasdiq](../../design-mockups/dashboard-ui-v1-approval.md). Foydalanuvchi 2026-10-03 kuni rasmni tasdiqladi va implementatsiyani boshlashni buyurdi.

## Qamrov va texnologiya

Birinchi ko‘rinadigan natija alohida `frontend/` ichida HTML5, CSS va vanilla JavaScript bilan yig‘iladi. Bu dependency-free lokal demo: backendning autentifikatsiya va RLS yo‘llarini o‘zgartirmaydi. Yakuniy production frontend frameworki va API ulash qarori ochiq qoladi. Tashqi font, CDN, AI API yoki telemetry chaqiruvi yo‘q.

## Tasklar

- UI-01: maket ranglari va komponentlari, lokal preview qamrovi va ishga tushirish yo‘lini qayd etish.
- UI-02: sidebar, kartalar, jadval, signal paneli, grafik va bo‘limlar ko‘rinishini yaratish.
- UI-03: namuna davr/qidiruv filtrlari, bo‘lim navigatsiyasi va signal dalili dialogini ishlatish. Demo ma’lumotlar belgisi doim ko‘rinadi; lokal inference hali ulanmaganligi ochiq aytiladi.
- UI-04: brauzerda boshlang‘ich ekran, filter va dialogni tekshirish; screenshotni rasmga solishtirish, mustaqil JS/UI review.
- UI-05: foydalanuvchiga lokal URL va screenshotni topshirish. Commit ruxsati alohida.

## Qabul

Desktopdagi asosiy kompozitsiya tasdiqlangan maketga mos. Nazoratlar haqiqatan demo natijalarini o‘zgartiradi. Dialog klaviatura bilan yopiladi va fokus qaytadi. Bo‘sh qidiruv holati tushunarli. Kichik ekranlarda kontent o‘qiladi; yangi mobil mahsulot dizayni tasdiqlangan deb hisoblanmaydi. Raqamlar, shaxslar, signallar va grafiklar haqiqiy backend yoki inference natijasi sifatida ko‘rsatilmaydi.

## Chegaralar

UI-01–UI-05 lokal preview doirasida bajarildi: brauzer sinovlari, desktop/mobil screenshot va mustaqil JS/UI review APPROVE. Dalillar: [dashboard preview tekshiruvi](../../acceptance/dashboard-preview.md). Commit ruxsati kutiladi.

Bu authenticated dashboard yoki haqiqiy loyiha/AI monitoringi emas; preview xizmatida maxfiy ma’lumotlar yo‘q. Haqiqiy API integratsiyasi, kirish ekrani va qolgan modullar alohida tasklarda bajariladi. Serverga deploy qilinmaydi.
