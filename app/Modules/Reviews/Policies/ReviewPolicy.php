<?php

namespace App\Modules\Reviews\Policies;

use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Models\Review;

class ReviewPolicy
{
    /**
     * Anyone signed in may report a *published* review, except its author.
     */
    public function report(User $user, Review $review): bool
    {
        return $review->status === ReviewStatus::Published && $review->author_user_id !== $user->id;
    }

    public function moderate(User $user, Review $review): bool
    {
        return $user->can(Permission::ReviewsModerate->value);
    }
}
