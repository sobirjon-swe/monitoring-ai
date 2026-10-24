<?php

namespace App\Domain\Browser\Demo;

use LogicException;
use Symfony\Component\Process\Process;

/** Protected journal and no-clobber publication; PostgreSQL and NTFS are separate resources. */
class DemoCredentialOutput
{
    private mixed $handle = null;

    private string $path;

    private string $pending;

    public static function assertLocalPath(string $path): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! preg_match('/\A[A-Za-z]:[\\\\\/]/D', $path) || preg_match('/[\x00-\x1f\x7f<>"|?*]/', $path)) {
            throw new LogicException('Protected local path refused.');
        }
        $parts = explode('\\', str_replace('/', '\\', substr($path, 3)));
        foreach ($parts as $part) {
            $device = rtrim(explode('.', rtrim($part, ' .'), 2)[0], ' ');
            if ($part === '' || $part === '.' || $part === '..' || $part !== rtrim($part, ' .') || str_contains($part, ':')
                || preg_match('/\A(?:CON|PRN|AUX|NUL|CONIN\$|CONOUT\$|COM[1-9¹²³]|LPT[1-9¹²³])\z/iuD', $device)) {
                throw new LogicException('Protected local path refused.');
            }
        }
    }

    public function open(string $path): void
    {
        self::assertLocalPath($path);
        $this->path = $path;
        $this->pending = $path.'.pending';
        // Refuse writable/shared directories and reparse ancestors before reserving even an empty file.
        $this->acl(dirname($path), false, true);
        if (file_exists($path)) {
            $this->acl($path, false, false);
        }
        if (file_exists($this->pending)) {
            $this->acl($this->pending, false, false);
            if (file_exists($path) && ! hash_equals(hash_file('sha256', $this->pending), hash_file('sha256', $path))) {
                throw new LogicException('Existing credential target refused.');
            }
            $this->handle = fopen($this->pending, 'r+b');
        } else {
            if (file_exists($path)) {
                throw new LogicException('Existing credential target refused.');
            }
            $this->handle = fopen($this->pending, 'x+b');
            if ($this->handle === false) {
                throw new LogicException('Credential reservation refused.');
            }
            $this->acl($this->pending, true, false);
        }
        if (! is_resource($this->handle) || ! flock($this->handle, LOCK_EX | LOCK_NB)) {
            throw new LogicException('Credential journal busy.');
        }
        $this->acl($this->pending, false, false);
    }

    public function read(): ?array
    {
        rewind($this->handle);
        $raw = stream_get_contents($this->handle, 65537);
        if ($raw === '') {
            return null;
        }
        if (! is_string($raw) || strlen($raw) > 65536) {
            throw new LogicException('Credential journal refused.');
        }
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new LogicException('Credential journal refused.');
        }

        return $data;
    }

    public function write(array $data): void
    {
        if (file_exists($this->path)) {
            throw new LogicException('Published credentials are immutable.');
        }
        $this->acl($this->pending, false, false);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (strlen($json) > 65536) {
            throw new LogicException('Credential journal refused.');
        }
        rewind($this->handle);
        if (! ftruncate($this->handle, 0) || fwrite($this->handle, $json) !== strlen($json) || ! fflush($this->handle) || ! fsync($this->handle)) {
            throw new LogicException('Credential journal persistence failed.');
        }
    }

    public function publish(): void
    {
        $this->acl($this->pending, false, false);
        if (! file_exists($this->path) && ! link($this->pending, $this->path)) {
            throw new LogicException('Credential publication failed.');
        }
        $this->acl($this->path, false, false);
        // Windows locks apply across hardlinks. Both names become immutable on publication.
        $this->close();
        if (! hash_equals(hash_file('sha256', $this->pending), hash_file('sha256', $this->path))) {
            throw new LogicException('Existing credential target refused.');
        }
        // Keep the protected journal: it is the recovery locator, never a cleanup target.
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function acl(string $path, bool $protect, bool $directory): void
    {
        $script = <<<'PS'
$ErrorActionPreference='Stop'
$p=$env:DEMO_ACL_PATH
$drive=[System.IO.DriveInfo]::new([System.IO.Path]::GetPathRoot($p))
if ($drive.DriveType -ne 'Fixed' -or $drive.DriveFormat -ne 'NTFS') {throw 'volume'}
$s=[System.Security.Principal.WindowsIdentity]::GetCurrent().User
$item=Get-Item -LiteralPath $p -Force
if ($item.PSIsContainer -ne ($env:DEMO_ACL_DIRECTORY -eq '1')) { throw 'kind' }
$cursor=$item
while ($null -ne $cursor) {
 if (($cursor.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'reparse' }
 $cursor=if ($cursor.PSIsContainer) {$cursor.Parent} else {$cursor.Directory}
}
$a=Get-Acl -LiteralPath $p
if ($a.GetOwner([System.Security.Principal.SecurityIdentifier]).Value -ne $s.Value) {throw 'owner'}
if ($env:DEMO_ACL_PROTECT -eq '1') {
 $a.SetAccessRuleProtection($true,$false)
 foreach($r in @($a.Access)) { [void]$a.RemoveAccessRuleSpecific($r) }
 $a.AddAccessRule([System.Security.AccessControl.FileSystemAccessRule]::new($s,'FullControl','Allow'))
 Set-Acl -LiteralPath $p -AclObject $a
 $a=Get-Acl -LiteralPath $p
}
if (-not $a.AreAccessRulesProtected) {throw 'inheritance'}
$allow=$false
foreach($r in $a.Access) {
 if ($r.AccessControlType -ne 'Allow') {throw 'deny'}
 $id=$r.IdentityReference.Translate([System.Security.Principal.SecurityIdentifier]).Value
 if ($id -ne $s.Value) {throw 'shared'}
 if (($r.FileSystemRights -band [System.Security.AccessControl.FileSystemRights]::FullControl) -eq [System.Security.AccessControl.FileSystemRights]::FullControl) {$allow=$true}
}
if (-not $allow) {throw 'rights'}
PS;
        $process = new Process(['C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe', '-NoProfile', '-NonInteractive', '-Command', $script], null,
            ['PSModulePath' => 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\Modules', 'DEMO_ACL_PATH' => $path, 'DEMO_ACL_PROTECT' => $protect ? '1' : '0', 'DEMO_ACL_DIRECTORY' => $directory ? '1' : '0']);
        $process->setTimeout(15);
        $process->disableOutput();
        if ($process->run() !== 0) {
            throw new LogicException('Credential owner/ACL/reparse protection refused.');
        }
    }
}
