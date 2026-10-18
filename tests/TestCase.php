<?php

namespace Tests;

use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Contracts\SmsProvider;
use App\Modules\Notifications\Sms\FakeSmsProvider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Roles and permissions are reference data every test needs. */
    protected bool $seed = true;

    protected string $seeder = RolesAndPermissionsSeeder::class;

    protected function sms(): FakeSmsProvider
    {
        /** @var FakeSmsProvider */
        return app(SmsProvider::class);
    }

    /**
     * Creates a user with the given role and authenticates with a full-access token.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function actingAsRole(RoleName $role, array $attributes = []): User
    {
        $user = User::factory()->role($role)->create($attributes);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    protected function assertErrorCode(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status)->assertJsonPath('error.code', $code);
    }
}
