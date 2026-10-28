$ErrorActionPreference = 'Stop'
$path = Join-Path $PSScriptRoot 'f02-bounded-drain.ps1'
if (-not (Test-Path -LiteralPath $path)) { throw 'Bounded drain missing' }
. $path
$ok = [F02BoundedDrain]::Read([IO.StringReader]::new('small'), 8)
if (-not $ok.Wait(5000) -or $ok.Result -ne 'small') { throw 'Small drain failed' }
$tooLarge = [F02BoundedDrain]::Read([IO.StringReader]::new('123456789'), 8)
$refused = $false
try { [void]$tooLarge.GetAwaiter().GetResult() } catch { if ($_.Exception.InnerException -isnot [IO.InvalidDataException]) { throw }; $refused = $true }
if (-not $refused) { throw 'Oversized drain accepted' }
Write-Output 'bounded asynchronous drain: PASS'
