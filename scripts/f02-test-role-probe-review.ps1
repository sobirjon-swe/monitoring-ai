[CmdletBinding()]
param([string]$PsqlPath = 'psql')
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
# The remaining wrapper is a review draft, not an executable provisioning path.
throw 'TEST role probe disabled pending backend-lifetime and credential-path security proof.'
$repoRoot = Split-Path $PSScriptRoot -Parent
$recordPath = Join-Path $repoRoot '.local/db-config.json'
$recordFile = Get-Item -LiteralPath $recordPath -Force -ErrorAction Stop
if (($recordFile.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Protected installation record refused.' }
$acl = Get-Acl -LiteralPath $recordPath
$currentSid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
$ownerSid = ([Security.Principal.NTAccount]$acl.Owner).Translate([Security.Principal.SecurityIdentifier]).Value
if ($ownerSid -ne $currentSid -or -not $acl.AreAccessRulesProtected) { throw 'Protected installation record refused.' }
$allowed = @($currentSid, 'S-1-5-18', 'S-1-5-32-544')
foreach ($ace in $acl.Access) {
    $sid = $ace.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value
    if ($ace.IsInherited -or ($ace.AccessControlType -eq 'Allow' -and $sid -notin $allowed)) { throw 'Protected installation record refused.' }
}
$record = Get-Content -LiteralPath $recordPath -Raw | ConvertFrom-Json
# Current records have no probe administrator identity: refuse until a reviewed record supplies one.
if ($record.version -ne 1 -or $record.host -ne '127.0.0.1' -or $record.port -ne 55432 -or
    $record.test_database -ne 'monitoring_ai_test' -or $record.owner_role -ne 'monitoring_ai_owner' -or
    $record.installation_id -notmatch '^[0-9a-f-]{36}$' -or
    -not ($record.PSObject.Properties.Name -contains 'test_probe_admin_username') -or
    $record.test_probe_admin_username -notmatch '^[a-z_][a-z0-9_]*$') { throw 'Approved TEST probe identity unavailable.' }
if (Test-Path Env:PGPASSWORD) { throw 'Environment password authentication is refused.' }
$admin = $record.test_probe_admin_username
$marker = 'monitoring-ai-local-v1:' + $record.installation_id
$probeName = 'f02_test_probe_' + [Guid]::NewGuid().ToString('N')
$exe = (Get-Command $PsqlPath -ErrorAction Stop).Source
$sql = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'f02-test-role-probe-review.sql') -Raw
function Invoke-ProbeSql([string]$body) {
    $start = [Diagnostics.ProcessStartInfo]::new()
    $start.FileName = $exe
    $start.Arguments = "-X -w -h 127.0.0.1 -p 55432 -U $admin -d monitoring_ai_test -At -v ON_ERROR_STOP=1"
    $start.UseShellExecute = $false; $start.CreateNoWindow = $true
    $start.RedirectStandardInput = $true; $start.RedirectStandardOutput = $true; $start.RedirectStandardError = $true
    foreach ($key in @($start.EnvironmentVariables.Keys)) {
        if ($key -like 'PG*') { $start.EnvironmentVariables.Remove($key) }
    }
    $proc = [Diagnostics.Process]::new(); $proc.StartInfo = $start
    try {
        [void]$proc.Start()
        $stdout = $proc.StandardOutput.ReadToEndAsync(); $stderr = $proc.StandardError.ReadToEndAsync()
        $proc.StandardInput.WriteLine("SELECT set_config('f02.expected_installation_marker', '$marker', false), set_config('f02.expected_admin', '$admin', false), set_config('f02.expected_owner', 'monitoring_ai_owner', false), set_config('f02.probe_name', '$probeName', false);")
        $proc.StandardInput.WriteLine($body); $proc.StandardInput.Close()
        if (-not $proc.WaitForExit(15000)) { $proc.Kill(); $proc.WaitForExit(); throw 'TEST role probe timed out.' }
        [void]$stdout.GetAwaiter().GetResult(); [void]$stderr.GetAwaiter().GetResult()
        if ($proc.ExitCode -ne 0) { throw 'TEST role probe failed; raw output suppressed.' }
    } finally { $proc.Dispose() }
}
$verification = @'
DO $check$
BEGIN
 IF current_database() <> 'monitoring_ai_test' OR inet_server_addr() IS DISTINCT FROM '127.0.0.1'::inet
 OR inet_server_port() IS DISTINCT FROM 55432 OR session_user IS DISTINCT FROM current_setting('f02.expected_admin')
 OR current_user IS DISTINCT FROM current_setting('f02.expected_admin')
 OR current_setting('server_version_num')::int < 170000
 OR (SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = current_database()) IS DISTINCT FROM current_setting('f02.expected_owner')
 OR (SELECT shobj_description(oid, 'pg_database') FROM pg_database WHERE datname = current_database()) IS DISTINCT FROM current_setting('f02.expected_installation_marker')
 OR EXISTS (SELECT 1 FROM pg_roles WHERE rolname = current_setting('f02.probe_name')) THEN
 RAISE EXCEPTION 'TEST role probe rollback verification failed'; END IF;
END
$check$;
'@
try {
    Invoke-ProbeSql $sql
} finally {
    # A fresh session confirms rollback even when the probe failed.
    Invoke-ProbeSql $verification
}
Write-Output 'TEST role probe completed and rollback verified.'
