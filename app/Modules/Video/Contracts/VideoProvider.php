<?php

namespace App\Modules\Video\Contracts;

use App\Modules\Identity\Models\User;
use App\Modules\Video\Data\ParticipantCredentials;
use App\Modules\Video\Models\VideoSession;
use Carbon\CarbonImmutable;

/**
 * Provider-independent WebRTC integration point.
 *
 * Implementations MUST:
 *  - never enable recording (the platform does not record consultations);
 *  - issue credentials scoped to a single room, a single participant and a
 *    short lifetime;
 *  - only be enabled for real patients after a security, privacy and data-
 *    residency assessment of the provider (see compliance register).
 */
interface VideoProvider
{
    public function name(): string;

    /**
     * Creates a room that closes no later than $closesAt.
     *
     * @return string provider room id
     */
    public function createRoom(string $consultationId, CarbonImmutable $closesAt): string;

    public function participantCredentials(VideoSession $session, User $user, string $role, CarbonImmutable $expiresAt): ParticipantCredentials;

    public function endRoom(VideoSession $session): void;
}
