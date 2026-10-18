<?php

namespace App\Modules\Administration\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Suspension and reactivation of accounts, with guard rails on who may act on whom.
 */
class UserAdministration
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function suspend(User $target, User $actor, string $reason): User
    {
        $this->guard($target, $actor);

        return DB::transaction(function () use ($target, $actor, $reason) {
            /** @var User $locked */
            $locked = User::whereKey($target->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== UserStatus::Active) {
                throw ApiException::conflict('invalid_state_transition', 'Only active accounts can be suspended.');
            }

            $locked->forceFill(['status' => UserStatus::Suspended, 'status_changed_at' => now()])->save();
            // Take effect immediately: every session is revoked.
            $locked->tokens()->delete();

            $this->audit->record('users.suspended', $locked, ['actor' => $actor, 'changes' => ['status' => ['active', 'suspended']], 'metadata' => ['reason' => $reason]]);

            return $locked;
        });
    }

    public function reactivate(User $target, User $actor): User
    {
        $this->guard($target, $actor);

        return DB::transaction(function () use ($target, $actor) {
            /** @var User $locked */
            $locked = User::whereKey($target->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== UserStatus::Suspended) {
                throw ApiException::conflict('invalid_state_transition', 'Only suspended accounts can be reactivated.');
            }

            $locked->forceFill(['status' => UserStatus::Active, 'status_changed_at' => now(), 'failed_login_attempts' => 0, 'locked_until' => null])->save();
            $this->audit->record('users.reactivated', $locked, ['actor' => $actor, 'changes' => ['status' => ['suspended', 'active']]]);

            return $locked;
        });
    }

    private function guard(User $target, User $actor): void
    {
        if ($target->id === $actor->id) {
            throw ApiException::make('cannot_modify_self', 'You cannot change your own account status.');
        }

        if ($target->hasRole(RoleName::SuperAdmin->value)) {
            throw ApiException::forbidden('Super administrator accounts cannot be modified here.');
        }

        if ($target->hasRole(RoleName::Admin->value) && ! $actor->hasRole(RoleName::SuperAdmin->value)) {
            throw ApiException::forbidden('Only a super administrator can change an administrator account.');
        }
    }
}
