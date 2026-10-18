<?php

namespace App\Modules\Specialties\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Specialties\Http\Requests\SpecialtyRequest;
use App\Modules\Specialties\Http\Resources\SpecialtyResource;
use App\Modules\Specialties\Models\Specialty;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

/**
 * @tags Specialties
 */
class SpecialtyController extends Controller
{
    public const CACHE_KEY = 'specialties:active';

    /**
     * List active medical specialties.
     *
     * Names are localized with `Accept-Language: ar|en`; both languages are always returned.
     *
     * @unauthenticated
     */
    public function index(): AnonymousResourceCollection
    {
        $specialties = Cache::remember(self::CACHE_KEY, 3600, fn () => Specialty::active()->orderBy('sort_order')->orderBy('name_en')->get());

        return SpecialtyResource::collection($specialties);
    }

    /**
     * List all specialties, including inactive (admin).
     */
    public function adminIndex(): AnonymousResourceCollection
    {
        return SpecialtyResource::collection(Specialty::orderBy('sort_order')->orderBy('name_en')->get());
    }

    /**
     * Create a specialty (admin).
     */
    public function store(SpecialtyRequest $request, AuditLogger $audit): SpecialtyResource
    {
        $specialty = Specialty::create($request->validated());
        $audit->record('specialties.created', $specialty);
        Cache::forget(self::CACHE_KEY);

        return new SpecialtyResource($specialty);
    }

    /**
     * Update a specialty (admin).
     *
     * Deactivating hides it from search; existing doctor links are kept.
     */
    public function update(SpecialtyRequest $request, Specialty $specialty, AuditLogger $audit): SpecialtyResource
    {
        $specialty->update($request->validated());
        $audit->record('specialties.updated', $specialty, ['changes' => AuditLogger::changedFields($specialty)]);
        Cache::forget(self::CACHE_KEY);

        return new SpecialtyResource($specialty);
    }
}
