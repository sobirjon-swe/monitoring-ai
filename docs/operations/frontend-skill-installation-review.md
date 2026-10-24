# Frontend skilllarini o‘rnatish va tekshirish

2026-10-24. Foydalanuvchi avval o‘rnatishni ko‘rib chiqishni, keyin uchala skillni o‘rnatishni aniq tasdiqladi. Quyidagi tarkib **o‘rnatildi va versiya/hash/litsenziya tekshiruvidan o‘tdi**. Bu yangi UI rasmlari yoki product implementatsiyasi tasdig‘i emas. React SPA keyingi alohida foydalanuvchi tanlovidir.

| Skill | Muallif manbasi | Tekshirilgan commit | Katalog |
| --- | --- | --- | --- |
| ui-ux-pro-max | [nextlevelbuilder/ui-ux-pro-max-skill](https://github.com/nextlevelbuilder/ui-ux-pro-max-skill/tree/d14efff8ed1faa4187f004f79926f428c8074ec7/.claude/skills/ui-ux-pro-max) | d14efff8ed1faa4187f004f79926f428c8074ec7 | .claude/skills/ui-ux-pro-max |
| grill-me | [mattpocock/skills](https://github.com/mattpocock/skills/tree/5beb35b040e3de97e54fa9e386d7e42d15c295c2/skills/productivity/grill-me) | 5beb35b040e3de97e54fa9e386d7e42d15c295c2 | skills/productivity/grill-me |
| grilling | [mattpocock/skills](https://github.com/mattpocock/skills/tree/5beb35b040e3de97e54fa9e386d7e42d15c295c2/skills/productivity/grilling) | 5beb35b040e3de97e54fa9e386d7e42d15c295c2 | skills/productivity/grilling |

`grill-me` faqat `grilling`ni chaqiruvchi wrapper, shuning uchun ikkalasi kerak. Codexda yo‘riqnomani native skill o‘qish mexanizmi orqali ochish kerak; mavjud bo‘lmagan literal Claude Skill tool chaqirilgan deb ko‘rsatilmaydi. UI yo‘riqnomasidagi CLAUDE_PLUGIN_ROOT o‘rniga aniq Codex o‘rnatish pathidan foydalaniladi.

## Tekshiruv natijasi

Builtin skill-installer va plugin-management yo‘riqnomalari o‘qildi. Plugin katalogi qidiruvi aynan shu ikki skill paketini qaytarmadi; muallif GitHub repozitoriylari va exact commitlar tekshirildi. O‘rnatish yordamchisi product GitHub originiga tegmaydi.

Ignored `.local/frontend-skill-source-audit/`ga faqat matnli ko‘rik nusxalari yuklandi; faol skill katalogiga yozilmadi va uchinchi tomon kodi bajarilmadi. Root36 UI va4 grilling matn blobini GitHub tree SHA bilan solishtirdi: mismatch0; uchta target katalog mavjud emas. Root LICENSE fayllarini ham ko‘rik uchun oldi. Mustaqil `gpt-6.1-sol`/`medium` mutaxassis exact UI skillning search/core/design_system/reasoning_contract/validate_data beshta Python faylini statik o‘qib, blob hashlarini alohida tekshirdi.

Ko‘rilgan runtime/validator fayllarida Python standart kutubxonasi va mavjud lokal modullar ishlatilgan; tarmoq klienti/subprocess/dynamic code execution/package install/telemetry chaqiruvlari topilmadi. urllib.parse URL matnini tahlil qiladi, HTTP so‘rovi emas. reasoning_contract JSON duplicate/condition/action allowlist va deterministik regex/string qoidalarini ishlatadi.34 CSV konfiguratsiya reference pinned treeda bor. UI skill subtree73 oddiy fayl/7 katalogdan iborat, symlink yo‘q.

Bu statik ko‘rik bilan cheklangan: CSV/provenance ichidagi tavsiyalar hali o‘qilmagan va runtime/offline xulqi tajribada tekshirilmagan. Test katalogida subprocess/temp-write va tashqi repository tooling havolalari bor; ularni avtomatik bajarish taklif qilinmaydi. `--persist` yozuv yaratadi, `--force` almashtiradi; dastlab ikkalasi ham ishlatilmaydi. Google Fonts/GSAP/external asset tavsiyalari yangi dependency/tarmoq yuklashga ruxsat emas. Mahsulotning lokal-only AI, mahalliy asset va oldindan rasm tasdig‘i talablari saqlanadi.

## Tasdiqdan keyingi aniq o‘rnatish doirasi

Faqat uchta tanlangan katalog va ikkala muallifning MIT license notice nusxalari `C:/Users/sobirjon.qurbonov/.codex/skills/` ostiga, shu commitlardan builtin `install-skill-from-github.py` orqali `--method download --ref <exact SHA>` bilan. Npm/pip/global OS package, boshqa barcha skilllar, provider key, avtomatik yangilash yoki loyiha deploymenti kerak emas. Mavjud Python runtime yetarli. User-profile katalogi workspace tashqarisida bo‘lgani uchun haqiqiy yozish normal approval mexanizmi orqali bajariladi; xavfsizlik cheklovi chetlab o‘tilmaydi.

Actual install: builtin helper ikki muvaffaqiyatli `--method download --ref <exact SHA> --dest .../.codex/skills` chaqiruvi bilan ui-ux-pro-max, grill-me va grillingni o‘rnatdi. Keyin uchala katalogga muallif MIT LICENSE nusxalari qo‘shildi.77 upstream faylning Git blob hashi pinned tree bilan solishtirildi: UI73, grill-me2, grilling2; mismatch0, missing0; uchala SKILL.md va license tekshirildi. Receipt ignored `.local/frontend-skills-install-receipt.json`da. Npm/pip/OS paket, boshqa skill, provider key, avtomatik update yoki product kodi o‘zgartirilmadi.

Skill-installer bo‘yicha yangi skilllar keyingi user turnida katalogda mavjud bo‘ladi; ushbu bosqichda yo‘riqnomalar verified absolute path orqali o‘qildi, katalog avtomatik yangilandi deb da’vo qilinmadi. UI/UX mutaxassisi keyin lokal generic guidance querylarini native Python `-B` bilan, oddiy network-restricted sandboxda bajardi; bu o‘rnatish oldi statik ko‘rikdan keyingi alohida foydalanishdir. Oldingi receiptning `third_party_code_executed=false` maydoni o‘rnatish tekshiruvi vaqtiga tegishli. Qidiruv natijalari va mos kelmagan variantlar UI briefda qayd etilgan. Hamma yangi agent dispatchlari explicit `gpt-6.1-sol`/`medium`.
