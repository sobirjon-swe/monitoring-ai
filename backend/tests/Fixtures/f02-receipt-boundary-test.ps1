$ErrorActionPreference = 'Stop'
$boundary = Join-Path $PSScriptRoot 'f02-receipt-boundary.ps1'
if (-not (Test-Path -LiteralPath $boundary)) { throw 'Receipt boundary missing' }
. $boundary
$good = '{"version":"f02-receipt-v1","volume_id":"01234567","parent_file_id":"0123456789abcdef","file_id":"fedcba9876543210","ciphertext_hash":"' + ('a' * 64) + '"}'
$parsed = ConvertFrom-F02ReceiptCandidate $good
if ($parsed.file_id -cne 'fedcba9876543210') { throw 'Valid candidate did not parse' }
foreach ($invalid in @(
    $good.Replace('f02-receipt-v1','f02-receipt-v2'),
    $good.Replace('fedcba9876543210','FEDCBA9876543210'),
    $good.Replace('"version":','"authorized":true,"version":'),
    $good.Replace('"version":','"version":"f02-receipt-v1","version":'),
    ($good + ' trailing')
)) {
    $rejected = $false
    try { [void](ConvertFrom-F02ReceiptCandidate $invalid) } catch { $rejected = $true }
    if (-not $rejected) { throw 'Invalid candidate accepted' }
}
if (Test-F02PublicationAuthority $parsed) { throw 'Caller JSON unlocked publication' }
Write-Output 'synthetic receipt boundary rejects untrusted authorization: PASS'
