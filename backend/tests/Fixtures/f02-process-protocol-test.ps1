$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'f02-bounded-drain.ps1')
$child = Join-Path $PSScriptRoot 'f02-synthetic-output-child.ps1'
function Read-Bounded($reader) {
    $watch = [Diagnostics.Stopwatch]::StartNew()
    $buffer = New-Object char[] 1
    $line = New-Object Text.StringBuilder
    while ($line.Length -le 4096) {
        $remaining = 15000 - [int]$watch.ElapsedMilliseconds
        if ($remaining -le 0) { throw 'Frame timeout' }
        $task = $reader.ReadAsync($buffer, 0, 1)
        if (-not $task.Wait($remaining)) { throw 'Frame timeout' }
        if ($task.Result -eq 0) { throw 'Missing frame' }
        if ($buffer[0] -eq "`n") { return $line.ToString().TrimEnd("`r") }
        [void]$line.Append($buffer[0])
    }
    throw 'Oversized frame'
}
function Stop-ProcessSafely($process) {
    if ($process) {
        try { if (-not $process.HasExited) { $process.Kill() }; if (-not $process.WaitForExit(15000)) { throw 'Process was not reaped' } } finally { $process.Dispose() }
    }
}
function Start-SyntheticChild {
    $start = New-Object Diagnostics.ProcessStartInfo
    $start.FileName = 'C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe'
    $start.Arguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $child + '"'
    if ($start.Arguments -match 'fictional-test-value' -or @($start.EnvironmentVariables.Values | Where-Object { $_ -match 'fictional-test-value' }).Count) { throw 'Sentinel leaked into argv or environment' }
    $start.UseShellExecute = $false; $start.CreateNoWindow = $true
    $start.RedirectStandardInput = $true; $start.RedirectStandardOutput = $true; $start.RedirectStandardError = $true
    $process = [Diagnostics.Process]::Start($start)
    try {
        $stderr = [F02BoundedDrain]::Read($process.StandardError, 4096)
        $line = Read-Bounded $process.StandardOutput
        if (-not $line) { throw 'Missing prepared frame' }
        $frame = $line | ConvertFrom-Json
        $names = @($frame.PSObject.Properties.Name | Sort-Object)
        if (($names -join ',') -ne 'ciphertext_hash,directory,file_id,parent_file_id,status,version,volume_id' -or $frame.version -ne 'f02-synthetic-process-v1' -or $frame.status -ne 'prepared' -or $frame.volume_id -cnotmatch '^[0-9a-f]{8}$' -or $frame.parent_file_id -cnotmatch '^[0-9a-f]{16}$' -or $frame.file_id -cnotmatch '^[0-9a-f]{16}$' -or $frame.ciphertext_hash -cnotmatch '^[0-9a-f]{64}$' -or $line -match 'fictional-test-value') { throw 'Unsafe prepared frame' }
        return @{ process=$process; frame=$frame; first=$line; stderr=$stderr }
    } catch { Stop-ProcessSafely $process; throw }
}
function Invoke-Case([byte[]]$bytes, [bool]$published, [int]$exitCode) {
    $run = Start-SyntheticChild
    try {
        if ($bytes.Length) { $run.process.StandardInput.BaseStream.Write($bytes,0,$bytes.Length); $run.process.StandardInput.BaseStream.Flush() }
        $run.process.StandardInput.Close()
        $out = [F02BoundedDrain]::Read($run.process.StandardOutput, 4096)
        $watch = [Diagnostics.Stopwatch]::StartNew()
        while (-not $run.process.WaitForExit(100)) {
            if ($out.IsFaulted -or $run.stderr.IsFaulted) { throw 'Child output limit exceeded' }
            if ($watch.ElapsedMilliseconds -gt 15000) { throw 'Child timeout' }
        }
        if (-not $out.Wait(15000) -or -not $run.stderr.Wait(15000)) { throw 'Child drain timeout' }
        if (($run.first + $out.Result + $run.stderr.Result) -match 'fictional-test-value') { throw 'Sentinel leaked into output' }
        if ($run.process.ExitCode -ne $exitCode -or [IO.File]::Exists((Join-Path $run.frame.directory 'published.bin')) -ne $published) { throw 'Unexpected child result' }
        if ($published -and $out.Result.Trim() -ne '{"status":"published"}') { throw 'Invalid publication response' }
    } finally { Stop-ProcessSafely $run.process }
}
$ascii = [Text.Encoding]::ASCII
Invoke-Case ($ascii.GetBytes('publish')) $false 0
Invoke-Case ($ascii.GetBytes("publish`n")) $true 0
Invoke-Case ($ascii.GetBytes("abort`n")) $false 0
Invoke-Case ($ascii.GetBytes("unknown`n")) $false 1
Invoke-Case ($ascii.GetBytes(('x' * 200) + "`n")) $false 1
Invoke-Case ([byte[]](0xff,0x0a)) $false 1
Invoke-Case ($ascii.GetBytes("publish`npublish`n")) $false 1
$noiseStart = New-Object Diagnostics.ProcessStartInfo
$noiseStart.FileName = 'C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe'
$noiseStart.Arguments = '-NoProfile -NonInteractive -Command "while ($true) { [Console]::Error.Write(''0123456789'') }"'
$noiseStart.UseShellExecute = $false; $noiseStart.CreateNoWindow = $true; $noiseStart.RedirectStandardError = $true
$noise = [Diagnostics.Process]::Start($noiseStart)
try {
    $drain = [F02BoundedDrain]::Read($noise.StandardError, 4096)
    $watch = [Diagnostics.Stopwatch]::StartNew()
    while (-not $drain.IsFaulted -and $watch.ElapsedMilliseconds -lt 15000 -and -not $noise.HasExited) { [void]$noise.WaitForExit(100) }
    if (-not $drain.IsFaulted) { throw 'Oversized process output was not refused' }
} finally { Stop-ProcessSafely $noise }
$parentHelper = Join-Path $PSScriptRoot 'f02-process-parent-test-helper.ps1'
$start = New-Object Diagnostics.ProcessStartInfo
$start.FileName = 'C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe'
$start.Arguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $parentHelper + '"'
$start.UseShellExecute = $false; $start.CreateNoWindow = $true; $start.RedirectStandardOutput = $true; $start.RedirectStandardInput = $true; $start.RedirectStandardError = $true
$testParent = [Diagnostics.Process]::Start($start)
try {
    $stderr = [F02BoundedDrain]::Read($testParent.StandardError, 4096)
    $info = (Read-Bounded $testParent.StandardOutput) | ConvertFrom-Json
    $testParent.Kill()
    if (-not $testParent.WaitForExit(15000)) { throw 'Parent termination failed' }
    try { $orphan = [Diagnostics.Process]::GetProcessById([int]$info.pid); try { if (-not $orphan.WaitForExit(15000)) { throw 'Child survived parent exit' } } finally { Stop-ProcessSafely $orphan } } catch [ArgumentException] { }
    if ([IO.File]::Exists((Join-Path $info.directory 'published.bin'))) { throw 'Parent exit published pending data' }
} finally { Stop-ProcessSafely $testParent }
Write-Output 'synthetic process protocol and parent-termination cases: PASS'
