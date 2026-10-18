<?php

namespace App\Modules\Doctors\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Doctors\Enums\VerificationStatus;
use App\Modules\Doctors\Http\Requests\StoreCredentialRequest;
use App\Modules\Doctors\Http\Resources\CredentialResource;
use App\Modules\Doctors\Models\DoctorCredential;
use App\Support\Exceptions\ApiException;
use App\Support\Files\PrivateFileStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Professional documents supporting verification.
 *
 * @tags Doctors - Credentials
 */
class CredentialController extends Controller
{
    public function __construct(
        private readonly PrivateFileStore $files,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * List my credential documents.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return CredentialResource::collection(DoctorProfileController::own($request->user())->credentials()->latest()->get());
    }

    /**
     * Upload a credential document.
     *
     * Multipart upload; PDF, JPEG or PNG up to 10 MB. Files are virus-scanned
     * and stored in private storage.
     */
    public function store(StoreCredentialRequest $request): CredentialResource
    {
        $doctor = DoctorProfileController::own($request->user());
        $stored = $this->files->store($request->file('file'), "doctors/{$doctor->id}/credentials");

        $credential = $doctor->credentials()->create([
            ...$request->safe()->except('file'),
            'file_path' => $stored->path,
            'file_name' => $stored->originalName,
            'mime_type' => $stored->mimeType,
            'size_bytes' => $stored->size,
            'sha256' => $stored->sha256,
        ]);

        $this->audit->record('doctors.credential_uploaded', $credential, ['metadata' => ['type' => $credential->type->value]]);

        return new CredentialResource($credential);
    }

    /**
     * Get a short-lived download link for one of my documents.
     */
    public function download(Request $request, string $credential): JsonResponse
    {
        $doc = DoctorProfileController::own($request->user())->credentials()->findOrFail($credential);

        return self::downloadLink($this->files, $doc);
    }

    /**
     * Remove a document.
     *
     * Not allowed for accepted documents or while the profile is under review.
     */
    public function destroy(Request $request, string $credential): JsonResponse
    {
        $doctor = DoctorProfileController::own($request->user());
        /** @var DoctorCredential $doc */
        $doc = $doctor->credentials()->findOrFail($credential);

        if ($doc->review_status === 'accepted' || $doctor->verification_status === VerificationStatus::UnderReview) {
            throw ApiException::conflict('credential_locked', 'This document can no longer be removed.');
        }

        // Soft delete: the file is retained for the verification audit trail.
        $doc->delete();
        $this->audit->record('doctors.credential_removed', $doc);

        return response()->json(['data' => ['status' => 'removed']]);
    }

    public static function downloadLink(PrivateFileStore $files, DoctorCredential $credential): JsonResponse
    {
        $minutes = (int) config('medconnect.files.download_link_minutes');

        app(AuditLogger::class)->record('doctors.credential_downloaded', $credential);

        return response()->json(['data' => [
            'url' => $files->temporaryUrl($credential->file_path, $credential->file_name, $minutes),
            'expires_at' => now()->addMinutes($minutes)->toIso8601ZuluString(),
        ]]);
    }
}
