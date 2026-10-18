<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Mail\OtpCodeMail;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccountAndDevicesTest extends TestCase
{
    private function token(string $phone, string $device): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'identifier' => $phone, 'password' => 'Password123', 'device_name' => $device, 'platform' => 'ios',
        ])->json('data.access_token');
    }

    public function test_user_lists_and_revokes_own_devices(): void
    {
        User::factory()->patient()->create(['phone' => '+966512345678']);
        $phone = $this->token('+966512345678', 'iPhone');
        $tablet = $this->token('+966512345678', 'iPad');

        $devices = $this->withToken($phone)->getJson('/api/v1/auth/devices')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->json('data');

        $ipad = collect($devices)->firstWhere('name', 'iPad');
        $this->assertFalse($ipad['is_current']);

        $this->withToken($phone)->deleteJson('/api/v1/auth/devices/'.$ipad['id'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($tablet)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_user_cannot_revoke_another_users_device(): void
    {
        User::factory()->patient()->create(['phone' => '+966512345678']);
        User::factory()->patient()->create(['phone' => '+966598765432']);
        $mine = $this->token('+966512345678', 'Mine');
        $theirs = $this->token('+966598765432', 'Theirs');

        $theirId = $this->withToken($theirs)->getJson('/api/v1/auth/devices')->json('data.0.id');
        $this->app['auth']->forgetGuards();

        $this->assertErrorCode($this->withToken($mine)->deleteJson('/api/v1/auth/devices/'.$theirId), 404, 'not_found');
    }

    public function test_changing_email_requires_reverification(): void
    {
        Mail::fake();
        $user = $this->actingAsRole(RoleName::Patient);

        $this->patchJson('/api/v1/me', ['email' => 'New.Address@Example.test'])
            ->assertOk()
            ->assertJsonPath('data.email', 'new.address@example.test')
            ->assertJsonPath('data.email_verified', false);

        $code = null;
        Mail::assertSent(OtpCodeMail::class, function ($mail) use (&$code) {
            preg_match('/\b(\d{6})\b/', $mail->text, $m);
            $code = $m[1] ?? null;

            return true;
        });

        $this->postJson('/api/v1/me/email/verify', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.email_verified', true);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_deactivation_requires_password_and_revokes_access(): void
    {
        $user = User::factory()->patient()->create(['phone' => '+966512345678']);
        $token = $this->token('+966512345678', 'Phone');

        $this->assertErrorCode($this->withToken($token)->postJson('/api/v1/me/deactivate', ['password' => 'wrong']), 422, 'invalid_password');

        $this->withToken($token)->postJson('/api/v1/me/deactivate', ['password' => 'Password123'])->assertOk();

        $this->assertSame('deactivated', $user->fresh()->status->value);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertErrorCode($this->postJson('/api/v1/auth/login', ['identifier' => '+966512345678', 'password' => 'Password123']), 403, 'account_inactive');
    }

    public function test_account_resource_never_exposes_secrets(): void
    {
        $this->actingAsRole(RoleName::Patient, ['mfa_secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', 'mfa_enabled_at' => now()]);

        $body = $this->getJson('/api/v1/me')->assertOk()->getContent();

        foreach (['password', 'mfa_secret', 'recovery', 'JBSWY3DP'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }
}
