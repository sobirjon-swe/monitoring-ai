$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'f02-bounded-drain.ps1')
$child = Join-Path $PSScriptRoot 'f02-synthetic-output-child.ps1'
$start = New-Object Diagnostics.ProcessStartInfo
$start.FileName = 'C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe'
$start.Arguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $child + '"'
$start.UseShellExecute = $false
$start.CreateNoWindow = $true
$start.RedirectStandardInput = $true
$start.RedirectStandardOutput = $true
$start.RedirectStandardError = $true
$childProcess = [Diagnostics.Process]::Start($start)
try {
    $stderr = [F02BoundedDrain]::Read($childProcess.StandardError, 4096)
    $watch = [Diagnostics.Stopwatch]::StartNew(); $chars = New-Object char[] 1; $line = New-Object Text.StringBuilder
    while ($line.Length -le 4096) {
        $remaining = 15000 - [int]$watch.ElapsedMilliseconds
        if ($remaining -le 0) { throw 'Child frame timeout' }
        $read = $childProcess.StandardOutput.ReadAsync($chars,0,1)
        if (-not $read.Wait($remaining) -or $read.Result -eq 0) { throw 'Child frame timeout or EOF' }
        if ($chars[0] -eq "`n") { break }
        [void]$line.Append($chars[0])
    }
    if ($line.Length -gt 4096) { throw 'Child frame too large' }
    $frame = $line.ToString() | ConvertFrom-Json
    @{ pid=$childProcess.Id; directory=$frame.directory } | ConvertTo-Json -Compress
    [Console]::ReadLine() | Out-Null
} finally {
    try { if (-not $childProcess.HasExited) { $childProcess.Kill() }; if (-not $childProcess.WaitForExit(15000)) { throw 'Child was not reaped' } } finally { $childProcess.Dispose() }
}
