<?php

namespace App\Domain\Ba;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class BaTerminal
{
    public function overlayLocked(string $project, array $graph): array
    {
        $this->locked();
        $ids = [];
        foreach ($graph['refs'] ?? [] as $refs) {
            $ids = [...$ids, ...$refs];
        }
        if (($graph['coverage'] ?? 'unknown') !== 'complete' || count($ids) > 2300) {
            return [];
        }

        return DB::table('ba_terminal_applicability')->where('project_id', $project)->whereIn('subject_id', array_unique($ids))->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    public function observeLocked(string $project, string $kind, string $id, string $proofHash, string $reason): void
    {
        $this->locked();
        if (! Str::isUuid($project) || ! Str::isUuid($id) || ! in_array($kind, ['receipt', 'request', 'answer', 'review', 'event', 'party', 'proof'], true) || ! preg_match('/\A[0-9a-f]{64}\z/D', $proofHash) || ! in_array($reason, ['access_revoked', 'role_revoked', 'source_revoked'], true)) {
            throw new InvalidArgumentException('Invalid BA terminal observation.');
        }
        DB::table('ba_terminal_applicability')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => DB::scalar("select current_setting('app.tenant_id')"), 'project_id' => $project, 'kind' => $kind, 'subject_id' => $id, 'proof_hash' => $proofHash, 'reason_code' => $reason]);
    }

    private function locked(): void
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.ba_authority_closed',true),'')") !== '1') {
            throw new LogicException('BA terminal observation requires coordination.');
        }
    }
}
