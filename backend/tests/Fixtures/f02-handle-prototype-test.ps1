$ErrorActionPreference = 'Stop'
$helper = Join-Path $PSScriptRoot 'f02-handle-prototype.ps1'
if (-not (Test-Path -LiteralPath $helper)) { throw 'Prototype helper missing' }
. $helper
$dir = Join-Path ([IO.Path]::GetTempPath()) ('f02-relative-' + [guid]::NewGuid().ToString('N'))
$sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
$parentAcl = New-Object Security.AccessControl.DirectorySecurity
$parentAcl.SetOwner($sid)
$parentAcl.SetAccessRuleProtection($true, $false)
$parentAcl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($sid, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow'))
[IO.Directory]::CreateDirectory($dir, $parentAcl) | Out-Null
$directory = [F02RelativePrototype]::OpenDirectory($dir)
$walked = [F02RelativePrototype]::WalkDirectory($dir)
try { if ([F02RelativePrototype]::Identity($walked) -ne [F02RelativePrototype]::Identity($directory)) { throw 'Walk identity mismatch' } } finally { $walked.Dispose() }
$restricted = Join-Path $dir 'restricted'
$acl = New-Object Security.AccessControl.DirectorySecurity
$acl.SetOwner($sid)
$acl.SetAccessRuleProtection($true, $false)
$acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($sid, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow'))
[IO.Directory]::CreateDirectory($restricted, $acl) | Out-Null
$restrictedHandle = [F02RelativePrototype]::WalkDirectory($restricted)
try {
    [F02RelativePrototype]::AssertOwnerOnly($restrictedHandle)
} finally { $restrictedHandle.Dispose() }
$shared = Join-Path ([IO.Path]::GetTempPath()) ('f02-shared-' + [guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($shared) | Out-Null
$sharedHandle = [F02RelativePrototype]::WalkDirectory($shared)
$sharedRefused = $false
try { $bad = [F02RelativePrototype]::CreateRelative($sharedHandle, 'refused.bin'); $bad.Dispose() } catch { if ($_ -notmatch 'owner or DACL refused') { throw }; $sharedRefused = $true } finally { $sharedHandle.Dispose() }
if (-not $sharedRefused) { throw 'Unprotected inherited ACL accepted' }
$junction = $dir + '-junction'
New-Item -ItemType Junction -Path $junction -Target $dir | Out-Null
if (-not ([F02RelativePrototype].GetMethod('WalkDirectory'))) { throw 'Ancestor walk implementation missing' }
$walkRefused = $false
try { $bad = [F02RelativePrototype]::WalkDirectory($junction); $bad.Dispose() } catch { if ($_ -notmatch 'reparse or wrong type') { throw }; $walkRefused = $true }
if (-not $walkRefused) { throw 'Ancestor walk accepted reparse point' }
[IO.Directory]::CreateDirectory((Join-Path $dir 'child')) | Out-Null
$walkRefused = $false
try { $bad = [F02RelativePrototype]::WalkDirectory((Join-Path $junction 'child')); $bad.Dispose() } catch { if ($_ -notmatch 'reparse or wrong type') { throw }; $walkRefused = $true }
if (-not $walkRefused) { throw 'Ancestor walk accepted intermediate reparse point' }
$reparseRefused = $false
try { $bad = [F02RelativePrototype]::OpenDirectory($junction); $bad.Dispose() } catch { if ($_ -notmatch 'Directory reparse point or wrong type') { throw }; $reparseRefused = $true }
if (-not $reparseRefused) { throw 'Directory reparse point accepted' }
$pending = Join-Path $dir 'pending.bin'
$target = Join-Path $dir 'published.bin'
$first = [F02RelativePrototype]::CreateRelative($directory, 'pending.bin')
[F02RelativePrototype]::AssertOwnerOnly($first)
Add-Type -AssemblyName System.Security
$plain = [Text.Encoding]::UTF8.GetBytes('fictional-test-data')
$cipher = [Security.Cryptography.ProtectedData]::Protect($plain, $null, [Security.Cryptography.DataProtectionScope]::CurrentUser)
$stream = New-Object IO.FileStream($first, [IO.FileAccess]::ReadWrite)
$stream.Write($cipher, 0, $cipher.Length)
$stream.Flush($true)
$stream.Position = 0
$read = New-Object byte[] $cipher.Length
if ($stream.Read($read, 0, $read.Length) -ne $read.Length) { throw 'Ciphertext read incomplete' }
$decoded = [Security.Cryptography.ProtectedData]::Unprotect($read, $null, [Security.Cryptography.DataProtectionScope]::CurrentUser)
if ([Convert]::ToBase64String($plain) -ne [Convert]::ToBase64String($decoded)) { throw 'DPAPI roundtrip failed' }
foreach ($invalid in @('CON', 'bad.', 'bad ', 'bad:name', "bad`0name", 'bad/name')) {
    $rejected = $false
    try { $bad = [F02RelativePrototype]::CreateRelative($directory, $invalid); $bad.Dispose() } catch { if ($_.Exception.InnerException -isnot [ArgumentException]) { throw }; $rejected = $true }
    if (-not $rejected) { throw 'Unsafe filename accepted' }
}
try {
    $before = [F02RelativePrototype]::Identity($first)
    [F02RelativePrototype]::RenameRelative($first, $directory, 'published.bin')
    if (-not [IO.File]::Exists($target) -or [IO.File]::Exists($pending)) { throw 'Relative publication failed' }
    if ($before -ne [F02RelativePrototype]::Identity($first)) { throw 'Identity changed on rename' }
} finally { $stream.Dispose() }
$sha = [Security.Cryptography.SHA256]::Create()
try { $cipherHash = [BitConverter]::ToString($sha.ComputeHash($cipher)).Replace('-', '') } finally { $sha.Dispose() }
$recoveryHandle = [F02RelativePrototype]::ValidateRecovery($directory, 'published.bin', [F02RelativePrototype]::Identity($directory), $before, $cipherHash)
try {
    if ([F02RelativePrototype]::Identity($recoveryHandle) -ne $before) { throw 'Recovery handle identity mismatch' }
    $borrowed = [Microsoft.Win32.SafeHandles.SafeFileHandle]::new($recoveryHandle.DangerousGetHandle(), $false)
    $writeDenied = $false
    try { $writeStream = [IO.FileStream]::new($borrowed, [IO.FileAccess]::Write); try { $writeStream.WriteByte(1); $writeStream.Flush() } finally { $writeStream.Dispose() } } catch { if ($_.Exception.InnerException -isnot [UnauthorizedAccessException] -and $_.Exception.InnerException -isnot [IO.IOException]) { throw }; $writeDenied = $true } finally { $borrowed.Dispose() }
    if (-not $writeDenied) { throw 'Recovery handle permitted writing' }
    $collisionPath = Join-Path $dir 'recovery-collision.bin'
    [IO.File]::WriteAllText($collisionPath, 'collision-target')
    $collisionHandle = [F02RelativePrototype]::OpenRead($collisionPath)
    try { $collisionId = [F02RelativePrototype]::Identity($collisionHandle) } finally { $collisionHandle.Dispose() }
    $collisionDenied = $false
    try { [F02RelativePrototype]::PublishRecovery($recoveryHandle, $directory, 'recovery-collision.bin', [F02RelativePrototype]::Identity($directory), $before, $cipherHash) } catch { if ($_ -notmatch 'c0000035') { throw }; $collisionDenied = $true }
    if (-not $collisionDenied -or [IO.File]::ReadAllText($collisionPath) -ne 'collision-target') { throw 'Recovery collision changed target' }
    $collisionHandle = [F02RelativePrototype]::OpenRead($collisionPath)
    try { if ([F02RelativePrototype]::Identity($collisionHandle) -ne $collisionId) { throw 'Collision target identity changed' } } finally { $collisionHandle.Dispose() }
    [F02RelativePrototype]::PublishRecovery($recoveryHandle, $directory, 'recovered.bin', [F02RelativePrototype]::Identity($directory), $before, $cipherHash)
    if ([F02RelativePrototype]::Identity($recoveryHandle) -ne $before) { throw 'Recovery rename changed identity' }
    [F02RelativePrototype]::RenameRelative($recoveryHandle, $directory, 'published.bin')
} finally { $recoveryHandle.Dispose() }
foreach ($case in @(@('bad-parent', $before, $cipherHash, 'parent identity refused'), @([F02RelativePrototype]::Identity($directory), 'bad-file', $cipherHash, 'file identity refused'), @([F02RelativePrototype]::Identity($directory), $before, 'bad-hash', 'hash refused'))) {
    $refused = $false
    try { [F02RelativePrototype]::ValidateRecovery($directory, 'published.bin', $case[0], $case[1], $case[2]) } catch { if ($_ -notmatch $case[3]) { throw }; $refused = $true }
    if (-not $refused) { throw 'Recovery mismatch accepted' }
}
$opened = [F02RelativePrototype]::OpenRead($target)
try { if ($before -ne [F02RelativePrototype]::Identity($opened)) { throw 'Published file is not the renamed handle' } } finally { $opened.Dispose() }
$victim = Join-Path $dir 'victim.bin'
[IO.File]::WriteAllText($victim, 'synthetic-victim')
New-Item -ItemType HardLink -Path (Join-Path $dir 'occupied.bin') -Target $victim | Out-Null
$hardlinkRefused = $false
try { $bad = [F02RelativePrototype]::CreateRelative($directory, 'occupied.bin'); $bad.Dispose() } catch { if ($_ -notmatch 'c0000035') { throw }; $hardlinkRefused = $true }
if (-not $hardlinkRefused -or [IO.File]::ReadAllText($victim) -ne 'synthetic-victim') { throw 'Existing hardlink was changed or accepted' }
$linked = [F02RelativePrototype]::CreateRelative($directory, 'linked.bin')
try {
    [F02RelativePrototype]::AssertSingleLink($linked)
    New-Item -ItemType HardLink -Path (Join-Path $dir 'linked-alias.bin') -Target (Join-Path $dir 'linked.bin') | Out-Null
    $multipleRefused = $false
    try { [F02RelativePrototype]::AssertSingleLink($linked) } catch { $multipleRefused = $true }
    if (-not $multipleRefused) { throw 'Additional hardlink was accepted' }
} finally { $linked.Dispose() }
$second = [F02RelativePrototype]::CreateRelative($directory, 'second.bin')
try {
    $refused = $false
    try { [F02RelativePrototype]::RenameRelative($second, $directory, 'published.bin') } catch { if ($_ -notmatch 'c0000035') { throw }; $refused = $true }
    if (-not $refused) { throw 'Existing target replaced' }
    if (-not [IO.File]::Exists((Join-Path $dir 'second.bin'))) { throw 'Collision lost source' }
} finally { $second.Dispose() }
$checked = [F02RelativePrototype]::ValidateRecovery($directory, 'published.bin', [F02RelativePrototype]::Identity($directory), $before, $cipherHash)
$checked.Dispose()
$moved = $dir + '-moved'
try {
    [IO.Directory]::Move($dir, $moved)
    [IO.Directory]::CreateDirectory($dir) | Out-Null
    $third = [F02RelativePrototype]::CreateRelative($directory, 'third.bin')
    if (-not [IO.File]::Exists((Join-Path $moved 'third.bin')) -or [IO.File]::Exists((Join-Path $dir 'third.bin'))) { throw 'Relative creation was not anchored' }
    try {
        [F02RelativePrototype]::RenameRelative($third, $directory, 'anchored.bin')
        if (-not [IO.File]::Exists((Join-Path $moved 'anchored.bin')) -or [IO.File]::Exists((Join-Path $dir 'anchored.bin'))) { throw 'Directory handle was not pinned' }
    } finally { $third.Dispose() }
} finally { $directory.Dispose() }
Write-Output 'relative rename, reopened identity, collision refusal, moved directory anchoring: PASS'
