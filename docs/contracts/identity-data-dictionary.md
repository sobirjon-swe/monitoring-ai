# T06 tashqi identifikatorlar lug‘ati

Manba: tasdiqlangan E01 dizayn, texnik spetsifikatsiya 6.2, 21, 22.3, 23.1 va 25.1. FR-001/200, AC-09ning ushbu API/DB yo‘llari; E01-BE/DATA. FR-020ning hodisa paytidagi tarixiga mos ravishda identity attribution ham sanali. Bu GitLab integratsiyasi yoki to‘liq AC-09 reliz dalili emas.

| Jadval | Maydonlar va invariantlar |
| --- | --- |
| source_instances | UUID id, tenant_id; kind: gitlab/task_tracker/attendance/qa/manual; label (200). Bu manba instansining lokal reyestri; URL, credential, tarmoq chaqiruvi yo‘q. |
| identities | UUID id, tenant_id, source_instance_id; external_user_id (200) tenant/manba doirasida noyob. id/tenant/manba/external ID DB trigger bilan o‘zgarmas. candidate_name (200), candidate_email (254) ixtiyoriy signallar; employee bilan avtomatik bog‘lamaydi. |
| identity_mappings | UUID id, tenant_id, identity_id, employee_id; status candidate/approved/rejected; changed_by (haqiqiy foydalanuvchi), reason (1–1000); reviewer_id va reviewed_at faqat inson review qilingan holatda; valid_from/to UTC [from,to); revision > 0. |
| identity_mapping_histories | mapping_id, yuqoridagi har reviziyaning to‘liq snapshoti, recorded_at. DB trigger INSERT/UPDATE bilan atomik snapshot yozadi. tenant/mapping/revision noyob; runtime to‘g‘ridan-to‘g‘ri INSERT/UPDATE/DELETE/TRUNCATE qilolmaydi. UPDATE/DELETEni trigger ham rad etadi. 000005 SECURITY DEFINER triggeri fixed pg_catalog search_path, qualified objects va origin guard bilan FORCE RLS ostida ishlaydi; PUBLIC/runtime EXECUTE bekor. |

Barcha jadvallar tenant RLS va FORCE RLS bilan himoyalangan. Employee, source, identity, reviewer, changed_by va history mapping bog‘lanishlari tarkibli tenant FKlardan foydalanadi. Mapping identity kaliti o‘zgarmaydi, update reviziyani aynan bittaga oshiradi. Tasdiqlangan davrlar bir identity uchun ustma-ust kelmaydi (PostgreSQL btree_gist exclusion); yonma-yon davrlar mumkin. Tasdiqlanmagan yoki rejected mapping attribution bermaydi. Qarama-qarshi nomzodlar bir vaqtda saqlanishi mumkin va tekshiruvni talab qiladi.

| Endpoint /api/v1 ostida | Kirish va javob |
| --- | --- |
| POST source-instances | kind, label → 201 data; identity.manage. |
| POST source-instances/{source}/identities | external_user_id, ixtiyoriy candidate_name/email → 201 data; identity.manage. |
| POST identities/{identity}/mappings | employee_id, reason, valid_from, ixtiyoriy valid_to → faqat candidate, revision=1; identity.manage. Payload status/reviewer attribution bermaydi. |
| PATCH identity-mappings/{mapping} | expected_revision, status approved/rejected, employee_id, reason, valid_from, ixtiyoriy valid_to → data va yangi reviziya; identity.manage. reviewer_id/changed_by autentifikatsiyadan olinadi. Bu yangi to‘liq ko‘rib chiqish; valid_to yuborilmasa davr ochiq. |
| GET identity-mappings/{mapping}/history | ixtiyoriy after_revision; eng ko‘pi 100 snapshot, next_after_revision; identity.read. |
| GET identities/{identity}/attribution | majburiy at vaqt belgisi; approved va [valid_from, valid_to) ichida bo‘lsa mapping_id/employee_id/revision/davr, aks holda data:null; identity.read. |

Joriy tenant-wide identity.read/manage granti har so‘rovda qayta tekshiriladi; project/department granti tenant-wide endpointni ochmaydi. Source/identity boshqaruv vakolati employee ro‘yxati, loyiha yoki moliyaviy ruxsat yaratmaydi. Tenant/reviewer clientdan olinmaydi. Begona tenant/resurs 404, vakolat yo‘q 403, stale reviziya/noyoblik/FK/davr to‘qnashuvi 409, yaroqsiz sana/sabab 422. Umumiy error middleware xavfsiz JSON va request_id beradi.

identity.source_created, identity.created, identity.mapping_proposed, identity.mapping_reviewed audit kodlari haqiqiy actor va resurs ID/reviziyasini yozadi. Audit reason_code=identity_review; email, candidate nom, batafsil review reason audit/logga kiritilmaydi. Review reason faqat tenant/ruxsat bilan himoyalangan mapping/historyda saqlanadi. Tasdiqlangan retention/deletion siyosati bu bosqichda tanlanmagan.

Re-review joriy mappingni almashtiradi, oldingi reviziyalarni o‘chirmaydi; tarixiy hisoblash oldingi mapping/revision snapshotiga tayanishi kerak. E02 va keyingi tahlilda hosila natijalarni invalidate/recompute qilish alohida ish: T06da hali kanonik source events yoki analysis natijalari yo‘q. Multi-session stress va haqiqiy korporativ identifikatorlarni tekshirish ushbu lokal fixture natijasidan kelib chiqib da’vo qilinmaydi.

Timestamp precision: PostgreSQL timestamptz(6), UTC DateTime bindings and microsecond-aware comparisons preserve up to six fractional digits. [from,to) boundaries apply precisely to mapping/history, roles/memberships/reporting, grant/token expiry and audit instants. Incremental000006 was applied to dev/test; previously rounded/truncated timestamps cannot be recovered. Its down migration refuses lossy rounding.
