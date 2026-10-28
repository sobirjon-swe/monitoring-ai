$ErrorActionPreference = 'Stop'
$root = Split-Path (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent) -Parent
$php = Join-Path $root '.local/tools/php85/php.exe'
$child = Join-Path $PSScriptRoot 'f02-synthetic-stdin-child.php'
function Invoke-Synthetic([byte[]]$frame, [int]$expected, [bool]$fragment = $false) {
    $start = [Diagnostics.ProcessStartInfo]::new()
    $start.FileName = $php; $start.Arguments = '-d display_errors=0 -d log_errors=0 "' + $child + '"'
    $start.UseShellExecute = $false; $start.CreateNoWindow = $true
    $start.RedirectStandardInput = $true; $start.RedirectStandardOutput = $true; $start.RedirectStandardError = $true
    foreach ($key in @($start.EnvironmentVariables.Keys)) { if ($key -like 'PG*') { $start.EnvironmentVariables.Remove($key) } }
    $p = [Diagnostics.Process]::Start($start)
    try {
        $stdout = $p.StandardOutput.ReadToEndAsync(); $stderr = $p.StandardError.ReadToEndAsync()
        if ($fragment) {
            for ($i = 0; $i -lt $frame.Length; $i++) {
                $write = $p.StandardInput.BaseStream.WriteAsync($frame, $i, 1)
                if (!$write.Wait(5000)) { throw 'Synthetic input timed out' }
                $p.StandardInput.BaseStream.Flush()
                Start-Sleep -Milliseconds 10
            }
        } else {
            $write = $p.StandardInput.BaseStream.WriteAsync($frame, 0, $frame.Length)
            if (!$write.Wait(5000)) { throw 'Synthetic input timed out' }
        }
        $p.StandardInput.Close()
        if (!$p.WaitForExit(5000) -or !$stdout.Wait(5000) -or !$stderr.Wait(5000)) { throw 'Synthetic child timed out' }
        $expectedOut = if ($expected -eq 0) { "accepted`n" } else { '' }
        $expectedErr = if ($expected -eq 0) { '' } else { "refused`n" }
        if ($p.ExitCode -ne $expected -or $stdout.Result -cne $expectedOut -or $stderr.Result -cne $expectedErr) { throw 'Synthetic transport failed' }
    } finally {
        if (!$p.HasExited) { $p.Kill(); if (!$p.WaitForExit(5000)) { throw 'Synthetic child not reaped' } }
        $p.Dispose()
    }
}
$payload = [Text.Encoding]::ASCII.GetBytes('synthetic-value')
Invoke-Synthetic ([byte[]]([BitConverter]::GetBytes([int]$payload.Length) + $payload)) 0 $true
Invoke-Synthetic ([byte[]]([BitConverter]::GetBytes([int]65))) 1
Invoke-Synthetic ([byte[]]([BitConverter]::GetBytes([int]20) + $payload)) 1
Invoke-Synthetic ([byte[]]([BitConverter]::GetBytes([int]$payload.Length) + $payload + [byte]1)) 1
Invoke-Synthetic ([byte[]]([BitConverter]::GetBytes([int]3) + [Text.Encoding]::ASCII.GetBytes('bad'))) 1
Write-Output 'Synthetic bounded input checks passed.'
