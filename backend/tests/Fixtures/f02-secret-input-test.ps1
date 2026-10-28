$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'f02-bounded-drain.ps1')
function Read-Metadata($reader) {
    $watch=[Diagnostics.Stopwatch]::StartNew(); $char=New-Object char[] 1; $line=New-Object Text.StringBuilder
    while ($line.Length -le 4096) { $remaining=15000-[int]$watch.ElapsedMilliseconds; if ($remaining -le 0) { throw 'Metadata timeout' }; $read=$reader.ReadAsync($char,0,1); if (-not $read.Wait($remaining) -or $read.Result -eq 0) { throw 'Metadata timeout or EOF' }; if ($char[0] -eq "`n") { return $line.ToString().TrimEnd("`r") }; [void]$line.Append($char[0]) }
    throw 'Metadata exceeds limit'
}
$script = Join-Path $PSScriptRoot '../../app/Domain/Browser/BaDemo/SyntheticSecretInput.ps1'
if (-not (Test-Path -LiteralPath $script)) { throw 'Secret input primitive missing' }
$repo = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../..'))
$root = Join-Path $repo '.local/f02-synthetic-token-output'
$sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
$acl = New-Object Security.AccessControl.DirectorySecurity
$acl.SetOwner($sid); $acl.SetAccessRuleProtection($true, $false)
$acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($sid, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow'))
if (-not (Test-Path -LiteralPath $root)) { [IO.Directory]::CreateDirectory($root, $acl) | Out-Null }
$marker=Join-Path $root 'test-only.marker'
if (-not (Test-Path -LiteralPath $marker)) {
$missingStart = New-Object Diagnostics.ProcessStartInfo
$missingStart.FileName='C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe'
$missingStart.Arguments='-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $script + '"'
$missingStart.UseShellExecute=$false; $missingStart.CreateNoWindow=$true; $missingStart.RedirectStandardInput=$true; $missingStart.RedirectStandardOutput=$true; $missingStart.RedirectStandardError=$true
$missing=[Diagnostics.Process]::Start($missingStart)
try { if (-not $missing.WaitForExit(3000) -or $missing.ExitCode -ne 1) { throw 'Missing marker not refused before input' } } finally { if (-not $missing.HasExited) { $missing.Kill(); if (-not $missing.WaitForExit(15000)) { throw 'Child not reaped' } }; $missing.Dispose() }
. (Join-Path $PSScriptRoot '../../app/Domain/Browser/BaDemo/NativeHandlePrimitive.ps1')
$markerDirectory=[F02SecureOutputNative]::WalkDirectory($root)
try {
    $markerHandle=[F02SecureOutputNative]::CreateRelative($markerDirectory,'test-only.marker')
    $markerStream=New-Object IO.FileStream($markerHandle,[IO.FileAccess]::Write)
    try { $markerBytes=[Text.Encoding]::ASCII.GetBytes('f02-synthetic-test-only-v1'); $markerStream.Write($markerBytes,0,$markerBytes.Length); $markerStream.Flush($true) } finally { $markerStream.Dispose() }
} finally { $markerDirectory.Dispose() }
}
function Assert-InputRefused([byte[]]$bytes) {
    $s=New-Object Diagnostics.ProcessStartInfo
    $s.FileName='C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe'; $s.Arguments='-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $script + '"'
    $s.UseShellExecute=$false; $s.CreateNoWindow=$true; $s.RedirectStandardInput=$true; $s.RedirectStandardOutput=$true; $s.RedirectStandardError=$true
    $child=[Diagnostics.Process]::Start($s)
    try {
        $child.StandardInput.BaseStream.Write($bytes,0,$bytes.Length); $child.StandardInput.Close()
        if (-not $child.WaitForExit(15000) -or $child.ExitCode -ne 1 -or $child.StandardOutput.ReadToEnd().Length -ne 0) { throw 'Invalid input accepted' }
    } finally { if (-not $child.HasExited) { $child.Kill(); if (-not $child.WaitForExit(15000)) { throw 'Child not reaped' } }; $child.Dispose() }
}
Assert-InputRefused ([BitConverter]::GetBytes([int]33)); Assert-InputRefused ([byte[]](32,0,0,0,42))
$timedStart=New-Object Diagnostics.ProcessStartInfo
$timedStart.FileName='C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe'; $timedStart.Arguments='-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $script + '"'
$timedStart.UseShellExecute=$false; $timedStart.CreateNoWindow=$true; $timedStart.RedirectStandardInput=$true; $timedStart.RedirectStandardOutput=$true; $timedStart.RedirectStandardError=$true
$timed=[Diagnostics.Process]::Start($timedStart)
try { if (-not $timed.WaitForExit(22000) -or $timed.ExitCode -ne 1) { throw 'Input timeout did not stop child' } } finally { if (-not $timed.HasExited) { $timed.Kill(); if (-not $timed.WaitForExit(15000)) { throw 'Child not reaped' } }; $timed.Dispose() }
$fake = New-Object byte[] 32
[Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($fake)
$sentinel = [Convert]::ToBase64String($fake)
$start = New-Object Diagnostics.ProcessStartInfo
$start.FileName='C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe'
$start.Arguments='-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $script + '"'
$start.UseShellExecute=$false; $start.CreateNoWindow=$true; $start.RedirectStandardInput=$true; $start.RedirectStandardOutput=$true; $start.RedirectStandardError=$true
if ($start.Arguments.Contains($sentinel) -or @($start.EnvironmentVariables.Values | Where-Object { $_ -and $_.Contains($sentinel) }).Count) { throw 'Synthetic secret leaked into argv or environment' }
$p=[Diagnostics.Process]::Start($start)
try {
    $stderr=[F02BoundedDrain]::Read($p.StandardError,4096)
    $length=[BitConverter]::GetBytes([int]$fake.Length)
    $p.StandardInput.BaseStream.Write($length,0,4); $p.StandardInput.BaseStream.Write($fake,0,$fake.Length); $p.StandardInput.BaseStream.Flush()
    $line=Read-Metadata $p.StandardOutput; $metadata=$line | ConvertFrom-Json
    $p.StandardInput.Write("abort`n"); $p.StandardInput.Close()
    if (-not $p.WaitForExit(15000)) { throw 'Child timeout' }
    $restTask=[F02BoundedDrain]::Read($p.StandardOutput,4096); if (-not $restTask.Wait(15000) -or -not $stderr.Wait(15000)) { throw 'Output timeout' }; $rest=$restTask.Result
    if ($p.ExitCode -ne 0 -or ($line+$rest+$stderr.Result).Contains($sentinel)) { throw 'Secret leaked or child failed' }
    if ((@($metadata.PSObject.Properties.Name | Sort-Object) -join ',') -ne 'ciphertext_hash,file_id,name,parent_file_id,status,version,volume_id' -or $metadata.status -ne 'prepared' -or $metadata.name -cnotmatch '^pending-[0-9a-f]{32}\.bin$') { throw 'Metadata refused' }
    $encrypted=[IO.File]::ReadAllBytes((Join-Path $root $metadata.name))
    for ($i=0; $i -le $encrypted.Length-$fake.Length; $i++) {
        $match=$true
        for ($j=0; $j -lt $fake.Length; $j++) { if ($encrypted[$i+$j] -ne $fake[$j]) { $match=$false; break } }
        if ($match) { throw 'Plain secret present in file' }
    }
    $sha=[Security.Cryptography.SHA256]::Create()
    try { $encryptedHash=[BitConverter]::ToString($sha.ComputeHash($encrypted)).Replace('-','').ToLowerInvariant() } finally { $sha.Dispose() }
    if ($metadata.ciphertext_hash -ne $encryptedHash) { throw 'Metadata hash mismatch' }
    Add-Type -AssemblyName System.Security
    $decoded=[Security.Cryptography.ProtectedData]::Unprotect($encrypted,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser)
    if ([Convert]::ToBase64String($decoded) -ne $sentinel) { throw 'DPAPI value mismatch' }
} finally { if (-not $p.HasExited) { $p.Kill(); if (-not $p.WaitForExit(15000)) { throw 'Child not reaped' } }; $p.Dispose(); [Array]::Clear($fake,0,$fake.Length) }
Write-Output 'synthetic binary secret input and DPAPI ciphertext: PASS'
