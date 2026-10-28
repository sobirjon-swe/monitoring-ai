[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
# One authorized rollback-only TEST probe completed; prevent accidental reruns.
throw 'The one-time administrator role probe is disabled after completion.'
$root = Split-Path $PSScriptRoot -Parent
. (Join-Path $root 'backend/app/Domain/Browser/BaDemo/NativeHandlePrimitive.ps1')
. (Join-Path $PSScriptRoot 'f02-probe-handle-review.ps1')
$sid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
$sandboxSid = ([Security.Principal.NTAccount]::new([Environment]::MachineName, 'CodexSandboxOffline')).Translate([Security.Principal.SecurityIdentifier]).Value
$owners = [string[]]@($sid, $sandboxSid)
$allowed = [string[]]@($sid, $sandboxSid, 'S-1-5-18', 'S-1-5-32-544')
$directory = $null; $recordHandle = $null; $passwordHandle = $null; $process = $null; $password = $null; $record = $null; $write = $null
try {
    # WalkDirectory holds and checks each path component against reparse points.
    $directory = [F02SecureOutputNative]::WalkDirectory((Join-Path $root '.local'))
    $recordHandle = [F02ProbeRead]::OpenFile($directory, 'db-config.json', $owners, $allowed)
    $passwordHandle = [F02ProbeRead]::OpenFile($directory, 'postgres-admin-password.txt', $owners, $allowed)
    function Read-BoundedHandle($handle, [int]$max) {
        $borrowed = [Microsoft.Win32.SafeHandles.SafeFileHandle]::new($handle.DangerousGetHandle(), $false)
        $stream = [IO.FileStream]::new($borrowed, [IO.FileAccess]::Read)
        $bytes = $null; $complete = $false
        try {
            if ($stream.Length -lt 1 -or $stream.Length -gt $max) { throw 'Input refused.' }
            $bytes = [byte[]]::new([int]$stream.Length); $offset = 0
            while ($offset -lt $bytes.Length) { $n = $stream.Read($bytes, $offset, $bytes.Length - $offset); if ($n -le 0) { throw 'Input refused.' }; $offset += $n }
            $complete = $true
            return ,$bytes
        } finally {
            if (!$complete -and $bytes) { [Array]::Clear($bytes, 0, $bytes.Length) }
            $stream.Dispose(); $borrowed.Dispose()
        }
    }
    $record = Read-BoundedHandle $recordHandle 65536
    $password = Read-BoundedHandle $passwordHandle 4096
    $start = [Diagnostics.ProcessStartInfo]::new()
    $start.FileName = Join-Path $root '.local/tools/php85/php.exe'
    $start.Arguments = '-d display_errors=0 -d log_errors=0 "' + (Join-Path $PSScriptRoot 'f02-pdo-role-probe-review.php') + '"'
    $start.UseShellExecute = $false; $start.CreateNoWindow = $true
    $start.RedirectStandardInput = $true; $start.RedirectStandardOutput = $true; $start.RedirectStandardError = $true
    foreach ($key in @($start.EnvironmentVariables.Keys)) { if ($key -like 'PG*') { $start.EnvironmentVariables.Remove($key) } }
    $process = [Diagnostics.Process]::Start($start)
    $out = $process.StandardOutput.ReadToEndAsync(); $err = $process.StandardError.ReadToEndAsync()
    foreach ($part in @([BitConverter]::GetBytes([int]$record.Length), $record, [BitConverter]::GetBytes([int]$password.Length), $password)) {
        $write = $process.StandardInput.BaseStream.WriteAsync($part, 0, $part.Length)
        if (!$write.Wait(5000)) { throw 'Input timeout; outcome unknown.' }
    }
    $process.StandardInput.Close()
    if (!$process.WaitForExit(45000) -or !$out.Wait(5000) -or !$err.Wait(5000) -or $process.ExitCode -ne 0) { throw 'Outcome unknown; do not retry without investigation.' }
    Write-Output 'Probe completed: transient role flags and rollback verified.'
} finally {
    try {
        if ($process) {
            try { if (!$process.HasExited) { $process.Kill(); if (!$process.WaitForExit(5000)) { throw 'Client could not be reaped; outcome unknown.' } } }
            finally {
                try { $process.StandardInput.Dispose() } catch { }
                if ($write -and !$write.IsCompleted) { try { [void]$write.Wait(5000) } catch { } }
                $process.Dispose()
            }
        }
    } finally {
        try { if ($passwordHandle) { $passwordHandle.Dispose() } }
        finally {
            try { if ($recordHandle) { $recordHandle.Dispose() } }
            finally {
                try { if ($directory) { $directory.Dispose() } }
                finally {
                    try { if ($password) { [Array]::Clear($password, 0, $password.Length) } }
                    finally { if ($record) { [Array]::Clear($record, 0, $record.Length) } }
                }
            }
        }
    }
}
