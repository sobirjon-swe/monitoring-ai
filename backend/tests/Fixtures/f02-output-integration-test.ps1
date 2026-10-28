$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'f02-handle-prototype.ps1')
. (Join-Path $PSScriptRoot 'f02-output-protocol.ps1')
Add-Type -AssemblyName System.Security
$sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
$acl = New-Object Security.AccessControl.DirectorySecurity
$acl.SetOwner($sid)
$acl.SetAccessRuleProtection($true, $false)
$acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($sid, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow'))
$dir = Join-Path ([IO.Path]::GetTempPath()) ('f02-orchestration-' + [guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($dir, $acl) | Out-Null
$parent = [F02RelativePrototype]::WalkDirectory($dir)
$file = [F02RelativePrototype]::CreateRelative($parent, 'pending.bin')
$stream = New-Object IO.FileStream($file, [IO.FileAccess]::ReadWrite)
try {
    $fictional = [Text.Encoding]::UTF8.GetBytes('fictional-test-value')
    $cipher = [Security.Cryptography.ProtectedData]::Protect($fictional, $null, [Security.Cryptography.DataProtectionScope]::CurrentUser)
    $stream.Write($cipher, 0, $cipher.Length)
    $stream.Flush($true)
    $parentId = [F02RelativePrototype]::Identity($parent)
    $fileId = [F02RelativePrototype]::Identity($file)
    $sha = [Security.Cryptography.SHA256]::Create()
    try { $hash = [BitConverter]::ToString($sha.ComputeHash($cipher)).Replace('-', '').ToLowerInvariant() } finally { $sha.Dispose() }
    $p = [F02OutputProtocol]::new()
    $p.Prepare($parentId.Substring(0, 8), $parentId.Substring(9), $fileId.Substring(9), $hash)
    $incomplete = [IO.MemoryStream]::new([Text.Encoding]::ASCII.GetBytes('publish'))
    try { $p.ReadCommand($incomplete); throw 'Incomplete frame accepted' } catch [IO.EndOfStreamException] { }
    if ($p.State -ne 'prepared' -or [IO.File]::Exists((Join-Path $dir 'published.bin'))) { throw 'Unknown outcome published' }
    $valid = [IO.MemoryStream]::new([Text.Encoding]::ASCII.GetBytes("publish`n"))
    $p.ReadCommand($valid)
    if ($p.State -ne 'publish_requested') { throw 'Frame was not accepted' }
    [F02RelativePrototype]::PublishRecovery($file, $parent, 'published.bin', $parentId, $fileId, $hash)
    if (-not [IO.File]::Exists((Join-Path $dir 'published.bin'))) { throw 'Synthetic publication failed' }
} finally { $stream.Dispose(); $parent.Dispose() }
Write-Output 'synthetic orchestration only: incomplete frame refused; held handle published after simulated request'
