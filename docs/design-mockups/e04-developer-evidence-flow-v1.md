# E04 v1 — dasturchi dalillarining lokal deterministik poydevori

Sana: 2026-10-06. Holat: **diagramma v1/doira, yozma dizayn va implementation plan tasdiqlangan; partial R1–R6 implemented, R1–R6 code reviews and final whole-unit scoped re-review approved; actual runtime activation blocked/default disabled503**. E03 `32c4e24` asos, branch `developer-evidence-analysis`. Tasdiqlangan PNG o‘zgartirilmagan; uning ichidagi tarixiy pending yorlig‘i ushbu qayd bilan yangilanadi.

## Maqsad va spetsifikatsiya

MR/diff revisionini o‘zgarmas base/head SHA bilan bog‘lash, qo‘shilgan/o‘chirilgan qator tarkibini aniq hisoblash, qamrov va noaniqlikni ko‘rsatish, analysis job/natija va inson reviewini tarix bilan saqlash. Source locatorlar snapshotdagi path/side/line/SHA bilan tekshiriladi. Review mezonni qabul qilmaydi va E03 progressini o‘zgartirmaydi.

Asos: repository DOCX FR-060/061, FR-120ning deterministik qismi, §12/13/25, E04-BE/DATA/QA va AC-03/09/11ning foundation holatlari. To‘liq FR-120 uchun lokal LLM + static analyzer va alohida sifat/izolyatsiya tekshiruvi kerak. Ushbu v1 to‘liq E04 emas; AC-04 mehnat sarfi bahosi va avtomatik criterion satisfaction yo‘q.

## Uch yondashuv

1. **Tavsiya: synthetic MR facts + lokal Python parser + backend review.** Chegaralangan unified diff, immutable snapshot, durable job, qat’iy JSON/hash/locator va CAS review birga tekshiriladi. Haqiqiy diff hisoblash bo‘ladi, lekin LLM/syntax/static quality va real GitLab integratsiyasi implemented deb aytilmaydi.
2. Faqat backend kontrakt/job/review, keyin Python parser. Runtime yuzasi kichikroq, ammo vaqtincha fixture natija haqiqiy tahlil emas va qo‘shimcha bosqich kerak.
3. Lokal modelni provision/evaluate qilish bilan to‘liq vertical slice. FR-120ga yaqinroq, ammo model/license/checksum, CPU/RAM/VRAM, sandbox/egress va quality gate qarorlari hozir ochiq; E04 boshlash unseen model/provisioningni tasdiqlamaydi.

## Diagramma v1 doirasi va taxminlar

- Faqat alohida lokal, guarded CLI orqali ro‘yxatdan o‘tgan **synthetic** MR/diff fixture; tahlil API approved snapshot IDni oladi, ixtiyoriy URL yoki host checkoutni olmaydi. GitLab/Kanban APIga murojaat, token/webhook/source write yo‘q. Synthetic manba havolasi haqiqiy GitLab linki deb ko‘rsatilmaydi.
- Secret scan fail-closed: block/redact policy versiyasi va old/new hashlar, faqat tekshirilgan redacted snapshot workerga boradi; raw secret log/error/worker payloadga kirmaydi. Secret scanner haqiqiy korporativ ma’lumot uchun to‘liq DLP kafolati deb aytilmaydi.
- Laravel: current tenant/project/purpose/source authority, snapshot/so‘rov/outboxni atomic saqlash, lease/retry/fencing, worker natijasini tekshirish, facts/finding/review tarixi. Python: bounded text parsing/tasnif, credential yoki Laravel serialized job yo‘q; source kod, build/test script, arbitrary tool/URL bajarilmaydi.
- Taklif etiladigan Python: mavjud lokal 3.12.14, stdlib parser, yangi download/dependency kerak emas. Bu hali DEC-04 yoki production runtime tasdig‘i emas. Limit, izolyatsiya/egress va local runnerning haqiqiy dalillari yozma dizayn/rejada aniqlashtiriladi; worker activation gate o‘tmaganda unavailable/partial, yashirin fallback yo‘q. Fake FastAPI/LLM gateway ochilmaydi.
- Tarkib taxonomy: generated/vendor/application/tests/config/docs/unknown birlamchi disjoint buckets; mixed/rename/binary/unsupported/truncated va generator aloqasi mustaqil coverage flags. Path/marker qoidalari heuristic, review/correction append-only. Refactoring yoki semantik sifat pathdan taxmin qilinmaydi.
- AC-03 fixture: +5172 = 3800 generated +900 application +300 tests +100 config +72 docs; -378 mustaqil tasniflanadi. Unknown chiqarib tashlanmaydi, LOC employee rank/soat/value/financial-unit emas.
- Dedupe: faqat aynan isbotlangan scoped normalized patch content; hodisalar saqlanadi. Squash/cherry-pick munosabati noaniq bo‘lsa unresolved, ownership taxmin qilinmaydi. Open/merged/released alohida, ochiq MR accepted release bo‘lib ketmaydi.
- Pinlar: MR revision/base/head, source/snapshot hash, requirement/criterion/task version, analyzer/rule/contract/redaction versions. Force-push/source correction/requirement change yoki ruxsat bekor qilinishi bog‘liq current natijani stale/unknown qiladi; eski revision saqlanadi.
- Alohida proposed analysis.request/read/review/process project purposes; mavjud analysis.context va ticket ingestion-v1 o‘zgartirilmaydi. Idempotency tenant/caller/operationda, mismatch409; durable accepted202; review expected_revision409; current source/viewer/requester authority delivery/processingda qayta tekshiriladi.
- Review verified/rejected/context-added holatini append-only saqlaydi; review acceptance emas. AI interpretation explicitly unavailable. Semantic criterion inference, probabilistic confidence, actual employee hours yoki financial allocation yo‘q.

## Tekshiriladigan qabul holatlari

PostgreSQL RLS/composite FK/immutable SHA, scoped idempotency, CAS review, raw +5172/-378 conservation, unknown/mixed/generated/rename/binary/truncation, bounded parser/injection-as-data/secret exclusion, invalid locator/output reject, stale revision and authority, lease crash/retry/fencing, exact dedupe-event preservation. Production NFR/model quality/offline inference/syntax sandbox natijasi oldindan da’vo qilinmaydi.

## Joriy qabul chegarasi

[Yozma dizayn v1](../superpowers/specs/2026-10-06-e04-developer-evidence-design.md) va tasdiqlangan plan ayni PNG/doirada bajarildi. R1/R2/R4/R5 hamda R3 safe-disabled reviewlari o‘tgan, R1–R6 code reviews and final whole-unit scoped re-review approved. Final parent PHP324/7032 va Python51 hamda Pint/locked Composer passed; actual broker Python startup0xc0000142 va independent controlled non-loopback endpoint yo‘qligi activationni to‘sadi. Runner503, model absent; fake worker/standalone parser fixture haqiqiy activated vertical natija emas. [Dalillar va ochiq ishlar](../acceptance/e04.md). `grill-me` mavjud emas edi; spetsifikatsiya, mutaxassis tahlili va yozma qarorlar ishlatilgan. UI qo‘shilmagan, PNG qayta chizilmagan.
