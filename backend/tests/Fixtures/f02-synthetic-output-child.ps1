# Synthetic process experiment only. It does not accept a real credential or authorize a DB receipt.
$ErrorActionPreference = 'Stop'
$parent = $null; $file = $null; $stream = $null
try {
    . (Join-Path $PSScriptRoot 'f02-handle-prototype.ps1')
    . (Join-Path $PSScriptRoot 'f02-output-protocol.ps1')
    Add-Type -AssemblyName System.Security
    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
    $acl = New-Object Security.AccessControl.DirectorySecurity
    $acl.SetOwner($sid)
    $acl.SetAccessRuleProtection($true, $false)
    $acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($sid, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow'))
    $dir = Join-Path ([IO.Path]::GetTempPath()) ('f02-process-' + [guid]::NewGuid().ToString('N'))
    [IO.Directory]::CreateDirectory($dir, $acl) | Out-Null
    $parent = [F02RelativePrototype]::WalkDirectory($dir)
    $file = [F02RelativePrototype]::CreateRelative($parent, 'pending.bin')
    $stream = New-Object IO.FileStream($file, [IO.FileAccess]::ReadWrite)
    try {
        $plain = [Text.Encoding]::UTF8.GetBytes('fictional-test-value')
        $cipher = [Security.Cryptography.ProtectedData]::Protect($plain, $null, [Security.Cryptography.DataProtectionScope]::CurrentUser)
        $stream.Write($cipher, 0, $cipher.Length)
        $stream.Flush($true)
        $parentId = [F02RelativePrototype]::Identity($parent)
        $fileId = [F02RelativePrototype]::Identity($file)
        $sha = [Security.Cryptography.SHA256]::Create()
        try { $hash = [BitConverter]::ToString($sha.ComputeHash($cipher)).Replace('-', '').ToLowerInvariant() } finally { $sha.Dispose() }
        $protocol = [F02OutputProtocol]::new()
        $protocol.Prepare($parentId.Substring(0,8), $parentId.Substring(9), $fileId.Substring(9), $hash)
        $frame = @{ version='f02-synthetic-process-v1'; status='prepared'; directory=$dir; volume_id=$parentId.Substring(0,8); parent_file_id=$parentId.Substring(9); file_id=$fileId.Substring(9); ciphertext_hash=$hash } | ConvertTo-Json -Compress
        [Console]::Out.WriteLine($frame)
        [Console]::Out.Flush()
        try { $protocol.ReadCommand([Console]::OpenStandardInput()) } catch { if ($_.Exception.InnerException -is [IO.EndOfStreamException]) { exit 0 }; throw }
        if ($protocol.State -eq 'publish_requested') {
            [F02RelativePrototype]::PublishRecovery($file, $parent, 'published.bin', $parentId, $fileId, $hash)
            [Console]::Out.WriteLine('{"status":"published"}')
            [Console]::Out.Flush()
        }
    } finally { }
} catch { [Console]::Error.WriteLine('Synthetic output refused.'); exit 1 }
finally { if ($stream) { $stream.Dispose() } elseif ($file) { $file.Dispose() }; if ($parent) { $parent.Dispose() } }
