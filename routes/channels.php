<?php

use App\Modules\Consultations\Models\Consultation;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channel authorisation  (POST /api/v1/broadcasting/auth)
|--------------------------------------------------------------------------
| Authorised server-side on every subscription. Payloads on these channels
| contain identifiers and status only - never clinical content.
*/

// Per-user notifications (in-app notification badges, appointment updates).
Broadcast::channel('user.{userId}', fn (User $user, string $userId) => $user->id === $userId);

// Consultation events: messages, receipts, status changes.
Broadcast::channel('consultation.{consultationId}', function (User $user, string $consultationId) {
    $consultation = Consultation::with(['doctor', 'patient'])->find($consultationId);

    return $consultation !== null && $consultation->participantRole($user) !== null;
});

// Presence (online / typing indicators): returns the member's public info.
Broadcast::channel('consultation.{consultationId}.presence', function (User $user, string $consultationId) {
    $consultation = Consultation::with(['doctor', 'patient'])->find($consultationId);
    $role = $consultation?->participantRole($user);

    return $role === null ? false : ['id' => $user->id, 'role' => $role, 'name' => $user->name];
});
