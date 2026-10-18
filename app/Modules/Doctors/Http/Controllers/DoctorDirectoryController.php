<?php

namespace App\Modules\Doctors\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Doctors\Http\Requests\DoctorSearchRequest;
use App\Modules\Doctors\Http\Resources\PublicDoctorResource;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Search\DoctorAvailabilityFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/**
 * Public doctor discovery. Only approved doctors with a valid licence,
 * an active account and at least one offered consultation type appear.
 *
 * @tags Doctors - Directory
 */
class DoctorDirectoryController extends Controller
{
    /**
     * Search doctors.
     *
     * Filter by specialty (UUID or slug), name, language, consultation type,
     * gender, price range (halalas) and availability date. Sort by `price`,
     * `-price`, `-rating`, `-experience` or `name`.
     *
     * @unauthenticated
     */
    public function index(DoctorSearchRequest $request, DoctorAvailabilityFilter $availability): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $type = $filters['consultation_type'] ?? null;

        $query = Doctor::query()->publiclyListed()->with('specialties');

        if (isset($filters['q'])) {
            $term = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('display_name_en', 'ilike', $term)->orWhere('display_name_ar', 'ilike', $term));
        }

        if (isset($filters['specialty'])) {
            $column = Str::isUuid($filters['specialty']) ? 'specialties.id' : 'specialties.slug';
            $query->whereHas('specialties', fn (Builder $q) => $q->where($column, $filters['specialty'])->where('specialties.is_active', true));
        }

        if (isset($filters['language'])) {
            $query->whereJsonContains('languages', $filters['language']);
        }

        if (isset($filters['gender'])) {
            $query->where('gender', $filters['gender']);
        }

        if ($type !== null) {
            $query->whereNotNull("{$type}_fee_amount");
        }

        if (isset($filters['min_price']) || isset($filters['max_price'])) {
            $min = $filters['min_price'] ?? 0;
            $max = $filters['max_price'] ?? PHP_INT_MAX;
            $columns = $type !== null ? ["{$type}_fee_amount"] : ['video_fee_amount', 'chat_fee_amount'];

            $query->where(function (Builder $q) use ($columns, $min, $max) {
                foreach ($columns as $column) {
                    $q->orWhereBetween($column, [$min, $max]);
                }
            });
        }

        if (isset($filters['available_on'])) {
            $availability->apply($query, $filters['available_on'], $type);
        }

        $priceExpression = $type !== null
            ? "{$type}_fee_amount"
            : 'LEAST(COALESCE(video_fee_amount, 2147483647), COALESCE(chat_fee_amount, 2147483647))';

        match ($filters['sort'] ?? '-rating') {
            'price' => $query->orderByRaw("{$priceExpression} ASC"),
            '-price' => $query->orderByRaw("{$priceExpression} DESC"),
            '-experience' => $query->orderByRaw('years_of_experience DESC NULLS LAST'),
            'name' => $query->orderBy('display_name_en'),
            default => $query->orderByRaw('rating_average DESC NULLS LAST')->orderByDesc('rating_count'),
        };

        return PublicDoctorResource::collection($query->orderBy('id')->paginate($filters['per_page'] ?? 20)->withQueryString());
    }

    /**
     * Public doctor profile.
     *
     * Returns 404 for doctors who are not currently listed.
     *
     * @unauthenticated
     */
    public function show(string $doctor): PublicDoctorResource
    {
        return new PublicDoctorResource(Doctor::publiclyListed()->with('specialties')->findOrFail($doctor));
    }
}
