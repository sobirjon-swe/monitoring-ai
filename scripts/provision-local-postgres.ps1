[CmdletBinding()]
param(
    [string]$PsqlPath = 'psql',
    [ValidateSet('127.0.0.1', 'localhost', '::1')][string]$DbHost = '127.0.0.1',
    [ValidateRange(1, 65535)][int]$DbPort = 5432,
    [ValidatePattern('^[a-z_][a-z0-9_]*$')][string]$AdminUser = 'postgres'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path $PSScriptRoot -Parent
$configPath = Join-Path $repoRoot '.local/db-config.json'
$databaseNames = @('monitoring_ai_dev', 'monitoring_ai_test')
$roleNames = @('monitoring_ai_owner', 'monitoring_ai_migrator', 'monitoring_ai_app')
$psqlExecutable = (Get-Command $PsqlPath -ErrorAction Stop).Source

function Invoke-LocalSql {
    param([string]$Database, [string]$Sql)
    if ($Database -notin (@('postgres') + $databaseNames)) {
        throw 'Database is outside the dedicated local allowlist.'
    }
    $start = [System.Diagnostics.ProcessStartInfo]::new()
    $start.FileName = $psqlExecutable
    # No credentials or SQL are placed in process arguments.
    $start.Arguments = "-X -w -h $DbHost -p $DbPort -U $AdminUser -d $Database -At -v ON_ERROR_STOP=1"
    $start.UseShellExecute = $false
    $start.CreateNoWindow = $true
    $start.RedirectStandardInput = $true
    $start.RedirectStandardOutput = $true
    $start.RedirectStandardError = $true
    $process = [System.Diagnostics.Process]::new()
    $process.StartInfo = $start
    try {
        [void]$process.Start()
        $outputRead = $process.StandardOutput.ReadToEndAsync()
        $errorRead = $process.StandardError.ReadToEndAsync()
        # Session-only logging settings prevent password DDL appearing in ordinary server logs.
        $process.StandardInput.WriteLine("SET log_statement = 'none'; SET log_min_error_statement = 'panic'; SET log_min_duration_statement = -1; SET log_min_duration_sample = -1; SET log_transaction_sample_rate = 0; SET log_duration = off;")
        $process.StandardInput.WriteLine($Sql)
        $process.StandardInput.Close()
        $process.WaitForExit()
        $output = $outputRead.GetAwaiter().GetResult()
        [void]$errorRead.GetAwaiter().GetResult()
        if ($process.ExitCode -ne 0) {
            throw 'Local PostgreSQL command failed; raw SQL/error output is suppressed to protect credentials.'
        }
        return ($output -split '\r?\n' | Where-Object { $_ -and $_ -ne 'SET' })
    } finally {
        $process.Dispose()
    }
}

function New-LocalPassword {
    $bytes = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return [Convert]::ToBase64String($bytes)
}

function Protect-LocalCredentialFile {
    # Restrict this credential file to the repository owner, the current process, and Windows administrators.
    # A fresh DACL also removes any pre-existing explicit broad grants on retries.
    $credentialAcl = [Security.AccessControl.FileSecurity]::new()
    $credentialAcl.SetAccessRuleProtection($true, $false)
    $principals = @(
        [Security.Principal.WindowsIdentity]::GetCurrent().User,
        [Security.Principal.NTAccount]::new((Get-Acl -LiteralPath $repoRoot).Owner),
        [Security.Principal.SecurityIdentifier]::new('S-1-5-18'),
        [Security.Principal.SecurityIdentifier]::new('S-1-5-32-544')
    )
    foreach ($principal in $principals) {
        $rule = [Security.AccessControl.FileSystemAccessRule]::new(
            $principal, [Security.AccessControl.FileSystemRights]::FullControl,
            [Security.AccessControl.AccessControlType]::Allow
        )
        [void]$credentialAcl.AddAccessRule($rule)
    }
    if ($PSVersionTable.PSEdition -eq 'Core') {
        [IO.FileSystemAclExtensions]::SetAccessControl((Get-Item -LiteralPath $configPath), $credentialAcl)
    } else {
        [IO.File]::SetAccessControl($configPath, $credentialAcl)
    }
}

# Both first provisioning and retries must keep credentials outside Git.
& git -C $repoRoot check-ignore -q -- .local/db-config.json
if ($LASTEXITCODE -ne 0) { throw '.local/db-config.json must be ignored by Git before provisioning.' }

# Validate all names before any role/database mutation. Never adopt unrelated objects.
$existingJson = Invoke-LocalSql 'postgres' @'
SELECT COALESCE(json_agg(item), '[]'::json)::text FROM (
    SELECT 'role' AS kind, rolname AS name, shobj_description(oid, 'pg_authid') AS marker,
           rolname AS owner, rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls AS unsafe,
           rolcanlogin AS can_login, rolinherit AS inherits
    FROM pg_roles WHERE rolname IN ('monitoring_ai_owner', 'monitoring_ai_migrator', 'monitoring_ai_app')
    UNION ALL
    SELECT 'database', datname, shobj_description(oid, 'pg_database'), pg_get_userbyid(datdba), false, false, false
    FROM pg_database WHERE datname IN ('monitoring_ai_dev', 'monitoring_ai_test')
) item;
'@
$existing = @()
foreach ($item in (($existingJson -join "`n") | ConvertFrom-Json)) {
    if ($null -ne $item) { $existing += $item }
}
$existingNames = @($existing | ForEach-Object { $_.name })
$config = $null
if (Test-Path -LiteralPath $configPath) {
    $config = Get-Content -LiteralPath $configPath -Raw | ConvertFrom-Json
    if ($config.version -ne 1 -or $config.host -ne $DbHost -or $config.port -ne $DbPort -or
        $config.development_database -ne $databaseNames[0] -or $config.test_database -ne $databaseNames[1] -or
        $config.owner_role -ne $roleNames[0] -or $config.migration_username -ne $roleNames[1] -or
        $config.runtime_username -ne $roleNames[2] -or
        $config.installation_id -notmatch '^[0-9a-f-]{36}$' -or
        $config.migration_password -notmatch '^[A-Za-z0-9+/]{43}=$' -or
        $config.runtime_password -notmatch '^[A-Za-z0-9+/]{43}=$') {
        throw 'Local credential configuration does not match this dedicated installation.'
    }
}
if ($existing.Count -gt 0 -and $null -eq $config) {
    throw 'Dedicated names already exist without this installation record; no objects were changed.'
}
if ($null -ne $config) {
    $marker = "monitoring-ai-local-v1:$($config.installation_id)"
    foreach ($item in $existing) {
        if ($item.marker -ne $marker -or $item.unsafe -or
            ($item.kind -eq 'database' -and $item.owner -ne $roleNames[0]) -or
            ($item.kind -eq 'role' -and $item.can_login -ne ($item.name -ne $roleNames[0])) -or
            ($item.kind -eq 'role' -and $item.inherits)) {
            throw "Existing dedicated object $($item.name) conflicts with this installation; no objects were changed."
        }
    }
}

$unexpectedMemberships = (Invoke-LocalSql 'postgres' @'
SELECT COUNT(*) FROM pg_auth_members m
JOIN pg_roles member_role ON member_role.oid = m.member
JOIN pg_roles granted_role ON granted_role.oid = m.roleid
WHERE member_role.rolname IN ('monitoring_ai_owner', 'monitoring_ai_migrator', 'monitoring_ai_app')
  AND NOT (member_role.rolname = 'monitoring_ai_migrator' AND granted_role.rolname = 'monitoring_ai_owner'
           AND NOT m.inherit_option AND m.set_option AND NOT m.admin_option);
'@) | Select-Object -Last 1
if ([int]$unexpectedMemberships -ne 0) {
    throw 'Dedicated roles have unexpected role memberships; no objects were changed.'
}

$serverVersion = (Invoke-LocalSql 'postgres' 'SHOW server_version_num;') | Select-Object -Last 1
if ([int]$serverVersion -lt 170000) {
    throw 'This local provisioner requires verified PostgreSQL 17+ role membership options.'
}
if ($null -eq $config) {
    [void](New-Item -ItemType Directory -Path (Split-Path $configPath -Parent) -Force)
    $config = [pscustomobject]@{
        version = 1
        installation_id = [Guid]::NewGuid().ToString()
        host = $DbHost
        port = $DbPort
        development_database = $databaseNames[0]
        test_database = $databaseNames[1]
        owner_role = $roleNames[0]
        migration_username = $roleNames[1]
        migration_password = New-LocalPassword
        runtime_username = $roleNames[2]
        runtime_password = New-LocalPassword
    }
    # CreateNew preserves an existing credential file even under concurrent provisioning.
    $file = [IO.File]::Open($configPath, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    $file.Dispose()
    Protect-LocalCredentialFile
    [IO.File]::WriteAllText($configPath, ($config | ConvertTo-Json), [Text.UTF8Encoding]::new($false))
} else {
    Protect-LocalCredentialFile
}
$marker = "monitoring-ai-local-v1:$($config.installation_id)"

$createRoles = @('BEGIN;', "SET LOCAL password_encryption = 'scram-sha-256';")
foreach ($role in $roleNames) {
    if ($existingNames -notcontains $role) {
        $login = 'NOLOGIN'
        $passwordClause = ''
        if ($role -eq 'monitoring_ai_migrator') {
            $login = 'LOGIN'
            $passwordClause = "PASSWORD '$($config.migration_password)'"
        } elseif ($role -eq 'monitoring_ai_app') {
            $login = 'LOGIN'
            $passwordClause = "PASSWORD '$($config.runtime_password)'"
        }
        $createRoles += "CREATE ROLE $role $login NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS $passwordClause;"
        $createRoles += "COMMENT ON ROLE $role IS '$marker';"
    }
}
$createRoles += 'GRANT monitoring_ai_owner TO monitoring_ai_migrator WITH INHERIT FALSE, SET TRUE;'
$createRoles += 'COMMIT;'
[void](Invoke-LocalSql 'postgres' ($createRoles -join "`n"))

foreach ($database in $databaseNames) {
    if ($existingNames -notcontains $database) {
        [void](Invoke-LocalSql 'postgres' "CREATE DATABASE $database OWNER monitoring_ai_owner TEMPLATE template0 ENCODING 'UTF8';")
        [void](Invoke-LocalSql 'postgres' "COMMENT ON DATABASE $database IS '$marker';")
    }
    [void](Invoke-LocalSql $database @"
BEGIN;
REVOKE ALL ON DATABASE $database FROM PUBLIC;
GRANT CONNECT ON DATABASE $database TO monitoring_ai_migrator, monitoring_ai_app;
ALTER SCHEMA public OWNER TO monitoring_ai_owner;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO monitoring_ai_app;
ALTER DEFAULT PRIVILEGES FOR ROLE monitoring_ai_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO monitoring_ai_app;
ALTER DEFAULT PRIVILEGES FOR ROLE monitoring_ai_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO monitoring_ai_app;
ALTER DEFAULT PRIVILEGES FOR ROLE monitoring_ai_owner REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
ALTER ROLE monitoring_ai_app IN DATABASE $database SET search_path = pg_catalog, public;
ALTER ROLE monitoring_ai_migrator IN DATABASE $database SET search_path = pg_catalog, public;
COMMIT;
"@)
}

Write-Output 'Dedicated local PostgreSQL roles/databases are provisioned. Credentials: .local/db-config.json (ignored).'
Write-Output 'Migrations: login monitoring_ai_migrator, SET ROLE monitoring_ai_owner before DDL. Runtime: monitoring_ai_app.'
Write-Output 'Cluster authentication and other database PUBLIC privileges are unchanged; provisioning does not establish global connection isolation.'
