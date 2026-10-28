$ErrorActionPreference = 'Stop'
$script = Join-Path $PSScriptRoot 'review-ba-demo-test-provisioning.ps1'
$plan = & $script -ReviewPlan
if ($plan.Mode -ne 'review-only' -or $plan.Database -ne 'monitoring_ai_test' -or
    $plan.Host -ne '127.0.0.1' -or $plan.Port -ne 55432 -or
    $plan.MutatesDatabase -or $plan.CreatesCredentials -or $plan.PublishesCredentials) {
    throw 'Review-only provisioning boundary failed.'
}
$refused = $false
try { & $script } catch { $refused = $_.Exception.Message -eq 'Synthetic BA TEST setup role provisioning is unavailable pending security review.' }
if (-not $refused) { throw 'Provisioning without review was not refused.' }
Write-Output 'Review-only provisioning checks passed.'
