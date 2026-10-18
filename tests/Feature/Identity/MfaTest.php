<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\MfaService;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MfaTest extends TestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private function totp(string $secret = self::SECRET): string
    {
        return app(MfaService::class)->currentCode($secret);
    }

    private function mfaToken(string $phone): string
    {
        return $this->postJson('/api/v1/auth/login', ['identifier' => $phone, 'password' => 'Password123'])
            ->assertOk()
            ->assertJsonPath('data.status', 'mfa_required')
            ->json('data.mfa_token');
    }

    public function test_doctor_with_mfa_must_complete_the_challenge(): void
    {
        User::factory()->doctor()->withMfa(self::SECRET)->create(['phone' => '+966512345678']);
        $mfaToken = $this->mfaToken('+966512345678');

        $this->assertErrorCode($this->withToken($mfaToken)->getJson('/api/v1/me'), 403, 'mfa_required');

        $token = $this->withToken($mfaToken)
            ->postJson('/api/v1/auth/mfa/challenge', ['code' => $this->totp()])
            ->assertOk()
            ->assertJsonPath('data.status', 'authenticated')
            ->json('data.access_token');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.mfa.enabled', true);

        // The challenge token is single-use.
        $this->app['auth']->forgetGuards();
        $this->withToken($mfaToken)->postJson('/api/v1/auth/mfa/challenge', ['code' => $this->totp()])->assertUnauthorized();
    }

    public function test_a_totp_code_cannot_be_replayed(): void
    {
        User::factory()->doctor()->withMfa(self::SECRET)->create(['phone' => '+966512345678']);
        $code = $this->totp();

        $this->withToken($this->mfaToken('+966512345678'))->postJson('/api/v1/auth/mfa/challenge', ['code' => $code])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->assertErrorCode(
            $this->withToken($this->mfaToken('+966512345678'))->postJson('/api/v1/auth/mfa/challenge', ['code' => $code]),
            422, 'invalid_mfa_code',
        );
    }

    public function test_wrong_code_is_rejected(): void
    {
        User::factory()->doctor()->withMfa(self::SECRET)->create(['phone' => '+966512345678']);
        $wrong = $this->totp() === '123456' ? '654321' : '123456';

        $this->assertErrorCode(
            $this->withToken($this->mfaToken('+966512345678'))->postJson('/api/v1/auth/mfa/challenge', ['code' => $wrong]),
            422, 'invalid_mfa_code',
        );
    }

    public function test_full_tokens_cannot_be_used_on_the_challenge_endpoint(): void
    {
        $this->actingAsRole(RoleName::Patient);

        $this->assertErrorCode($this->postJson('/api/v1/auth/mfa/challenge', ['code' => '123456']), 403, 'already_authenticated');
    }

    /**
     * TOTP uses the real clock (not Carbon test time), so instead of waiting
     * for the next 30-second step we forget the last accepted step.
     */
    private function allowCodeReuse(User $user): void
    {
        $user->forceFill(['mfa_last_timestep' => null])->save();
    }

    public function test_doctor_enrols_in_mfa_and_receives_recovery_codes(): void
    {
        User::factory()->doctor()->create(['phone' => '+966512345678']);

        $enrolToken = $this->postJson('/api/v1/auth/login', ['identifier' => '+966512345678', 'password' => 'Password123'])
            ->assertJsonPath('data.status', 'mfa_enrollment_required')
            ->json('data.mfa_token');

        $secret = $this->withToken($enrolToken)->postJson('/api/v1/auth/mfa/setup')
            ->assertOk()
            ->assertJsonStructure(['data' => ['secret', 'otpauth_url']])
            ->json('data.secret');

        $response = $this->withToken($enrolToken)
            ->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->totp($secret)])
            ->assertOk()
            ->assertJsonPath('data.status', 'mfa_enabled')
            ->assertJsonCount(8, 'data.recovery_codes')
            ->assertJsonPath('data.session.status', 'authenticated');

        $this->app['auth']->forgetGuards();
        $this->withToken($response->json('data.session.access_token'))->getJson('/api/v1/me')->assertOk();

        // Secret is encrypted at rest.
        $this->assertDatabaseMissing('users', ['mfa_secret' => $secret]);
    }

    public function test_recovery_code_works_exactly_once(): void
    {
        $user = User::factory()->doctor()->create(['phone' => '+966512345678']);
        $mfa = app(MfaService::class);
        $secret = $mfa->beginEnrollment($user)['secret'];
        $codes = $mfa->confirmEnrollment($user, $mfa->currentCode($secret));

        $this->withToken($this->mfaToken('+966512345678'))
            ->postJson('/api/v1/auth/mfa/challenge', ['recovery_code' => strtoupper($codes[0])])
            ->assertOk();
        $this->app['auth']->forgetGuards();

        $this->assertErrorCode(
            $this->withToken($this->mfaToken('+966512345678'))->postJson('/api/v1/auth/mfa/challenge', ['recovery_code' => $codes[0]]),
            422, 'invalid_mfa_code',
        );
    }

    public function test_mfa_cannot_be_disabled_for_privileged_roles(): void
    {
        $user = User::factory()->doctor()->create();
        $mfa = app(MfaService::class);
        $secret = $mfa->beginEnrollment($user)['secret'];
        $mfa->confirmEnrollment($user, $mfa->currentCode($secret));
        $this->allowCodeReuse($user);

        Sanctum::actingAs($user->fresh(), ['*']);

        $this->assertErrorCode(
            $this->deleteJson('/api/v1/auth/mfa', ['password' => 'Password123', 'code' => $mfa->currentCode($secret)]),
            403, 'mfa_mandatory',
        );
    }

    public function test_patient_may_opt_in_and_out_of_mfa(): void
    {
        $user = $this->actingAsRole(RoleName::Patient);

        $secret = $this->postJson('/api/v1/auth/mfa/setup')->assertOk()->json('data.secret');
        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->totp($secret)])->assertOk()->assertJsonMissingPath('data.session');
        $this->allowCodeReuse($user);

        $this->deleteJson('/api/v1/auth/mfa', ['password' => 'Password123', 'code' => $this->totp($secret)])->assertOk();
        $this->assertFalse($user->fresh()->hasMfaEnabled());
    }
}
