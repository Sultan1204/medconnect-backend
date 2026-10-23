<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\OtpCode;
use App\Modules\Identity\Models\User;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'account_type' => 'patient',
            'name' => 'Synthetic Patient',
            'phone' => '0512345678',
            'password' => 'Secret12345',
            'password_confirmation' => 'Secret12345',
            'preferred_locale' => 'en',
        ], $overrides);
    }

    public function test_patient_registers_and_receives_sms_code(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'verification_pending');

        $user = User::where('phone', '+966512345678')->firstOrFail();
        $this->assertTrue($user->hasRole('patient'));
        $this->assertNull($user->phone_verified_at);
        $this->assertNotNull($this->sms()->lastCodeFor('+966512345678'));
    }

    public function test_otp_and_destination_are_never_stored_in_plaintext(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(202);
        $code = $this->sms()->lastCodeFor('+966512345678');

        $row = OtpCode::query()->firstOrFail()->getAttributes();
        foreach ($row as $value) {
            $this->assertNotSame($code, $value);
            $this->assertStringNotContainsString('512345678', (string) $value);
        }
    }

    public function test_verifying_the_code_signs_the_patient_in(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(202);
        $code = $this->sms()->lastCodeFor('+966512345678');

        $response = $this->postJson('/api/v1/auth/verify-otp', [
            'phone' => '+966512345678', 'code' => $code, 'device_name' => 'Test phone', 'platform' => 'android',
        ])->assertOk()
            ->assertJsonPath('data.status', 'authenticated')
            ->assertJsonPath('data.user.phone_verified', true)
            ->assertJsonPath('data.user.roles.0', 'patient');

        $this->withToken($response->json('data.access_token'))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.phone', '+966512345678');
    }

    public function test_demo_fixed_code_is_accepted_only_when_configured(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(202);

        $this->assertErrorCode($this->postJson('/api/v1/auth/verify-otp', ['phone' => '+966512345678', 'code' => '123456']), 422, 'invalid_otp');

        config(['medconnect.demo_fixed_code' => '123456']);
        $this->postJson('/api/v1/auth/verify-otp', ['phone' => '+966512345678', 'code' => '123456'])->assertOk();
    }

    public function test_wrong_code_is_rejected_and_attempts_are_limited(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(202);
        $code = $this->sms()->lastCodeFor('+966512345678');
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->assertErrorCode($this->postJson('/api/v1/auth/verify-otp', ['phone' => '+966512345678', 'code' => $wrong]), 422, 'invalid_otp');
            // Stay under the per-minute endpoint throttle; this test targets the per-code limit.
            $this->travel(13)->seconds();
        }

        // The code is exhausted even though it is now correct.
        $this->assertErrorCode($this->postJson('/api/v1/auth/verify-otp', ['phone' => '+966512345678', 'code' => $code]), 422, 'invalid_otp');
    }

    public function test_expired_code_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(202);
        $code = $this->sms()->lastCodeFor('+966512345678');

        $this->travel(config('medconnect.otp.ttl_seconds') + 1)->seconds();

        $this->assertErrorCode($this->postJson('/api/v1/auth/verify-otp', ['phone' => '+966512345678', 'code' => $code]), 422, 'invalid_otp');
    }

    public function test_registering_an_existing_number_does_not_reveal_the_account(): void
    {
        User::factory()->patient()->create(['phone' => '+966512345678', 'name' => 'Original Owner']);

        $first = $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(202);
        $fresh = $this->postJson('/api/v1/auth/register', $this->payload(['phone' => '0598765432']))->assertStatus(202);

        $this->assertSame($fresh->json(), $first->json());
        $this->assertSame(1, User::where('phone', '+966512345678')->count());
        $this->assertSame('Original Owner', User::where('phone', '+966512345678')->value('name'));

        // The owner gets a notice, not a usable code.
        $this->assertCount(1, $this->sms()->sentTo('+966512345678'));
        $this->assertNull($this->sms()->lastCodeFor('+966512345678'));
    }

    public function test_unverified_registration_can_be_reclaimed_by_the_number_owner(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['name' => 'Squatter']))->assertStatus(202);
        $this->travel(2)->minutes();
        $this->postJson('/api/v1/auth/register', $this->payload(['name' => 'Real Owner']))->assertStatus(202);

        $this->assertSame(1, User::where('phone', '+966512345678')->count());
        $this->assertSame('Real Owner', User::where('phone', '+966512345678')->value('name'));
    }

    public function test_resend_respects_the_cooldown(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(202);
        $this->postJson('/api/v1/auth/otp/resend', ['phone' => '+966512345678'])->assertStatus(202);
        $this->assertCount(1, $this->sms()->sentTo('+966512345678'));

        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/otp/resend', ['phone' => '+966512345678'])->assertStatus(202);
        $this->assertCount(2, $this->sms()->sentTo('+966512345678'));
    }

    public function test_only_the_latest_code_is_valid(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(202);
        $first = $this->sms()->lastCodeFor('+966512345678');
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/otp/resend', ['phone' => '+966512345678'])->assertStatus(202);
        $second = $this->sms()->lastCodeFor('+966512345678');

        if ($first !== $second) {
            $this->assertErrorCode($this->postJson('/api/v1/auth/verify-otp', ['phone' => '+966512345678', 'code' => $first]), 422, 'invalid_otp');
        }
        $this->postJson('/api/v1/auth/verify-otp', ['phone' => '+966512345678', 'code' => $second])->assertOk();
    }

    public function test_doctor_registration_requires_mfa_enrolment_before_access(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['account_type' => 'doctor']))->assertStatus(202);
        $code = $this->sms()->lastCodeFor('+966512345678');

        $response = $this->postJson('/api/v1/auth/verify-otp', ['phone' => '+966512345678', 'code' => $code])
            ->assertOk()
            ->assertJsonPath('data.status', 'mfa_enrollment_required')
            ->assertJsonMissingPath('data.access_token');

        $this->assertErrorCode(
            $this->withToken($response->json('data.mfa_token'))->getJson('/api/v1/me'),
            403, 'mfa_enrollment_required',
        );
    }

    public function test_administrative_roles_cannot_self_register(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['account_type' => 'admin']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_weak_passwords_are_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['password']]]]);
    }

    public function test_invalid_phone_numbers_are_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['phone' => '12']))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['phone']]]]);
    }
}
