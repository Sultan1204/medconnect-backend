<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AvailabilityRule;
use App\Modules\Scheduling\Models\AvailabilitySlot;
use App\Modules\Scheduling\Models\DoctorScheduleSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Computes bookable slots from weekly rules and date-specific overrides,
 * minus breaks, time off, existing appointments (plus buffer), the minimum
 * notice period, the booking horizon and daily caps.
 *
 * Rules are interpreted in the display time zone (Asia/Riyadh); slots are
 * returned as absolute instants.
 */
class AvailabilityService
{
    /**
     * @return list<Slot>
     */
    public function slots(Doctor $doctor, CarbonImmutable $fromDate, CarbonImmutable $toDate, ?string $consultationType = null): array
    {
        $tz = (string) config('medconnect.display_timezone');
        $settings = DoctorScheduleSettings::for($doctor);

        $from = $fromDate->setTimezone($tz)->startOfDay();
        $to = $toDate->setTimezone($tz)->endOfDay();

        $now = CarbonImmutable::now();
        $earliest = $now->addMinutes($settings->min_notice_minutes);
        $horizon = $now->setTimezone($tz)->startOfDay()->addDays($settings->max_days_ahead)->endOfDay();

        if ($to > $horizon) {
            $to = $horizon;
        }

        if ($from > $to) {
            return [];
        }

        $rules = AvailabilityRule::where('doctor_id', $doctor->id)->get();

        $overrides = AvailabilitySlot::where('doctor_id', $doctor->id)
            ->where('starts_at', '<', $to->utc())
            ->where('ends_at', '>', $from->utc())
            ->get();

        $blocked = $overrides->where('type', AvailabilitySlot::BLOCKED);

        $appointments = Appointment::query()->active()
            ->where('doctor_id', $doctor->id)
            ->where('starts_at', '<', $to->utc()->addMinutes($settings->buffer_minutes))
            ->where('ends_at', '>', $from->utc()->subMinutes($settings->buffer_minutes))
            ->get(['starts_at', 'ends_at']);

        $slots = [];

        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $candidates = [];

            foreach ($rules as $rule) {
                if (! $rule->appliesOn($day)) {
                    continue;
                }

                $start = $day->setTimeFromTimeString($rule->start_time);
                $end = $day->setTimeFromTimeString($rule->end_time);
                $breaks = array_map(fn (array $b) => [$day->setTimeFromTimeString($b['start']), $day->setTimeFromTimeString($b['end'])], $rule->breaks);

                array_push($candidates, ...$this->split($start, $end, $rule->slot_minutes, $settings->buffer_minutes, $rule->consultation_types, $breaks));
            }

            foreach ($overrides->where('type', AvailabilitySlot::AVAILABLE) as $extra) {
                $start = CarbonImmutable::instance($extra->starts_at)->setTimezone($tz);
                if ($start->toDateString() !== $day->toDateString()) {
                    continue;
                }

                array_push($candidates, ...$this->split($start, CarbonImmutable::instance($extra->ends_at)->setTimezone($tz),
                    (int) $extra->slot_minutes, $settings->buffer_minutes, $extra->consultation_types ?? [], []));
            }

            $dayAppointments = $appointments->filter(fn (Appointment $a) => CarbonImmutable::instance($a->starts_at)->setTimezone($tz)->toDateString() === $day->toDateString());
            if ($settings->max_daily_appointments !== null && $dayAppointments->count() >= $settings->max_daily_appointments) {
                continue;
            }

            foreach ($this->dedupe($candidates) as $slot) {
                if ($slot->startsAt < $earliest || $slot->startsAt > $horizon) {
                    continue;
                }

                if ($consultationType !== null && ! in_array($consultationType, $slot->consultationTypes, true)) {
                    continue;
                }

                if ($this->overlapsAny($slot, $blocked, 0) || $this->overlapsAny($slot, $appointments, $settings->buffer_minutes)) {
                    continue;
                }

                $slots[] = $slot;
            }
        }

        usort($slots, fn (Slot $a, Slot $b) => $a->startsAt <=> $b->startsAt);

        return $slots;
    }

    /**
     * The bookable slot starting exactly at $startsAt for $type, if any.
     */
    public function find(Doctor $doctor, CarbonImmutable $startsAt, string $type): ?Slot
    {
        $day = $startsAt->setTimezone((string) config('medconnect.display_timezone'));

        foreach ($this->slots($doctor, $day, $day, $type) as $slot) {
            if ($slot->startsAt->equalTo($startsAt)) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $types
     * @param  list<array{CarbonImmutable, CarbonImmutable}>  $breaks
     * @return list<Slot>
     */
    private function split(CarbonImmutable $start, CarbonImmutable $end, int $minutes, int $buffer, array $types, array $breaks): array
    {
        $slots = [];

        for ($t = $start; $t->addMinutes($minutes) <= $end; $t = $t->addMinutes($minutes + $buffer)) {
            $slot = new Slot($t, $t->addMinutes($minutes), array_values($types));

            foreach ($breaks as [$breakStart, $breakEnd]) {
                if ($slot->overlaps($breakStart, $breakEnd)) {
                    continue 2;
                }
            }

            $slots[] = $slot;
        }

        return $slots;
    }

    /**
     * Overlapping rules for the same start collapse into one slot offering the union of types.
     *
     * @param  list<Slot>  $slots
     * @return list<Slot>
     */
    private function dedupe(array $slots): array
    {
        $byStart = [];

        foreach ($slots as $slot) {
            $key = $slot->startsAt->getTimestamp();
            $byStart[$key] = isset($byStart[$key])
                ? new Slot($slot->startsAt, $slot->endsAt, array_values(array_unique([...$byStart[$key]->consultationTypes, ...$slot->consultationTypes])))
                : $slot;
        }

        return array_values($byStart);
    }

    /**
     * @param  Collection<int, covariant \Illuminate\Database\Eloquent\Model>  $intervals  models with starts_at / ends_at
     */
    private function overlapsAny(Slot $slot, Collection $intervals, int $bufferMinutes): bool
    {
        foreach ($intervals as $interval) {
            $start = CarbonImmutable::instance($interval->getAttribute('starts_at'))->subMinutes($bufferMinutes);
            $end = CarbonImmutable::instance($interval->getAttribute('ends_at'))->addMinutes($bufferMinutes);

            if ($slot->overlaps($start, $end)) {
                return true;
            }
        }

        return false;
    }
}
