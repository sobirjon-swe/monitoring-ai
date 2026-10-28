$ErrorActionPreference = 'Stop'
$root = Split-Path (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent) -Parent
$php = Join-Path $root '.local/tools/php85/php.exe'
$child = Join-Path $PSScriptRoot 'f02-probe-frame-synthetic-child.php'
function Invoke-Case([byte[]]$frame, [int]$expected) {
    $s = [Diagnostics.ProcessStartInfo]::new(); $s.FileName = $php
    $s.Arguments = '-d display_errors=0 -d log_errors=0 "' + $child + '"'
    $s.UseShellExecute = $false; $s.CreateNoWindow = $true
    $s.RedirectStandardInput = $true; $s.RedirectStandardOutput = $true; $s.RedirectStandardError = $true
    $p = [Diagnostics.Process]::Start($s)
    try {
        $out = $p.StandardOutput.ReadToEndAsync(); $err = $p.StandardError.ReadToEndAsync()
        foreach ($b in $frame) { $one = [byte[]]@($b); $w = $p.StandardInput.BaseStream.WriteAsync($one,0,1); if (!$w.Wait(5000)) { throw 'Write timeout' } }
        $p.StandardInput.Close()
        if (!$p.WaitForExit(5000) -or !$out.Wait(5000) -or !$err.Wait(5000)) { throw 'Timeout' }
        $wantOut = if ($expected -eq 0) { "accepted`n" } else { '' }; $wantErr = if ($expected -eq 0) { '' } else { "refused`n" }
        if ($p.ExitCode -ne $expected -or $out.Result -cne $wantOut -or $err.Result -cne $wantErr) { throw 'Unexpected result' }
    } finally { if (!$p.HasExited) { $p.Kill(); if (!$p.WaitForExit(5000)) { throw 'Child not reaped' } }; $p.Dispose() }
}
$a=[Text.Encoding]::ASCII.GetBytes('{"fake":true}'); $b=[Text.Encoding]::ASCII.GetBytes('fictional-value')
$frame=[byte[]]([BitConverter]::GetBytes([int]$a.Length)+$a+[BitConverter]::GetBytes([int]$b.Length)+$b)
Invoke-Case $frame 0
Invoke-Case ([byte[]]($frame + [byte]1)) 1
Invoke-Case ([byte[]]([BitConverter]::GetBytes([int]65537))) 1
Invoke-Case ([byte[]]($frame[0..($frame.Length-2)])) 1
Write-Output 'Probe frame synthetic checks passed.'
