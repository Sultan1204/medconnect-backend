<?php

namespace App\Modules\Administration\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Models\User;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Permission management for administrators (super administrators only).
 * Direct permissions are granted on top of the role defaults.
 *
 * @tags Admin - Administrators
 */
class AdministratorController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * All permissions with their defaults and whether they are super-admin only.
     */
    public function permissions(): JsonResponse
    {
        $defaults = Permission::defaultsByRole()[RoleName::Admin->value];

        return response()->json(['data' => collect(Permission::cases())->map(fn (Permission $p) => [
            'name' => $p->value,
            'super_admin_only' => $p->isSuperAdminOnly(),
            'admin_default' => in_array($p->value, $defaults, true),
        ])->values()]);
    }

    /**
     * Administrator accounts and their extra (direct) permissions.
     */
    public function index(): JsonResponse
    {
        $admins = User::query()->role([RoleName::Admin->value, RoleName::SuperAdmin->value])
            ->with(['roles', 'permissions'])->orderBy('name')->orderBy('id')->get();

        return response()->json(['data' => $admins->map(fn (User $u) => $this->present($u))->values()]);
    }

    /**
     * Replace an administrator's extra permissions. Requires step-up MFA.
     */
    public function syncPermissions(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::enum(Permission::class)],
        ]);

        if ($user->id === $request->user()->id) {
            throw ApiException::make('cannot_modify_self', 'You cannot change your own permissions.');
        }

        if (! $user->hasRole(RoleName::Admin->value) || $user->hasRole(RoleName::SuperAdmin->value)) {
            throw ApiException::make('invalid_target', 'Permissions can only be managed for administrator accounts.');
        }

        $requested = collect($data['permissions'])->unique()->values();

        if ($requested->contains(fn (string $p) => Permission::from($p)->isSuperAdminOnly())) {
            throw ApiException::make('permission_not_grantable', 'Some permissions can only be held by super administrators.');
        }

        $before = $user->getDirectPermissions()->pluck('name');
        $user->syncPermissions($requested->all());
        // The target's tokens keep working; permissions are checked on each request.

        $this->audit->record('admins.permissions_changed', $user, ['changes' => [
            'added' => $requested->diff($before)->values(), 'removed' => $before->diff($requested)->values(),
        ]]);

        return response()->json(['data' => $this->present($user->refresh()->load(['roles', 'permissions']))]);
    }

    /** @return array<string, mixed> */
    private function present(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'roles' => $u->roles->pluck('name')->values(),
            'extra_permissions' => $u->permissions->pluck('name')->sort()->values(),
            'status' => $u->status->value,
        ];
    }
}
