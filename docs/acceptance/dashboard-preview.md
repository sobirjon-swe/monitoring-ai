# Dashboard preview tekshiruvi

Sana: 2026-10-03. Qamrov — tasdiqlangan [UI v1](../design-mockups/dashboard-ui-v1.png) asosidagi mustaqil lokal demo. Production framework, haqiqiy login/API va inference ulanmagan.

Lokal URL: http://127.0.0.1:8080/ . Repository rootdan `python -m http.server 8080 --bind 127.0.0.1 --directory frontend` bilan ishlaydi. Faqat `frontend/` katalogi xizmat qiladi; loyiha `.env` yoki DB konfiguratsiyasi xizmatga berilmaydi.

## Brauzerda tekshirilgan

- Dashboard ochilishi, demo belgisi va maketdagi asosiy bloklar.
- Natijasiz qidiruvda bo‘sh holat, so‘ng qidiruvni tozalab jadval qaytishi.
- 7 kunlik davr tanlanganda 12 ta demo loyihadan 7 ta qolishi va grafik davri o‘zgarishi.
- Bitta loyiha tanlanganda bir qator va tegishli demo signallar/grafik ko‘rinishi.
- Grafik haftalik guruhlanishi.
- Loyihalar bo‘limida dashboard bloklari yashirilishi; umumiy holatga qaytish.
- Signal dalili native dialogda ochilishi, Escape bilan yopilishi va fokus chaqiruvchi tugmaga qaytishi.
- Lokal AI tugmasida inference hali ulanmaganligi aniq ko‘rsatilishi.
- Brauzer console error/warn ro‘yxati bo‘sh.
- Desktop 1440px va mobil 390px screenshotlar. Mobil jadval ichki scrollni saqlaydi; `position:relative` bilan yashirin table sarlavhalarining sahifani kengaytirishi tuzatildi. 390px viewportda document width 375px; jadval o‘z 349px konteyneri ichida 634px scrollga ega.

JS syntax: `node --check frontend/app.js` exit0. Source tekshiruvida tashqi asset, fetch/XHR, local/session storage yoki eval topilmadi; SVG namespace URL tarmoq chaqiruvi emas.

## Review va dalillar

Dastlabki mustaqil JS/UI reviewda Critical/High nuqson yo‘q. Kichik matn kontrasti va mobil profilning accessible name muammolari tuzatildi. Yakuniy mustaqil screenshot va JS/UI re-review: APPROVE, yangi topilma yo‘q. Desktop asosiy kompozitsiyasi maketga mos; mobil kontent o‘qiladi va jadval scrolli o‘z konteynerida qoladi.

- [Desktop screenshot](../design-mockups/dashboard-running-v1.jpg)
- [Mobil screenshot](../design-mockups/dashboard-running-mobile-v1.jpg)

Barcha raqamlar, ismlar, hodisalar va signallar synthetic fixture. Bu tekshiruv haqiqiy monitoring, connector yoki model sifati sinovi emas.
