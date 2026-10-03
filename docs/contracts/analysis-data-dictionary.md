# T07 sun’iy kontekst kontrakti

Manba: tasdiqlangan E01 dizayn/reja, FR-110/160/200 va AC-09ning ushbu ichki ruxsat tekshiruvlari; E01-BE. Ushbu bosqich faqat lokal sun’iy fixturelar uchun JSON chegarasini tekshiradi. Haqiqiy artifact retrieval, inference, embedding, sirlarni aniqlash/yashirish pipeline yoki model sifati bajarilgan deb ko‘rsatilmaydi.

TrustedAnalysisScope haqiqiy AuthenticateLocalToken/LocalTokenAuthenticator tekshirgan TrustedActor, loyiha UUID va faqat analysis.context maqsadidan olinadi. Tenant actor orqali keladi; model yoki fixture tenantni tanlamaydi. AuthorizedContextClient getSnapshot(scope, artifactId) joriy gateway grant/policy/user/tenant va loyiha a’zoligini qayta tekshiradi. SyntheticArtifactRegistry ichki lokal yozuvlarni tenant/loyiha va approved=true bilan tekshiradi; standart reyestr bo‘sh va hech qanday tarmoq chaqirmaydi.

| Kontrakt | Maydon va chegaralar |
| --- | --- |
| analysis-context.v1 | tenant_id/project_id/actor_id/artifact_id UUID; purpose=analysis.context; artifact_version; evidence_ids (1–100, noyob UUID); content synthetic=true va text ≤20000; coverage partial/unavailable va cheklangan reasons; snapshot_hash SHA256. Qo‘shimcha maydonlar rad etiladi. |
| analysis-output.v1 | analysis_run_id UUID; tenant/project/purpose va input_snapshot_hash; eng ko‘pi 50 findings. Har biri finding_id, category delivery/quality/blocker/schedule_risk/evidence_gap, explanation ≤4000, 1–100 noyob evidence UUID, coverage/reasons, confidence source/link/claim/reasons va faqat pending review_state. Qo‘shimcha maydonlar rad etiladi. |

Confidence high/medium/low/unknown — alohida manba, bog‘lanish va da’vo tavsiflari; kalibrlangan ehtimollik emas. Raqamli model self-confidence qabul qilinmaydi. Fixture doirasida to‘liq real qamrov da’vosi yo‘q: coverage faqat partial/unavailable. Bu cheklov kelajak modulda alohida versiya bilan kengaytiriladi.

Snapshot xeshi actor/tenant/project/purpose, artifact versiyasi, authorized evidence, coverage va contentni oladi; snapshot_hash maydonining o‘zi kiritilmaydi. Associative object kalitlari rekursiv saralanadi, list tartibi saqlanadi; JSON UTF-8 va unescaped Unicode/slashes bilan SHA256 hisoblanadi. Turli serializatsiya kalit tartibi xeshni o‘zgartirmaydi; boshqa ma’lumot/versiya yoki scope o‘zgarsa xesh o‘zgaradi.

validateOutput(scope, snapshot, output) topshirishdan oldin getSnapshotni qayta chaqirib joriy ruxsatni tekshiradi. Caller yaratgan AuthorizedSnapshot vakolat hisoblanmaydi: hash va to‘liq payload joriy ruxsatli snapshotga teng bo‘lishi shart. So‘ng haqiqiy JSON Schema output/versionni, tenant/project/purpose/xeshni va har finding evidence_idsning aynan shu snapshotga ruxsatini tekshiradi. Bekor qilingan grant/a’zolik, almashtirilgan loyiha/tenant, unapproved artifact, soxta snapshot/dalil yoki accepted holat rad etiladi. Natijani bazada saqlash/yakunlash endpointi yo‘q.

Opis/json-schema 2.6.0 (Apache-2.0), opis/string 2.1.0 va opis/uri 1.1.0 composer.lock bilan belgilangan. CompliantValidator faqat diskdagi stdClass sxemalarni ishlatadi; remote resolver/loader ro‘yxatdan o‘tkazilmaydi, faqat #/ ichki refs ruxsatli. Schemalardagi $schema/$id identifikator metama’lumotidir. Validate kirish JSONi ≤1 MB, decode chuqurligi 64, schema maydonlari alohida cheklangan. Xatolar raw payload/schema diagnostikasini chiqarish o‘rniga xavfsiz kontrakt xabarini qaytaradi. Rasmiy API: [Opis Validator](https://opis.io/json-schema/2.x/php-validator.html), [Opis repository](https://github.com/opis/json-schema).

T07 inference/provisioning policy yoki real AI internet-blok testini almashtirmaydi. Oddiy Composer dependency provisioning tasdiqlangan; bu inference API emas. Mahsulot AI faqat lokal/company-controlled infratuzilmada keyingi alohida bosqichda joriy etiladi. Sintetik matndagi ko‘rsatmalar oddiy data bo‘lib qoladi; tool/shell/network bajaruvchi yo‘l bu modulda yo‘q.
