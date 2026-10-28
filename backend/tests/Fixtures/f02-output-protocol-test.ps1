$ErrorActionPreference = 'Stop'
$helper = Join-Path $PSScriptRoot 'f02-output-protocol.ps1'
if (-not (Test-Path -LiteralPath $helper)) { throw 'Protocol prototype missing' }
. $helper
$p = [F02OutputProtocol]::new()
if ($p.State -ne 'new') { throw 'Initial state refused' }
$p.Prepare('01234567', 'aaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbb', ('c' * 64))
if ($p.State -ne 'prepared') { throw 'Prepare state refused' }
foreach ($invalid in @('', 'PUBLISH', 'publish ', "publish`0", ('x' * 1025))) {
    $bad = $false
    try { $p.Command($invalid) } catch { if ($_.Exception.InnerException -isnot [ArgumentException]) { throw }; $bad = $true }
    if (-not $bad -or $p.State -ne 'prepared') { throw 'Malformed protocol command accepted' }
}
$p.Command('abort')
if ($p.State -ne 'aborted') { throw 'Abort failed' }
$bad = $false
try { $p.Command('publish') } catch { if ($_.Exception.InnerException -isnot [InvalidOperationException]) { throw }; $bad = $true }
if (-not $bad) { throw 'Publish after abort accepted' }
$q = [F02OutputProtocol]::new()
$q.Prepare('01234567', 'aaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbb', ('c' * 64))
$duplicate = [IO.MemoryStream]::new([Text.Encoding]::ASCII.GetBytes("publish`npublish`n"))
$duplicateRefused = $false
try { $q.ReadCommand($duplicate) } catch { if ($_.Exception.InnerException -isnot [ArgumentException]) { throw }; $duplicateRefused = $true }
if (-not $duplicateRefused -or $q.State -ne 'prepared') { throw 'Duplicate frame accepted' }
$partial = [IO.MemoryStream]::new([Text.Encoding]::ASCII.GetBytes('publish'))
$partialRefused = $false
try { $q.ReadCommand($partial) } catch { if ($_.Exception.InnerException -isnot [IO.EndOfStreamException]) { throw }; $partialRefused = $true }
if (-not $partialRefused -or $q.State -ne 'prepared') { throw 'Partial command accepted' }
$frame = [IO.MemoryStream]::new([Text.Encoding]::ASCII.GetBytes("publish`n"))
$q.ReadCommand($frame)
if ($q.State -ne 'publish_requested') { throw 'Publish request state missing' }
Write-Output 'synthetic protocol framing and fail-closed abort: PASS'
