<?php

namespace App\Modules\Doctors\Search;

use App\Modules\Doctors\Models\Doctor;
use Illuminate\Database\Eloquent\Builder;

/**
 * Narrows a doctor query to those with bookable time on a given date.
 *
 * Bound in the container so the Scheduling module can provide the real
 * implementation without Doctors depending on Scheduling.
 */
class DoctorAvailabilityFilter
{
    /**
     * @param  Builder<Doctor>  $query
     */
    public function apply(Builder $query, string $date, ?string $consultationType): void
    {
        // No scheduling data yet: nobody is available.
        $query->whereRaw('false');
    }
}
