<?php

namespace App\Modules\Doctors\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Doctors\Http\Requests\UpdateDoctorProfileRequest;
use App\Modules\Doctors\Http\Resources\DoctorProfileResource;
use App\Modules\Doctors\Http\Resources\VerificationEventResource;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Services\DoctorVerificationService;
use App\Modules\Identity\Models\User;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in doctor's professional profile.
 *
 * @tags Doctors - My profile
 */
class DoctorProfileController extends Controller
{
    public function __construct(
        private readonly DoctorVerificationService $verification,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * My doctor profile.
     *
     * Includes verification status and, while not yet approved, the list of
     * requirements still missing before the profile can be submitted.
     */
    public function show(Request $request): DoctorProfileResource
    {
        return new DoctorProfileResource(self::own($request->user())->load('specialties'));
    }

    /**
     * Update my doctor profile.
     *
     * Changing licence details after approval returns the profile to
     * `under_review` and hides it from search until re-approved.
     */
    public function update(UpdateDoctorProfileRequest $request): DoctorProfileResource
    {
        $doctor = self::own($request->user());
        $data = $request->validated();

        if (isset($data['license_number']) && $this->licenseTaken($doctor, $data)) {
            throw ApiException::make('license_already_registered', 'This licence number is already registered to another account.');
        }

        DB::transaction(function () use ($doctor, $data, $request) {
            $doctor->fill(collect($data)->except('specialties')->all());
            $licenseChanged = $doctor->isDirty(Doctor::LICENSE_FIELDS);
            $doctor->save();

            if (array_key_exists('specialties', $data)) {
                $doctor->specialties()->sync($request->specialtySync());
            }

            $this->audit->record('doctors.profile_updated', $doctor, ['changes' => [
                'fields' => array_values(array_diff(array_keys($data), ['updated_at'])),
            ]]);

            if ($licenseChanged) {
                $this->verification->licenseChanged($doctor, $request->user());
            }
        });

        return new DoctorProfileResource($doctor->fresh()->load('specialties'));
    }

    /**
     * Submit my profile for verification.
     *
     * Requires licence details, at least one specialty, at least one
     * consultation fee and an uploaded professional licence document.
     */
    public function submit(Request $request): DoctorProfileResource
    {
        $doctor = $this->verification->submit(self::own($request->user()), $request->user());

        return new DoctorProfileResource($doctor->load('specialties'));
    }

    /**
     * My verification history.
     */
    public function history(Request $request): AnonymousResourceCollection
    {
        return VerificationEventResource::collection(self::own($request->user())->verificationHistory()->get());
    }

    public static function own(User $user): Doctor
    {
        return Doctor::where('user_id', $user->id)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function licenseTaken(Doctor $doctor, array $data): bool
    {
        return Doctor::query()
            ->whereKeyNot($doctor->id)
            ->where('license_number', $data['license_number'])
            ->where('licensing_authority', $data['licensing_authority'] ?? $doctor->licensing_authority)
            ->exists();
    }
}
