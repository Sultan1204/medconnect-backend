<?php

namespace Tests\Feature\Support;

use Illuminate\Support\Str;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    public function test_health_check_reports_dependencies(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.cache', 'ok');
    }

    public function test_api_responses_carry_security_headers_and_are_not_cacheable(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_request_id_is_generated_or_propagated(): void
    {
        $generated = $this->getJson('/api/v1/health')->headers->get('X-Request-Id');
        $this->assertTrue(Str::isUuid($generated));

        $id = (string) Str::uuid();
        $this->getJson('/api/v1/health', ['X-Request-Id' => $id])->assertHeader('X-Request-Id', $id);

        // Malformed ids are replaced, not echoed back.
        $replaced = $this->getJson('/api/v1/health', ['X-Request-Id' => '<script>'])->headers->get('X-Request-Id');
        $this->assertTrue(Str::isUuid($replaced));
    }

    public function test_unknown_routes_use_the_error_envelope(): void
    {
        $this->get('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_validation_errors_are_reported_per_field(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['identifier', 'password']]]]);
    }
}
