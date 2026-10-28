[CmdletBinding()]
param([switch]$ReviewPlan)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# This review-only entry point intentionally cannot create roles or credentials.
if (-not $ReviewPlan) {
    throw 'Synthetic BA TEST setup role provisioning is unavailable pending security review.'
}
[pscustomobject]@{
    Mode = 'review-only'
    Database = 'monitoring_ai_test'
    Host = '127.0.0.1'
    Port = 55432
    Role = 'monitoring_ai_ba_demo_setup'
    MutatesDatabase = $false
    CreatesCredentials = $false
    PublishesCredentials = $false
    RequiredReview = @(
        'Exact installation marker and runtime identity',
        'Explicit fixture table and sequence grant inventory',
        'Non-owner NOSUPERUSER NOBYPASSRLS role and FORCE RLS',
        'No runtime membership or role escalation path',
        'Protected credential creation and crash recovery',
        'One physical transaction for fixture and receipt',
        'Snapshot serialization and expiry fence through rename'
    )
}
