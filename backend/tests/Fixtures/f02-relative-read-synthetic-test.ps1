$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'f02-relative-read-synthetic.ps1')
. (Join-Path $PSScriptRoot '../../app/Domain/Browser/BaDemo/NativeHandlePrimitive.ps1')
. (Join-Path $PSScriptRoot '../../../scripts/f02-probe-handle-review.ps1')
$root = Join-Path (Split-Path (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent) -Parent) '.local'
$dir = Join-Path $root ('f02-relative-read-' + [guid]::NewGuid().ToString('N'))
$sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
$acl = [Security.AccessControl.DirectorySecurity]::new()
$acl.SetOwner($sid); $acl.SetAccessRuleProtection($true, $false)
$acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($sid, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow'))
# Synthetic values only: ACL assignment after creation is not safe for real secrets.
[IO.Directory]::CreateDirectory($dir) | Out-Null
[IO.FileSystemAclExtensions]::SetAccessControl((Get-Item -LiteralPath $dir), $acl)
$file = Join-Path $dir 'fake.txt'
try {
    [IO.File]::WriteAllText($file, 'synthetic-value', [Text.Encoding]::ASCII)
    $fileAcl = [Security.AccessControl.FileSecurity]::new()
    $fileAcl.SetOwner($sid); $fileAcl.SetAccessRuleProtection($true, $false)
    $fileAcl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($sid, 'FullControl', 'Allow'))
    [IO.FileSystemAclExtensions]::SetAccessControl((Get-Item -LiteralPath $file), $fileAcl)
    $link = Join-Path $dir 'fake-link.txt'
    $hardLinkCreated = $false
    try { New-Item -ItemType HardLink -Path $link -Target $file | Out-Null; $hardLinkCreated = $true } catch { Write-Output 'Hard-link creation unavailable; link test not run.' }
    $dh = [F02SecureOutputNative]::WalkDirectory($dir)
    [F02SyntheticRead]::ValidateDirectory($dh)
    try {
        if ($hardLinkCreated) {
            $refused = $false; try { $bad = [F02SyntheticRead]::OpenFile($dh, 'fake.txt'); $bad.Dispose() } catch { $refused = $true }
            if (!$refused) { throw 'Multiple hard links accepted' }
            Remove-Item -LiteralPath $link -Force
        }
        $probeHandle = [F02ProbeRead]::OpenFile($dh, 'fake.txt', [string[]]@($sid.Value), [string[]]@($sid.Value))
        $probeHandle.Dispose()
        $refused = $false; try { $bad = [F02ProbeRead]::OpenFile($dh, 'fake.txt', [string[]]@('S-1-5-18'), [string[]]@($sid.Value)); $bad.Dispose() } catch { $refused = $true }
        if (!$refused) { throw 'Impostor owner accepted' }
        $fh = [F02SyntheticRead]::OpenFile($dh, 'fake.txt')
        try {
            $stream = [IO.FileStream]::new($fh, [IO.FileAccess]::Read)
            try { $bytes = New-Object byte[] 15; if ($stream.Read($bytes, 0, $bytes.Length) -ne 15 -or [Text.Encoding]::ASCII.GetString($bytes) -cne 'synthetic-value') { throw 'Incorrect synthetic bytes' } }
            finally { $stream.Dispose() }
        } finally { $fh.Dispose() }
        $fh = [F02SyntheticRead]::OpenFile($dh, 'fake.txt')
        try {
            $blocked = $false; try { [IO.File]::Move($file, ($file + '.moved')) } catch { $blocked = $true }
            if (!$blocked) { throw 'Open file allowed replacement' }
        } finally { $fh.Dispose() }
        # Windows may permit directory rename; the retained handle must still address the original object.
        $moved = $dir + '.moved'
        [IO.Directory]::Move($dir, $moved)
        Write-Output 'Directory rename succeeded while held; checking relative identity.'
        $fh = [F02SyntheticRead]::OpenFile($dh, 'fake.txt'); $fh.Dispose()
        [IO.Directory]::Move($moved, $dir)
        $broad = [Security.AccessControl.FileSystemAccessRule]::new([Security.Principal.SecurityIdentifier]::new('S-1-1-0'), 'Read', 'Allow')
        $fileAcl.AddAccessRule($broad); [IO.FileSystemAclExtensions]::SetAccessControl((Get-Item -LiteralPath $file), $fileAcl)
        $refused = $false; try { $bad = [F02SyntheticRead]::OpenFile($dh, 'fake.txt'); $bad.Dispose() } catch { $refused = $true }
        if (!$refused) { throw 'Unexpected ACL accepted' }
        $refused = $false; try { $bad = [F02ProbeRead]::OpenFile($dh, 'fake.txt', [string[]]@($sid.Value), [string[]]@($sid.Value)); $bad.Dispose() } catch { $refused = $true }
        if (!$refused) { throw 'Probe accepted unexpected ACL' }
        [void]$fileAcl.RemoveAccessRule($broad)
        [IO.FileSystemAclExtensions]::SetAccessControl((Get-Item -LiteralPath $file), $fileAcl)
        $control = [F02SyntheticRead]::OpenFile($dh, 'fake.txt'); $control.Dispose()
        $target = Join-Path $dir 'fake-target.txt'
        [IO.File]::Move($file, $target)
        $symlinkCreated = $false
        try { New-Item -ItemType SymbolicLink -Path $file -Target $target | Out-Null; $symlinkCreated = $true } catch { Write-Output 'Symlink creation unavailable; final reparse test not run.' }
        if ($symlinkCreated) {
            $refused = $false; try { $bad = [F02SyntheticRead]::OpenFile($dh, 'fake.txt'); $bad.Dispose() } catch { if ($_.Exception.ToString() -match 'reparse point refused') { $refused = $true } else { throw } }
            if (!$refused) { throw 'Final reparse point accepted' }
        }
    } finally { $dh.Dispose() }
    $junction = $dir + '.junction'
    $junctionCreated = $false
    try { New-Item -ItemType Junction -Path $junction -Target $dir | Out-Null; $junctionCreated = $true } catch { Write-Output 'Junction creation unavailable; ancestor reparse test not run.' }
    if ($junctionCreated) {
        $refused = $false; try { $bad = [F02SecureOutputNative]::WalkDirectory($junction); $bad.Dispose() } catch { $refused = $true }
        if (!$refused) { throw 'Ancestor reparse point accepted' }
    }
    Write-Output 'Synthetic handle checks passed.'
} finally { Write-Output 'Synthetic directory retained for safe manual cleanup.' }
