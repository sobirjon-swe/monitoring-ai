$ErrorActionPreference = 'Stop'
$script = Join-Path $PSScriptRoot '../../app/Domain/Browser/BaDemo/SyntheticSecretInput.ps1'
$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../../.local/f02-synthetic-token-output'))
$start = [Diagnostics.ProcessStartInfo]::new()
$start.FileName = 'C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe'
$start.Arguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $script + '"'
$start.UseShellExecute = $false; $start.CreateNoWindow = $true
$start.RedirectStandardInput = $true; $start.RedirectStandardOutput = $true; $start.RedirectStandardError = $true
$secret = New-Object byte[] 32
$rng = [Security.Cryptography.RandomNumberGenerator]::Create()
$child = $null
try {
    $rng.GetBytes($secret)
    $child = [Diagnostics.Process]::Start($start)
    $length = [BitConverter]::GetBytes([int]$secret.Length)
    $child.StandardInput.BaseStream.Write($length, 0, 4)
    $child.StandardInput.BaseStream.Write($secret, 0, $secret.Length)
    $child.StandardInput.BaseStream.Flush()
    $line = $child.StandardOutput.ReadLineAsync()
    if (-not $line.Wait(15000) -or -not $line.Result) { throw 'Prepared metadata unavailable' }
    $metadata = $line.Result | ConvertFrom-Json
    if ($metadata.status -ne 'prepared' -or $metadata.name -cnotmatch '^pending-[0-9a-f]{32}\.bin$') { throw 'Metadata refused' }
    $path = Join-Path $root $metadata.name
    $opened = $null
    try { $opened = [IO.File]::Open($path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::ReadWrite) }
    catch [IO.IOException] { }
    if ($opened) { $opened.Dispose(); throw 'Pending file was accessible while helper held it' }
    $child.Kill()
    if (-not $child.WaitForExit(15000)) { throw 'Killed helper was not reaped' }
    $opened = [IO.File]::Open($path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read)
    try {
        $sha = [Security.Cryptography.SHA256]::Create()
        try { $actual = [BitConverter]::ToString($sha.ComputeHash($opened)).Replace('-', '').ToLowerInvariant() } finally { $sha.Dispose() }
        if ($actual -cne $metadata.ciphertext_hash) { throw 'Retained ciphertext hash mismatch' }
    } finally { $opened.Dispose() }
    Write-Output 'synthetic helper retains exclusive file handle until process exit: PASS'
} finally {
    if ($child) { if (-not $child.HasExited) { $child.Kill(); [void]$child.WaitForExit(15000) }; $child.Dispose() }
    $rng.Dispose(); [Array]::Clear($secret, 0, $secret.Length)
}
