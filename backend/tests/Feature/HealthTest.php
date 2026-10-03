<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'logging.default' => 'null']);
    }

    public function test_health_returns_ok(): void
    {
        config(['database.connections.pgsql.password' => 'do-not-expose-db-password']);

        $response = $this->get('/up');

        $response->assertOk()->assertExactJson(['status' => 'ok']);
        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-ID')));
        $response->assertDontSee(config('app.key'))->assertDontSee('do-not-expose-db-password');
    }

    public function test_failure_hides_secrets_even_when_local_debug_is_enabled(): void
    {
        config(['app.debug' => true]);
        $logPath = tempnam(sys_get_temp_dir(), 'monitoring-http-test-');
        config(['logging.default' => 'test_file', 'logging.channels.test_file' => [
            'driver' => 'single', 'path' => $logPath, 'level' => 'error',
        ]]);
        $this->beforeApplicationDestroyed(fn () => unlink($logPath));
        Route::get('/test-failure', function (): never {
            throw new RuntimeException('password=synthetic-secret connection=pgsql://private-host');
        });

        $response = $this->get('/test-failure');

        $response->assertStatus(500)->assertJsonPath('error.code', 'internal_error');
        $this->assertTrue(Str::isUuid($response->json('request_id')));
        $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-ID'));
        $response->assertDontSee('synthetic-secret')->assertDontSee('private-host')->assertDontSee('trace');
        $log = file_get_contents($logPath);
        $this->assertStringContainsString($response->json('request_id'), $log);
        $this->assertStringContainsString('RuntimeException', $log);
        $this->assertStringNotContainsString('synthetic-secret', $log);
        $this->assertStringNotContainsString('private-host', $log);
    }

    public function test_root_does_not_expose_a_welcome_ui(): void
    {
        $this->get('/')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_api_group_denies_unauthenticated_requests(): void
    {
        Route::middleware('api')->get('/api/v1/test-protected', fn () => ['protected' => true]);

        $this->get('/api/v1/test-protected')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_untrusted_request_id_is_replaced(): void
    {
        $response = $this->withHeader('X-Request-ID', 'synthetic-secret')->get('/up');

        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-ID')));
        $response->assertHeaderMissing('Server-Timing');
    }

    public function test_validation_returns_422_without_raw_sensitive_messages(): void
    {
        Route::get('/test-validation', function (): never {
            throw ValidationException::withMessages(['email' => ['synthetic-secret']]);
        });

        $this->get('/test-validation')->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_request')->assertJsonPath('errors.email.0', 'Invalid value.')
            ->assertDontSee('synthetic-secret');
    }

    public function test_protocol_headers_survive_safe_errors(): void
    {
        Route::get('/test-throttle', function (): never {
            throw new HttpException(429, 'synthetic-secret', null, ['Retry-After' => '30', 'X-Private' => 'synthetic-secret']);
        });
        Route::get('/test-method', function (): never {
            throw new HttpException(405, 'synthetic-secret', null, ['Allow' => 'GET, HEAD']);
        });

        $this->get('/test-throttle')->assertStatus(429)->assertHeader('Retry-After', '30')
            ->assertHeaderMissing('X-Private')->assertDontSee('synthetic-secret');
        $this->get('/test-method')->assertStatus(405)->assertHeader('Allow', 'GET, HEAD');
    }
}
