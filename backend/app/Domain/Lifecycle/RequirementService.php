<?php

namespace App\Domain\Lifecycle;

use App\Domain\Access\TrustedActor;
use App\Domain\Audit\AuditWriter;
use App\Domain\DeveloperAnalysis\AnalysisInvalidation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Opis\JsonSchema\CompliantValidator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RequirementService
{
    public function __construct(private readonly ProjectCoordination $coordination, private readonly LifecycleChangeWriter $changes, private readonly AuditWriter $audit) {}

    public function createDraft(TrustedActor $actor, string $projectId, array $data): array
    {
        $data = $this->validate($data);

        return $this->coordination->read($actor, $projectId, 'requirements.manage', function () use ($actor, $projectId, $data): array {
            $this->owner($projectId, $data['owner_id']);
            if (DB::table('requirements')->where('project_id', $projectId)->count() >= 100) {
                $this->invalid();
            }
            $rid = (string) Str::uuid();
            $id = (string) Str::uuid();
            $scope = ['tenant_id' => $actor->tenantId, 'project_id' => $projectId];
            DB::table('requirements')->insert(['id' => $rid] + $scope);
            DB::table('requirement_drafts')->insert(['id' => $id, 'requirement_id' => $rid, 'content' => $this->json($data)] + $scope);
            $this->audit->record($actor->id, $scope, 'requirement.draft_created', ['type' => 'requirement_drafts', 'id' => $id], 1, 'approved_change');

            return ['id' => $id, 'requirement_id' => $rid, 'revision' => 1];
        });
    }

    public function updateDraft(TrustedActor $actor, string $projectId, string $draftId, array $data, int $expectedRevision): array
    {
        $data = $this->validate($data);

        return $this->coordination->read($actor, $projectId, 'requirements.manage', function () use ($actor, $projectId, $draftId, $data, $expectedRevision): array {
            $d = $this->draft($projectId, $draftId);
            if ($d->revision !== $expectedRevision) {
                throw new ConflictHttpException;
            }$this->owner($projectId, $data['owner_id']);
            DB::table('requirement_drafts')->where('id', $draftId)->update(['content' => $this->json($data), 'revision' => $expectedRevision + 1]);
            $this->audit->record($actor->id, ['tenant_id' => $actor->tenantId, 'project_id' => $projectId], 'requirement.draft_updated', ['type' => 'requirement_drafts', 'id' => $draftId], $expectedRevision + 1, 'approved_change');

            return ['id' => $draftId, 'requirement_id' => $d->requirement_id, 'revision' => $expectedRevision + 1];
        });
    }

    public function approve(TrustedActor $actor, string $projectId, string $draftId, int $expectedRevision, int $expectedGeneration, string $reason): array
    {
        if (! trim($reason) || mb_strlen($reason) > 500 || str_contains($reason, "\0")) {
            $this->invalid();
        }
        $r = $this->coordination->run($actor, $projectId, 'requirements.approve', $expectedGeneration, function () use ($actor, $projectId, $draftId, $expectedRevision, $reason): array {
            $d = $this->draft($projectId, $draftId);
            if ($d->revision !== $expectedRevision) {
                throw new ConflictHttpException;
            }
            $data = $this->validate(json_decode($d->content, true, 16, JSON_THROW_ON_ERROR));
            $this->owner($projectId, $data['owner_id']);
            $old = DB::table('requirement_versions')->where('requirement_id', $d->requirement_id)->orderByDesc('version')->first();
            if ($old && $old->draft_revision === $expectedRevision) {
                throw new ConflictHttpException;
            }
            $id = (string) Str::uuid();
            $version = ($old->version ?? 0) + 1;
            $scope = ['tenant_id' => $actor->tenantId, 'project_id' => $projectId];
            $json = $this->json($data);
            $hash = hash('sha256', $json);
            DB::table('requirement_versions')->insert(['id' => $id, 'requirement_id' => $d->requirement_id, 'version' => $version, 'draft_revision' => $expectedRevision, 'content' => $json, 'content_hash' => $hash, 'approved_by' => $actor->id, 'reason' => $reason] + $scope);
            $context = hash('sha256', $this->json(['business_outcome' => $data['business_outcome'], 'nfr_constraints' => $data['nfr_constraints'], 'dependencies' => $data['dependencies']]));
            $oldLinks = $old ? DB::table('requirement_version_criteria')->where('requirement_version_id', $old->id)->get()->keyBy('criterion_id') : collect();
            $kept = [];
            $criteria = [];
            foreach ($data['criteria'] as $item) {
                $c = DB::table('criteria')->where('requirement_id', $d->requirement_id)->where('key', $item['key'])->first();
                $cid = $c->id ?? (string) Str::uuid();
                if (! $c) {
                    DB::table('criteria')->insert(['id' => $cid, 'requirement_id' => $d->requirement_id, 'key' => $item['key']] + $scope);
                }
                $fingerprint = hash('sha256', $this->json(['text' => $item['text'], 'required_test_keys' => $item['required_test_keys'], 'context_hash' => $context]));
                $prev = isset($oldLinks[$cid]) ? DB::table('criterion_versions')->where('id', $oldLinks[$cid]->criterion_version_id)->first() : null;
                if ($prev && $prev->content_hash === $fingerprint) {
                    $vid = $prev->id;
                } else {
                    $vid = (string) Str::uuid();
                    $cv = (int) DB::table('criterion_versions')->where('criterion_id', $cid)->max('version') + 1;
                    DB::table('criterion_versions')->insert(['id' => $vid, 'criterion_id' => $cid, 'version' => $cv, 'text' => $item['text'], 'required_test_keys' => $this->json($item['required_test_keys']), 'context_hash' => $context, 'content_hash' => $fingerprint] + $scope);
                    if ($prev) {
                        $this->changes->record($actor, $projectId, 'requirements.approve', 'criterion_replaced', $cid, $vid, $reason);
                    }
                }
                DB::table('requirement_version_criteria')->insert(['id' => (string) Str::uuid(), 'requirement_version_id' => $id, 'requirement_id' => $d->requirement_id, 'criterion_id' => $cid, 'criterion_version_id' => $vid] + $scope);
                $kept[] = $cid;
                $criteria[] = ['id' => $cid, 'key' => $item['key'], 'version_id' => $vid];
            }
            foreach ($oldLinks as $cid => $link) {
                if (! in_array($cid, $kept, true)) {
                    $this->changes->record($actor, $projectId, 'requirements.approve', 'criterion_removed', $cid, $id, $reason);
                }
            }
            $this->audit->record($actor->id, $scope, 'requirement.approved', ['type' => 'requirement_versions', 'id' => $id], $version, 'approved_change');
            app(AnalysisInvalidation::class)->observeProject($actor, $projectId, 'requirement:'.$id, ['criterion_version_ids' => $oldLinks->pluck('criterion_version_id')->all()]);

            return ['id' => $id, 'requirement_id' => $d->requirement_id, 'version' => $version, 'hash' => $hash, 'criteria' => $criteria];
        });

        return $r['result'] + ['generation' => $r['generation']];
    }

    public function listing(TrustedActor $actor, string $projectId, int $limit = 20, ?string $cursor = null): array
    {
        if ($limit < 1 || $limit > 100 || ($cursor !== null && ! Str::isUuid($cursor))) {
            $this->invalid();
        }

        return $this->coordination->read($actor, $projectId, 'requirements.read', function () use ($projectId, $limit, $cursor): array {
            $q = DB::table('requirement_versions')->where('project_id', $projectId)->orderBy('id');
            if ($cursor) {
                $q->where('id', '>', $cursor);
            }

            return $q->limit($limit)->get(['id', 'requirement_id', 'version', 'draft_revision', 'content_hash', 'approved_at'])->map(fn ($v) => (array) $v)->all();
        });
    }

    private function draft(string $project, string $id): object
    {
        return DB::table('requirement_drafts')->where('project_id', $project)->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundHttpException;
    }

    private function owner(string $project, string $owner): void
    {
        if (! DB::table('employees')->where('id', $owner)->exists() || ! DB::table('project_memberships')->where('project_id', $project)->where('employee_id', $owner)->where('valid_from', '<=', now())->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', now()))->exists()) {
            $this->invalid();
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['requirement' => 'Invalid requirement.']);
    }

    public function validate(array $data): array
    {
        $schema = json_decode(file_get_contents(base_path('../contracts/lifecycle-requirement-v1.schema.json')), false, 16, JSON_THROW_ON_ERROR);
        $object = json_decode(json_encode($data, JSON_THROW_ON_ERROR), false, 16, JSON_THROW_ON_ERROR);
        if (! (new CompliantValidator)->validate($object, $schema)->isValid() || strlen(json_encode($data)) > 65536 || str_contains(json_encode($data), '\\u0000')) {
            $this->invalid();
        }
        foreach (['title', 'stakeholder', 'business_outcome'] as $key) {
            if (! trim($data[$key])) {
                $this->invalid();
            }
        }
        $keys = [];
        foreach ($data['criteria'] as &$c) {
            if (! trim($c['key']) || ! trim($c['text']) || in_array($c['key'], $keys, true)) {
                $this->invalid();
            }$keys[] = $c['key'];
            foreach ($c['required_test_keys'] as $t) {
                if (! trim($t)) {
                    $this->invalid();
                }
            }sort($c['required_test_keys'], SORT_STRING);
        }unset($c);
        foreach ($data['nfr_constraints'] as $n) {
            if (! trim($n)) {
                $this->invalid();
            }
        }sort($data['nfr_constraints'], SORT_STRING);
        sort($data['dependencies'], SORT_STRING);
        usort($data['criteria'], fn ($a, $b) => strcmp($a['key'], $b['key']));

        return $data;
    }

    private function json(array $data): string
    {
        $sort = function ($v) use (&$sort) {
            if (! is_array($v)) {
                return $v;
            }if (! array_is_list($v)) {
                ksort($v, SORT_STRING);
            }

            return array_map($sort, $v);
        };

        return json_encode($sort($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
