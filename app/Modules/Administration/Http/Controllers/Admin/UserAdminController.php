<?php

namespace App\Modules\Administration\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Http\Resources\AdminUserResource;
use App\Modules\Administration\Services\UserAdministration;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Account administration.
 *
 * @tags Admin - Users
 */
class UserAdminController extends Controller
{
    public function __construct(private readonly UserAdministration $users, private readonly AuditLogger $audit) {}

    /**
     * Search accounts. `q` matches name (contains) or an exact email/phone.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'role' => ['sometimes', Rule::enum(RoleName::class)],
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
            'q' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(['created_at', '-created_at', 'name', '-name', 'last_login_at', '-last_login_at'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $sort = $f['sort'] ?? '-created_at';
        $query = User::query()->with('roles')
            ->when($f['role'] ?? null, fn ($q, $v) => $q->role($v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['q'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '\\%_').'%';
                $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('email', mb_strtolower($term))->orWhere('phone', $term));
            })
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('id');

        $this->audit->record('users.listed', null, ['metadata' => ['filters' => array_keys($f)]]);

        return AdminUserResource::collection($query->paginate($f['per_page'] ?? 25));
    }

    /**
     * One account.
     */
    public function show(User $user): AdminUserResource
    {
        $this->audit->record('users.viewed', $user);

        return new AdminUserResource($user->load('roles'));
    }

    /**
     * Suspend an account and revoke its sessions. Requires step-up MFA.
     */
    public function suspend(Request $request, User $user): AdminUserResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return new AdminUserResource($this->users->suspend($user, $request->user(), $data['reason'])->load('roles'));
    }

    /**
     * Reactivate a suspended account. Requires step-up MFA.
     */
    public function reactivate(Request $request, User $user): AdminUserResource
    {
        return new AdminUserResource($this->users->reactivate($user, $request->user())->load('roles'));
    }
}
