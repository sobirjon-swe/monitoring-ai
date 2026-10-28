<?php

namespace App\Http\Controllers;

use App\Domain\Ba\BaClarificationService;
use App\Domain\Ba\BaContract;
use App\Domain\Browser\BrowserAttemptCleanup;
use App\Domain\Browser\BrowserBaAuthority;
use App\Domain\Browser\BrowserSessionService;
use App\Http\Controllers\Concerns\StrictBaJson;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class BrowserBaAttemptController extends Controller
{
    use StrictBaJson;

    public function claim(Request $request): mixed
    {
        return $this->baResponse(function () use ($request) {
            $this->baControls($request);
            $body = $this->baJson($request, 'event');
            $project = strtolower($request->route('project'));
            $clarification = strtolower($request->route('clarification'));
            if ($body['kind'] !== 'context.add' || $body['clarification_id'] !== $clarification) {
                $this->invalidBa();
            }

            return $this->runProtected($request, function ($verified, $remember) use ($request, $body, $project, $clarification) {
                $hash = hash_hmac('sha256', "monitoring.browser.session.v1\0".$request->session()->getId(), (string) config('app.key'));
                DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['browser-ba:'.$verified->actor->tenantId.':'.$hash.':'.$project.':'.$clarification]);
                $slot = DB::table('browser_ba_attempts')->where(['session_hash' => $hash, 'project_id' => $project, 'clarification_id' => $clarification])->where('state', '<>', 'closed')->lockForUpdate()->first();
                if ($slot) {
                    $remember($slot->id);
                }
                if ($slot && ($slot->state === 'tombstone' || $slot->actor_id !== $verified->actor->id || CarbonImmutable::parse($slot->expires_at)->lessThanOrEqualTo(now()))) {
                    throw new ConflictHttpException;
                }
                $saved = $slot ? json_decode(Crypt::decryptString($slot->ciphertext), true, 32, JSON_THROW_ON_ERROR) : null;
                $key = $saved['key'] ?? (string) Str::uuid();
                $this->authorize($verified->actor, $project, $clarification, $key, $slot ? null : $body['expected_revision']);
                if ($saved !== null && BaContract::canonical($saved['body']) !== BaContract::canonical($body)) {
                    throw new ConflictHttpException;
                }
                if ($slot) {
                    return ['attempt_id' => $slot->id, 'state' => $slot->state, 'replayed' => true];
                }
                $id = (string) Str::uuid();
                DB::table('browser_ba_attempts')->insert(['id' => $id, 'tenant_id' => $verified->actor->tenantId,
                    'project_id' => $project, 'clarification_id' => $clarification, 'actor_id' => $verified->actor->id,
                    'session_hash' => $hash, 'state' => 'claimed', 'ciphertext' => Crypt::encryptString(json_encode(['body' => $body, 'key' => $key, 'binding' => $request->session()->get(BrowserSessionService::BINDING)], JSON_THROW_ON_ERROR)),
                    'expires_at' => $verified->expiresAt->min(now()->addMinutes(120))]);

                return ['attempt_id' => $id, 'state' => 'claimed', 'replayed' => false];
            });
        }, true);
    }

    public function show(Request $request): mixed
    {
        return $this->baResponse(function () use ($request) {
            $this->baControls($request);
            if ($request->query() || $request->getContent() !== '') {
                $this->invalidBa();
            }
            $project = strtolower($request->route('project'));
            $clarification = strtolower($request->route('clarification'));

            return $this->runProtected($request, function ($verified, $remember) use ($request, $project, $clarification) {
                $hash = hash_hmac('sha256', "monitoring.browser.session.v1\0".$request->session()->getId(), (string) config('app.key'));
                DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['browser-ba:'.$verified->actor->tenantId.':'.$hash.':'.$project.':'.$clarification]);
                $slot = DB::table('browser_ba_attempts')->where(['session_hash' => $hash, 'project_id' => $project, 'clarification_id' => $clarification])->where('state', '<>', 'closed')->lockForUpdate()->first();
                if ($slot) {
                    $remember($slot->id);
                }
                if (! $slot || $slot->state === 'tombstone' || $slot->actor_id !== $verified->actor->id || CarbonImmutable::parse($slot->expires_at)->lessThanOrEqualTo(now())) {
                    throw new NotFoundHttpException;
                }
                $saved = json_decode(Crypt::decryptString($slot->ciphertext), true, 32, JSON_THROW_ON_ERROR);
                $this->authorize($verified->actor, $project, $clarification, $saved['key']);

                return ['attempt_id' => $slot->id, 'state' => $slot->state];
            });
        });
    }

    public function execute(Request $request): mixed
    {
        $response = $this->baResponse(function () use ($request) {
            return $this->operate($request, false);
        });
        if ($response->getData(true)['data'] === ['conflict' => true]) {
            return response()->json(['error' => ['code' => 'attempt_conflict', 'message' => 'Attempt conflicted.'],
                'request_id' => $request->attributes->get('request_id')], 409);
        }
        if ($response->getData(true)['data'] === ['invalid' => true]) {
            return response()->json(['error' => ['code' => 'attempt_rejected', 'message' => 'Attempt rejected.'],
                'request_id' => $request->attributes->get('request_id')], 422);
        }

        return $response;
    }

    public function ack(Request $request): mixed
    {
        $this->baResponse(fn () => $this->operate($request, true));

        return response()->noContent();
    }

    private function operate(Request $request, bool $ack): array
    {
        $this->baControls($request);
        if ($request->query() || strlen($request->getContent()) > 1024) {
            $this->invalidBa();
        }
        $input = app(BaContract::class)->parse($request->getContent());
        if (array_keys($input) !== ['attempt_id'] || ! is_string($input['attempt_id']) || ! Str::isUuid($input['attempt_id'])) {
            $this->invalidBa();
        }
        $project = strtolower($request->route('project'));
        $clarification = strtolower($request->route('clarification'));

        return $this->runProtected($request, function ($verified, $remember) use ($request, $project, $clarification, $input, $ack): array {
            $hash = hash_hmac('sha256', "monitoring.browser.session.v1\0".$request->session()->getId(), (string) config('app.key'));
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['browser-ba:'.$verified->actor->tenantId.':'.$hash.':'.$project.':'.$clarification]);
            $slot = DB::table('browser_ba_attempts')->where(['id' => strtolower($input['attempt_id']), 'session_hash' => $hash, 'project_id' => $project, 'clarification_id' => $clarification])->where('state', '<>', 'closed')->lockForUpdate()->first();
            if ($slot) {
                $remember($slot->id);
            }
            if (! $slot || $slot->state === 'tombstone' || $slot->actor_id !== $verified->actor->id || CarbonImmutable::parse($slot->expires_at)->lessThanOrEqualTo(now())) {
                throw new NotFoundHttpException;
            }
            $saved = json_decode(Crypt::decryptString($slot->ciphertext), true, 32, JSON_THROW_ON_ERROR);
            $this->authorize($verified->actor, $project, $clarification, $saved['key']);
            if ($ack) {
                if ($slot->state !== 'completed') {
                    throw new ConflictHttpException;
                }
                DB::table('browser_ba_attempts')->where('id', $slot->id)->update(['state' => 'closed', 'ciphertext' => null]);

                return [];
            }
            if ($slot->state === 'completed') {
                return $saved['result'];
            }
            try {
                $result = DB::transaction(fn () => app(BaClarificationService::class)->event($verified->actor, $project, $clarification, $saved['body'], $saved['key']));
            } catch (ConflictHttpException) {
                DB::table('browser_ba_attempts')->where('id', $slot->id)->update(['state' => 'closed', 'ciphertext' => null]);

                return ['conflict' => true];
            } catch (\InvalidArgumentException) {
                DB::table('browser_ba_attempts')->where('id', $slot->id)->update(['state' => 'closed', 'ciphertext' => null]);

                return ['invalid' => true];
            }
            $saved['result'] = $result;
            DB::table('browser_ba_attempts')->where('id', $slot->id)->update(['state' => 'completed', 'ciphertext' => Crypt::encryptString(json_encode($saved, JSON_THROW_ON_ERROR))]);

            return $result;
        });
    }

    private function runProtected(Request $request, callable $work): mixed
    {
        $sessions = app(BrowserSessionService::class);
        $failedId = null;
        try {
            return $sessions->runLocked($request->session(), function ($verified) use ($work, &$failedId) {
                return $work($verified, function (string $id) use (&$failedId): void {
                    $failedId = $id;
                });
            });
        } catch (AccessDeniedHttpException $denied) {
            if ($failedId === null) {
                throw $denied;
            }
            // The failed authority transaction rolled back; clear only this
            // authenticated session's slot in a separate transaction.
            $sessions->runLocked($request->session(), function ($verified) use ($request, $failedId): void {
                $project = strtolower($request->route('project'));
                $clarification = strtolower($request->route('clarification'));
                $hash = hash_hmac('sha256', "monitoring.browser.session.v1\0".$request->session()->getId(), (string) config('app.key'));
                DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['browser-ba:'.$verified->actor->tenantId.':'.$hash.':'.$project.':'.$clarification]);
                app(BrowserAttemptCleanup::class)->clearDeniedSlot($failedId, $hash, $verified->actor->id, $project, $clarification);
            });
            throw $denied;
        }
    }

    private function authorize($actor, string $project, string $clarification, string $key, ?int $expectedRevision = null): void
    {
        app(BrowserBaAuthority::class)->check($actor, $project, $clarification, $key, $expectedRevision);
    }
}
