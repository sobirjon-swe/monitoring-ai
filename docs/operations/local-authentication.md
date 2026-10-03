# Lokal development autentifikatsiyasi

2026-10-03. FR-001/020/200 va AC-09ning lokal API yo‘llari; E01-BE/DATA. Bu haqiqiy SSO/pilot security tasdig‘i emas. Dedicated dev/test [inkremental migration](local-development.md) tayyor bo‘lishi shart. DB sessiyalari UTC, period [from,to), project displey Asia/Tashkent.

## Explicit lokal setup

backend/ ichida yangi synthetic tenant/subject uchun PowerShell misoli:

```powershell
$tenantId = [guid]::NewGuid().ToString()
$loginSubject = 'local-' + [guid]::NewGuid().ToString('N')
php artisan app:bootstrap-local --tenant="$tenantId" --name="Local development" --subject="$loginSubject"
```

Bu persistent lokal yozuvlarni yaratadi. Mavjud intended tenant bilan ishlaganda uning UUIDini tanlang; qayta random tenantga o‘tish kerak emas. Issuer/subject almashtirilmaydi. Faqat APP_ENV=local, database.default=pgsql, pgsql driver, loopback/no URL/read/write override, monitoring_ai_dev/monitoring_ai_app bilan ishlaydi. Guard connectiondan oldin, so‘ng actual marker/database/runtime tekshiriladi. User/subject/token/policy/grant/audit atomik.

Token bir marta terminalda ko‘rsatiladi; lokal clientda xavfsiz saqlang, chat/log/Gitga yubormang. Faqat SHA256 secret hash DBda. Token/setup grantlari 8 soat. HTTP Authorization: Bearer orqali opaque local token (JWT emas); local/testing va loopback mijozdagina, productionda rad etiladi. X-Tenant-ID/X-Actor-ID/body actor/tenant/reviewer vakolat bermaydi.

## Employee bilan explicit bog‘lash

Birinchi bootstrap employee_id NULL login yaratadi: tashkilot CRUD granti bor, current project membership talab qiluvchi read/manage/roles ochilmaydi. Shu tenantda ruxsatli client orqali POST /api/v1/employees (display_name, reason_code=approved_change) bilan employee yarating yoki ruxsatli mavjud UUIDni tanlang. So‘ng aynan shu tenantga yangi linked subject:

```powershell
$employeeId = Read-Host 'Shu tenantdagi mavjud employee UUID'
$linkedSubject = 'linked-' + [guid]::NewGuid().ToString('N')
php artisan app:bootstrap-local --tenant="$tenantId" --name="Linked local developer" --subject="$linkedSubject" --employee="$employeeId"
```

Composite FK boshqa tenant employee bilan linkni rad etadi; ism/email merge qilmaydi. Linked login project yaratganda employee uchun current membership avtomatik yoziladi; boshqa HTTP grant yaratilmaydi. Signup/self-admin/access-grants CRUD endpointi yo‘q.

GET /api/v1/me verified userni ko‘rsatadi. Bootstrap organization.read/manage, project.create/read/manage, roles.read/manage, membership.manage va identity.read/manage tenant grantlarini beradi; analysis.context bermaydi. Current membership project/roles/membership accessga qo‘shimcha shart. Tenant-wide organization/identity ro‘yxatlari department/project grantiga kengaymaydi; source admin employee/code/finance report vakolatini yaratmaydi.

Token/subject/user/tenant/policy/grant va membership holati har so‘rovda tekshiriladi. Historical role current revoked accessni qaytarmaydi. Optimistic stale update va identical-role overlap (jumladan PATCH) 409; transaction eski period/reviziya/auditni saqlaydi. Turli parallel roles/adjacent periods ruxsatli; multisession stress o‘lchanmagan.

Testlar verified synthetic actor va dedicated PostgreSQL runtime rolida. CLI success testi dedicated devda outer transaction rollback qiladi; persisted admin/secret yaratmaydi va token test outputida chiqarilmaydi. Production SSO/MFA, rotation, rate limiting, policy/grant UI va pilot approval E01-OPSning ochiq ishlaridir.

HEAD uses GET read authorization/handlers and has an empty body; only POST/PATCH mutate. Fractional grant/membership/token expiry boundaries are checked through timestamptz(6) and precise UTC bindings.
