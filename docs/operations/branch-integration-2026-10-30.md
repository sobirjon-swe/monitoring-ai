# Branchlarni main ga birlashtirish — 2026-10-30

Foydalanuvchi branchlar farqini tekshirish va kerakli o‘zgarishlarni `main`ga merge qilishni aniq tasdiqladi. Bu serverga deploy yoki production qabulini anglatmaydi.

## Tarix va tanlov

Tekshiruv paytida remote `main`: `131880f`. `codex/impeccable-react-polish`: `ea95fc7`. Quyidagi branchlar to‘liq shu eng oxirgi branchning ajdodlari:

| Branch | Tekshirilgan head | Faqat shu branchdagi commitlar | Oxirgi branchda qo‘shimcha |
| --- | --- | --- | --- |
| main | 131880f | 0 | 12 |
| monitoring-platform-foundation | 804f620 | 0 | 9 |
| frontend-api-integration | 89f7453 | 0 | 2 |
| ba-workflow-frontend | 86b8bc8 | 0 | 1 |

Shu sabab avvalgi branchlarni alohida merge yoki cherry-pick qilish kerak emas. Oxirgi branchni `main`ga fast-forward qilish barcha tarixni saqlaydi. Eski branchlar o‘chirilmaydi. GitHub connector orqali PR yaratish `403 Resource not accessible by integration` bilan rad etildi; foydalanuvchi tasdiqlagan oddiy Git fast-forward yo‘li tanlandi. Force-push va branch himoyasini chetlab o‘tish qo‘llanmaydi.

## Merge oldidan topilgan va tuzatilgan cheklovlar

- Konfiguratsiyadagi lokal port guard qiymatiga mos kelishi saqlangan holda demo fixture entry-pointlari endi faqat literal55432 bilan cheklanmaydi. Port integer1..65535, keyingi mavjud guard esa haqiqiy server porti/rol/baza/marker va owner bo‘lmagan runtime holatini tekshiradi. O‘chiq BA setup authority yoqilmadi.
- Salbiy port testi haqiqiy portdan boshqa qiymat tanlaydi va konfiguratsiyani `finally`da tiklaydi. Parallel child-testlar va physical-test yordamchilari ham sozlangan trusted port bilan solishtiradi.
- TEST identity tekshiruvlaridan keyin concurrency dalillarining ignored kataloglari yaratiladi; baza reset qilinmaydi. Eski schema inventory debug faylini talab qiluvchi yon ta’sir o‘rniga snapshot mavjudligi assert qilinadi.
- BA izolyatsiya testi yangi `ba_demo_receipts`ni ham hisobga olib,13 jadvalning aniq inventari hamda HAR BIR jadvaldagi ENABLE/FORCE RLS holatini tekshiradi. Runtime nonowner/nosuper/nobypass shartlari saqlangan.

## Tekshiruv dalillari

- Backendning982 ta noyob testi ketma-ket guruhlarda qamrab olindi: dastlabki171 ta, tuzatilgan BA schema bo‘limining16 tasi va qolgan795 ta. JUnit case identifikatorlari birlashtirilib, takror yo‘qligi va982 ta inventar tasdiqlandi:979 passed,3 skipped,0 failure/error,25597 assertion. Bu bitta uzluksiz yashil run deb ko‘rsatilmaydi: dastlabki stale12-table test keyin to‘liq16-testli bo‘lim qayta yurishi bilan almashtirilgan.
- Uchta physical BA attempt testi disposable database manifesti berilmagani uchun mavjud qoida bo‘yicha skipped. Ular ishladi deb hisoblanmaydi.
- PHP test jarayoni512MiB limit bilan ishga tushirildi. Qolgan795 test24m36s davom etdi,262MiB peak; katta department QA queue SQL so‘rovlari ayniqsa sekin bo‘ldi. Funksional pass production ishlash tezligi/NFR qabulini anglatmaydi. Odatiy128MiB limitdagi oldingi xotira yetishmasligi yashirilmaydi.
- Frontend115 test, TypeScript va build passed; mavjud ikki ReUI use-no-memo build warning saqlangan. UI kodi shu tekshiruvdan keyin o‘zgarmagan.
- Python unittest51 test passed. Bu broker policy/adapter double testlari, haqiqiy Windows izolyatsiyasini aktivatsiya qilish dalili emas.
- O‘zgargan PHP fayllarida Pint, `git diff --check` va Composer strict validate passed. Mustaqil ancestry, port guard va RLS-test reviewlarda ochiq actionable topilma yo‘q.
- Haqiqiy lokal cookie sessiyasi: login authenticated, loyiha capabilities/progress HTTP200, logout204. Secretlar logga chiqarilmagan; boshqa foydalanuvchining brauzer sessiyasiga tegilmagan.

Mahalliy loglar va JUnit/aggregate JSON `.local/p0/merge-*`da; Gitga kiritilmaydi. Merge oddiy fast-forward yo‘li bilan bajariladi, commitlar tarixi saqlanadi.

## Saqlangan mahsulot chegaralari

Bu lokal MVP ishlab chiqish holatini integratsiya qiladi. Real lokal AI aktivatsiyasi, jonli source integratsiyalar, F02 writer DEV UAT, production SSO va server deploy yakunlandi deb hisoblanmaydi. Bosh sahifaning yakuniy desktop/mobile piksel solishtiruvi hali ochiq (`docs/acceptance/home-video-v3.md`). Demo tokenlar hamda `.env`, baza, runtime/dependency va build fayllari commitga kiritilmaydi.
