<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Mail\OtpCodeMail;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    public function test_forgot_password_response_does_not_reveal_account_existence(): void
    {
        User::factory()->patient()->create(['phone' => '+966512345678']);

        $known = $this->postJson('/api/v1/auth/password/forgot', ['identifier' => '0512345678'])->assertStatus(202);
        $unknown = $this->postJson('/api/v1/auth/password/forgot', ['identifier' => '0598765432'])->assertStatus(202);

        $this->assertSame($known->json(), $unknown->json());
        $this->assertNotNull($this->sms()->lastCodeFor('+966512345678'));
        $this->assertSame([], $this->sms()->sentTo('+966598765432'));
    }

    public function test_reset_with_sms_code_changes_password_and_revokes_sessions(): void
    {
        $user = User::factory()->patient()->create(['phone' => '+966512345678']);
        $oldToken = $this->postJson('/api/v1/auth/login', ['identifier' => '+966512345678', 'password' => 'Password123'])->json('data.access_token');

        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => '+966512345678']);
        $code = $this->sms()->lastCodeFor('+966512345678');

        $this->postJson('/api/v1/auth/password/reset', [
            'identifier' => '+966512345678', 'code' => $code,
            'password' => 'BrandNew12345', 'password_confirmation' => 'BrandNew12345',
        ])->assertOk()->assertJsonPath('data.status', 'password_reset');

        $this->assertTrue(Hash::check('BrandNew12345', $user->fresh()->password));
        $this->app['auth']->forgetGuards();
        $this->withToken($oldToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_reset', 'subject_id' => $user->id]);
    }

    public function test_reset_by_email_sends_the_code_by_email(): void
    {
        Mail::fake();
        User::factory()->patient()->create(['email' => 'someone@example.test']);

        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => 'someone@example.test'])->assertStatus(202);

        Mail::assertSent(OtpCodeMail::class, fn ($mail) => $mail->hasTo('someone@example.test'));
    }

    public function test_unknown_account_and_wrong_code_fail_identically(): void
    {
        User::factory()->patient()->create(['phone' => '+966512345678']);
        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => '+966512345678']);
        $code = $this->sms()->lastCodeFor('+966512345678');
        $wrong = $code === '000000' ? '111111' : '000000';

        $payload = ['password' => 'BrandNew12345', 'password_confirmation' => 'BrandNew12345'];
        $wrongCode = $this->postJson('/api/v1/auth/password/reset', ['identifier' => '+966512345678', 'code' => $wrong] + $payload);
        $unknown = $this->postJson('/api/v1/auth/password/reset', ['identifier' => '+966598765432', 'code' => $wrong] + $payload);

        $this->assertErrorCode($wrongCode, 422, 'invalid_otp');
        $this->assertSame($wrongCode->json('error.message'), $unknown->json('error.message'));
        $this->assertSame($wrongCode->status(), $unknown->status());
    }

    public function test_reset_unlocks_a_locked_account(): void
    {
        $user = User::factory()->patient()->create(['phone' => '+966512345678', 'locked_until' => now()->addMinutes(10)]);
        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => '+966512345678']);

        $this->postJson('/api/v1/auth/password/reset', [
            'identifier' => '+966512345678', 'code' => $this->sms()->lastCodeFor('+966512345678'),
            'password' => 'BrandNew12345', 'password_confirmation' => 'BrandNew12345',
        ])->assertOk();

        $this->assertFalse($user->fresh()->isLocked());
    }

    public function test_change_password_requires_the_current_password_and_keeps_current_session(): void
    {
        User::factory()->patient()->create(['phone' => '+966512345678']);
        $current = $this->postJson('/api/v1/auth/login', ['identifier' => '+966512345678', 'password' => 'Password123'])->json('data.access_token');
        $other = $this->postJson('/api/v1/auth/login', ['identifier' => '+966512345678', 'password' => 'Password123'])->json('data.access_token');

        $this->assertErrorCode($this->withToken($current)->postJson('/api/v1/auth/password/change', [
            'current_password' => 'nope', 'password' => 'BrandNew12345', 'password_confirmation' => 'BrandNew12345',
        ]), 422, 'invalid_password');

        $this->withToken($current)->postJson('/api/v1/auth/password/change', [
            'current_password' => 'Password123', 'password' => 'BrandNew12345', 'password_confirmation' => 'BrandNew12345',
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($current)->getJson('/api/v1/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/me')->assertUnauthorized();
    }
}
