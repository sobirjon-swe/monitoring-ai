[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repo = Split-Path $PSScriptRoot -Parent
$local = Join-Path $repo '.local'
$pg = Join-Path $local 'tools/pgsql/bin'
$php = Join-Path $local 'tools/php/php.exe'
$data = Join-Path $local 'postgres-data'
$logs = Join-Path $local 'p0'
$databasePort = (Get-Content -LiteralPath (Join-Path $local 'db-config.json') -Raw | ConvertFrom-Json).port
foreach ($required in @($php, (Join-Path $pg 'pg_ctl.exe'), (Join-Path $data 'PG_VERSION'), (Join-Path $repo 'backend/.env'), (Join-Path $repo 'backend/vendor/autoload.php'), (Join-Path $repo 'backend/public/ui-assets/index.html'))) {
    if (-not (Test-Path -LiteralPath $required)) { throw "Local prerequisite missing: $required" }
}
New-Item -ItemType Directory -Force -Path $logs | Out-Null

& (Join-Path $pg 'pg_ctl.exe') -D $data status *> $null
if ($LASTEXITCODE -ne 0) {
    $listener = Get-NetTCPConnection -LocalPort $databasePort -ErrorAction SilentlyContinue
    if ($listener) { throw "Port $databasePort belongs to another process; no process was stopped." }
    & (Join-Path $pg 'pg_ctl.exe') -D $data -l (Join-Path $local 'postgres-start.log') -w start
    if ($LASTEXITCODE -ne 0) { throw 'Monitoring PostgreSQL startup failed.' }
}
& (Join-Path $pg 'pg_isready.exe') -h 127.0.0.1 -p $databasePort
if ($LASTEXITCODE -ne 0) { throw 'Monitoring PostgreSQL is not ready.' }

$running = $false
try {
    $page = Invoke-WebRequest -Uri 'http://127.0.0.1:18080/app' -TimeoutSec 3 -UseBasicParsing
    $running = $page.StatusCode -eq 200 -and $page.Content -match '<title>Monitoring AI</title>'
} catch { }
if (-not $running) {
    $listener = Get-NetTCPConnection -State Listen -LocalPort 18080 -ErrorAction SilentlyContinue
    if ($listener) { throw 'Port 18080 belongs to another process; no process was stopped.' }
    $server = Start-Process -FilePath $php -ArgumentList @('artisan', 'serve', '--host=127.0.0.1', '--port=18080', '--tries=1') -WorkingDirectory (Join-Path $repo 'backend') -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logs 'server.stdout.log') -RedirectStandardError (Join-Path $logs 'server.stderr.log') -PassThru
    $server.Id | Set-Content -LiteralPath (Join-Path $logs 'server.pid')
    for ($attempt = 0; $attempt -lt 20; $attempt++) {
        Start-Sleep -Milliseconds 250
        try {
            $page = Invoke-WebRequest -Uri 'http://127.0.0.1:18080/app' -TimeoutSec 2 -UseBasicParsing
            if ($page.StatusCode -eq 200 -and $page.Content -match '<title>Monitoring AI</title>') { $running = $true; break }
        } catch { }
    }
    if (-not $running) { throw 'Monitoring web startup failed; inspect .local/p0/server.stderr.log.' }
}
Write-Output 'Monitoring AI: http://127.0.0.1:18080/app'
Write-Output 'Local demo credentials expire after eight hours. Startup does not renew or recreate access.'
