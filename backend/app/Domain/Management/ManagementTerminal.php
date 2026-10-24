<?php

namespace App\Domain\Management;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class ManagementTerminal
{
    public function overlayLocked(string $project, array $graph): array
    {
        $this->locked();
        $ids = array_merge([], ...array_values($graph['refs'] ?? []));
        if (($graph['coverage'] ?? 'unknown') !== 'complete' || count($ids) > 4000) {
            return [];
        }

        return DB::table('management_terminal_applicability')->where('project_id', $project)->whereIn('subject_id', array_unique($ids))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function observeLocked(string $project, string $kind, string $subject, string $proofHash, string $reason): void
    {
        $this->locked();
        if (! Str::isUuid($project) || ! Str::isUuid($subject) || ! in_array($kind, ['receipt', 'action', 'action_version', 'assignment', 'support', 'review', 'event', 'proof'], true) || ! preg_match('/\A[0-9a-f]{64}\z/D', $proofHash) || ! in_array($reason, ['access_revoked', 'role_revoked', 'source_revoked', 'source_invalid'], true)) {
            throw new InvalidArgumentException('Invalid management terminal observation.');
        }
        DB::table('management_terminal_applicability')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => DB::scalar("select current_setting('app.tenant_id')"), 'project_id' => $project, 'kind' => $kind, 'subject_id' => $subject, 'proof_hash' => $proofHash, 'reason_code' => $reason]);
    }

    private function locked(): void
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.management_authority_closed',true),'')") !== '1') {
            throw new LogicException('Management terminal observation requires coordination.');
        }
    }
}
