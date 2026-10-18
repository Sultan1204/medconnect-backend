<?php

namespace App\Modules\Scheduling\Search;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Search\DoctorAvailabilityFilter;
use App\Modules\Scheduling\Models\AvailabilityRule;
use App\Modules\Scheduling\Models\AvailabilitySlot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Search pre-filter for "available on <date>": doctors with a weekly rule
 * for that weekday (valid on the date) or an extra availability window
 * that day. Exact free slots are computed by GET /doctors/{id}/availability,
 * so a listed doctor may still turn out to be fully booked.
 */
class ScheduledAvailabilityFilter extends DoctorAvailabilityFilter
{
    /**
     * @param  Builder<Doctor>  $query
     */
    public function apply(Builder $query, string $date, ?string $consultationType): void
    {
        $tz = (string) config('medconnect.display_timezone');
        $day = CarbonImmutable::parse($date, $tz);

        $rules = AvailabilityRule::query()
            ->select('doctor_id')
            ->where('day_of_week', (int) $day->format('w'))
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date))
            ->when($consultationType, fn ($q) => $q->whereJsonContains('consultation_types', $consultationType));

        $extra = AvailabilitySlot::query()
            ->select('doctor_id')
            ->where('type', AvailabilitySlot::AVAILABLE)
            ->where('starts_at', '<', $day->endOfDay()->utc())
            ->where('ends_at', '>', $day->startOfDay()->utc())
            ->when($consultationType, fn ($q) => $q->whereJsonContains('consultation_types', $consultationType));

        $query->where(fn (Builder $q) => $q->whereIn('id', $rules)->orWhereIn('id', $extra));
    }
}
