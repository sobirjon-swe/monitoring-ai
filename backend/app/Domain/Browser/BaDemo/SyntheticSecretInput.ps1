# TEST-only primitive. No publication or database authorization is provided here.
$ErrorActionPreference='Stop'
$parent=$null; $file=$null; $stream=$null; $plain=$null; $cipher=$null
function Read-Exact($inputStream, [int]$size) {
    $buffer=New-Object byte[] $size; $offset=0
    $watch=[Diagnostics.Stopwatch]::StartNew()
    try { while ($offset -lt $size) { $remaining=15000-[int]$watch.ElapsedMilliseconds; if ($remaining -le 0) { throw 'Input timeout' }; $read=$inputStream.ReadAsync($buffer,$offset,$size-$offset); if (-not $read.Wait($remaining)) { throw 'Input timeout' }; $count=$read.Result; if ($count -eq 0) { throw 'Incomplete input' }; $offset += $count }; return ,$buffer }
    catch { throw }
}
try {
    if (-not [Console]::IsInputRedirected -or -not [Console]::IsOutputRedirected -or -not [Console]::IsErrorRedirected) { throw 'Redirected stdio required' }
    . (Join-Path $PSScriptRoot 'NativeHandlePrimitive.ps1')
    Add-Type -AssemblyName System.Security
    $repo=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../../../..'))
    $root=Join-Path $repo '.local/f02-synthetic-token-output'
    $parent=[F02SecureOutputNative]::WalkDirectory($root)
    [F02SecureOutputNative]::AssertOwnerOnly($parent)
    [F02SecureOutputNative]::AssertTestMarker($parent)
    $inputStream=[Console]::OpenStandardInput()
    $header=Read-Exact $inputStream 4
    $size=[BitConverter]::ToInt32($header,0)
    if ($size -ne 32) { throw 'Input size refused' }
    $plain=Read-Exact $inputStream $size
    $cipher=[Security.Cryptography.ProtectedData]::Protect($plain,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser)
    $sha=[Security.Cryptography.SHA256]::Create()
    try { $cipherHash=[BitConverter]::ToString($sha.ComputeHash($cipher)).Replace('-','').ToLowerInvariant() } finally { $sha.Dispose() }
    $name='pending-' + [guid]::NewGuid().ToString('N') + '.bin'
    $file=[F02SecureOutputNative]::CreateRelative($parent,$name)
    $stream=New-Object IO.FileStream($file,[IO.FileAccess]::ReadWrite)
    $stream.Write($cipher,0,$cipher.Length); $stream.Flush($true)
    [F02SecureOutputNative]::AssertOwnerOnly($file); [F02SecureOutputNative]::AssertSingleLink($file)
    $parentId=[F02SecureOutputNative]::Identity($parent); $fileId=[F02SecureOutputNative]::Identity($file)
    if ($parentId.Substring(0,8) -ne $fileId.Substring(0,8)) { throw 'Volume mismatch' }
    $frame=@{ version='f02-synthetic-secret-input-v1'; status='prepared'; name=$name; volume_id=$parentId.Substring(0,8); parent_file_id=$parentId.Substring(9); file_id=$fileId.Substring(9); ciphertext_hash=$cipherHash } | ConvertTo-Json -Compress
    [Array]::Clear($plain,0,$plain.Length); $plain=$null
    [Console]::Out.WriteLine($frame); [Console]::Out.Flush()
    # Only abort is accepted. There is no publication capability until receipt authorization exists.
    $abort=Read-Exact $inputStream 6
    if ([Text.Encoding]::ASCII.GetString($abort) -ne "abort`n") { throw 'Command refused' }
    $tail=New-Object byte[] 1; $end=$inputStream.ReadAsync($tail,0,1)
    if (-not $end.Wait(15000) -or $end.Result -ne 0) { throw 'Command refused' }
} catch { [Console]::Error.WriteLine('Synthetic secret input refused.'); exit 1 }
finally { if ($plain) { [Array]::Clear($plain,0,$plain.Length) }; if ($cipher) { [Array]::Clear($cipher,0,$cipher.Length) }; if ($stream) { $stream.Dispose() } elseif ($file) { $file.Dispose() }; if ($parent) { $parent.Dispose() } }
