# Synthetic experiment only. Parsing a candidate never authenticates a receipt.
function ConvertFrom-F02ReceiptCandidate([string]$json) {
    if ($null -eq $json -or $json.Length -gt 512) { throw 'Receipt candidate refused' }
    # Require one canonical encoding so duplicate keys and extra authorization claims
    # cannot be interpreted differently by separate JSON parsers.
    $pattern = '\A\{"version":"f02-receipt-v1","volume_id":"(?<volume>[0-9a-f]{8})","parent_file_id":"(?<parent>[0-9a-f]{16})","file_id":"(?<file>[0-9a-f]{16})","ciphertext_hash":"(?<hash>[0-9a-f]{64})"\}\z'
    $match = [regex]::Match($json, $pattern, [Text.RegularExpressions.RegexOptions]::CultureInvariant)
    if (-not $match.Success) { throw 'Receipt candidate refused' }
    return [pscustomobject]@{
        version = 'f02-receipt-v1'
        volume_id = $match.Groups['volume'].Value
        parent_file_id = $match.Groups['parent'].Value
        file_id = $match.Groups['file'].Value
        ciphertext_hash = $match.Groups['hash'].Value
    }
}

function Test-F02PublicationAuthority($candidate) {
    # No independent committed-receipt verifier is connected to this experiment.
    # A candidate, regardless of shape or provenance, cannot grant authority.
    return $false
}
