<?php

namespace App\Modules\Administration\Policies;

use App\Modules\Administration\Models\Complaint;
use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Models\User;

class ComplaintPolicy
{
    /** The complainant only. Administrators use the admin endpoints, which are audited. */
    public function view(User $user, Complaint $complaint): bool
    {
        return $complaint->complainant_user_id === $user->id;
    }

    public function manage(User $user, Complaint $complaint): bool
    {
        return $user->can(Permission::ComplaintsManage->value);
    }
}
