# Monitoring AI

Yangilangan sana: 2026-10-03.

Xodimlar va loyihalar monitoringi uchun lokal AI tizimi.

## Joriy holat

Hozir loyiha talablari, E01 dizayni va kichik vazifalarga ajratilgan reja mavjud. Ilova, PostgreSQL va AI ishga tushirilgani hali tasdiqlanmagan; mahsulot kodi keyingi tasklarda yaratiladi.

- [Hujjatlar](docs/README.md)
- [Loyiha yo‘riqnomasi](AGENTS.md)
- [E01 dizayni](docs/superpowers/specs/2026-10-03-e01-foundation-design.md)
- [E01 vazifalar rejasi](docs/superpowers/plans/2026-10-03-e01-local-foundation-plan.md)

## Ishlash tartibi

Avval ilova, PostgreSQL va barcha AI hisoblashlari shu kompyuterda ishlaydi. Lokal natija tasdiqlangach ichki serverga ko‘chiriladi. Tashqi tayyor AI APIlari va cloud fallback ishlatilmaydi. GitLab kabi ruxsatli ma’lumot manbalari alohida read-only integratsiya hisoblanadi.

AI xulosalari dalillar va inson tekshiruviga tayanadi. Davomat, commitlar yoki LOCdan xodimning haqiqiy ishlagan soatlari yoki universal qiymat balli chiqarilmaydi. Moliyaviy taqsimot deterministik, tasdiqlanadigan va dastlab sandboxda ishlaydi; tizim to‘lov bajarmaydi.

## Git workflow

main boshlang‘ich hujjatlar uchun. Mahsulot implementatsiyasi alohida task branchida bajariladi; push, merge va deploy o‘z ruxsat doirasida yuritiladi. Avtomatik deploy sozlanmagan.
