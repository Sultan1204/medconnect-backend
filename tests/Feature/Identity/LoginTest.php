<?php

namespace Tests\Feature\Identity;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Events\SuspiciousActivityDetected;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LoginTest extends TestCase
{
    private function login(string $identifier, string $password = 'Password123', array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['identifier' => $identifier, 'password' => $password] + $extra);
    }

    public function test_patient_signs_in_with_phone_in_local_format(): void
    {
        User::factory()->patient()->create(['phone' => '+966512345678']);

        $token = $this->login('0512345678')
            ->assertOk()
            ->assertJsonPath('data.status', 'authenticated')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->json('data.access_token');

        $this->assertStringStartsWith('mcs_', explode('|', $token, 2)[1]);
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    }

    public function test_patient_signs_in_with_email_case_insensitively(): void
    {
        User::factory()->patient()->create(['email' => 'someone@example.test']);

        $this->login('SomeOne@Example.test')->assertOk()->assertJsonPath('data.status', 'authenticated');
    }

    public function test_unknown_account_and_wrong_password_are_indistinguishable(): void
    {
        User::factory()->patient()->create(['phone' => '+966512345678']);

        $wrongPassword = $this->login('+966512345678', 'WrongPassword1');
        $unknown = $this->login('+966598765432', 'WrongPassword1');

        $this->assertErrorCode($wrongPassword, 401, 'invalid_credentials');
        $this->assertErrorCode($unknown, 401, 'invalid_credentials');
        $this->assertSame($wrongPassword->json('error.message'), $unknown->json('error.message'));
    }

    public function test_repeated_failures_lock_the_account(): void
    {
        Event::fake([SuspiciousActivityDetected::class]);
        $user = User::factory()->patient()->create(['phone' => '+966512345678']);

        for ($i = 0; $i < 5; $i++) {
            $this->login('+966512345678', 'WrongPassword1')->assertUnauthorized();
        }

        $this->assertTrue($user->fresh()->isLocked());
        Event::assertDispatched(SuspiciousActivityDetected::class, fn ($e) => $e->type === SuspiciousActivityDetected::ACCOUNT_LOCKED);

        // Past the request throttle, but still inside the lockout window: even the right password fails.
        $this->travel(2)->minutes();
        $this->assertErrorCode($this->login('+966512345678'), 429, 'too_many_attempts');

        $this->travel(15)->minutes();
        $this->login('+966512345678')->assertOk();
    }

    public function test_suspended_accounts_cannot_sign_in(): void
    {
        User::factory()->patient()->suspended()->create(['phone' => '+966512345678']);

        $this->assertErrorCode($this->login('+966512345678'), 403, 'account_inactive');
    }

    public function test_existing_tokens_stop_working_when_an_account_is_suspended(): void
    {
        $user = User::factory()->patient()->create(['phone' => '+966512345678']);
        $token = $this->login('+966512345678')->json('data.access_token');

        $user->forceFill(['status' => 'suspended'])->save();

        $this->assertErrorCode($this->withToken($token)->getJson('/api/v1/me'), 403, 'account_inactive');
    }

    public function test_unverified_phone_must_be_verified_first(): void
    {
        User::factory()->patient()->unverified()->create(['phone' => '+966512345678']);

        $this->assertErrorCode($this->login('+966512345678'), 403, 'phone_not_verified');
    }

    public function test_sign_in_and_failures_are_audited(): void
    {
        $user = User::factory()->patient()->create(['phone' => '+966512345678']);

        $this->login('+966512345678', 'WrongPassword1');
        $this->login('+966512345678');

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'subject_id' => $user->id, 'outcome' => 'failure']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'subject_id' => $user->id, 'outcome' => 'success']);

        // Credentials never end up in audit metadata.
        AuditLog::all()->each(fn (AuditLog $log) => $this->assertStringNotContainsString('Password123', json_encode($log->getAttributes())));
    }

    public function test_new_device_sign_in_is_flagged(): void
    {
        Event::fake([SuspiciousActivityDetected::class]);
        User::factory()->patient()->create(['phone' => '+966512345678']);

        $this->login('+966512345678', extra: ['device_id' => 'device-a']); // first sign-in: never flagged
        $this->login('+966512345678', extra: ['device_id' => 'device-a']);
        Event::assertNotDispatched(SuspiciousActivityDetected::class);

        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
            ->postJson('/api/v1/auth/login', ['identifier' => '+966512345678', 'password' => 'Password123', 'device_id' => 'device-b'])
            ->assertOk();

        Event::assertDispatched(SuspiciousActivityDetected::class, fn ($e) => $e->type === SuspiciousActivityDetected::NEW_DEVICE);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        User::factory()->patient()->create(['phone' => '+966512345678']);
        $first = $this->login('+966512345678')->json('data.access_token');
        $second = $this->login('+966512345678')->json('data.access_token');

        $this->withToken($first)->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withToken($first)->getJson('/api/v1/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($second)->getJson('/api/v1/me')->assertOk();
    }

    public function test_logout_all_revokes_every_token(): void
    {
        User::factory()->patient()->create(['phone' => '+966512345678']);
        $first = $this->login('+966512345678')->json('data.access_token');
        $second = $this->login('+966512345678')->json('data.access_token');

        $this->withToken($first)->postJson('/api/v1/auth/logout-all')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withToken($second)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_expired_tokens_are_rejected(): void
    {
        User::factory()->patient()->create(['phone' => '+966512345678']);
        $token = $this->login('+966512345678')->json('data.access_token');

        $this->travel(config('medconnect.auth.token_ttl_minutes') + 1)->minutes();

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_tokens_are_stored_hashed(): void
    {
        User::factory()->patient()->create(['phone' => '+966512345678']);
        $token = $this->login('+966512345678')->json('data.access_token');
        [, $plain] = explode('|', $token, 2);

        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $plain]);
        $this->assertDatabaseHas('personal_access_tokens', ['token' => hash('sha256', $plain)]);
    }
}
