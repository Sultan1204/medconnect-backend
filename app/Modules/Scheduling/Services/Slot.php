<?php

namespace App\Modules\Scheduling\Services;

use Carbon\CarbonImmutable;

final readonly class Slot
{
    /**
     * @param  list<string>  $consultationTypes
     */
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public array $consultationTypes,
    ) {}

    public function overlaps(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $this->startsAt < $end && $start < $this->endsAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $tz = (string) config('medconnect.display_timezone');

        return [
            'starts_at' => $this->startsAt->utc()->toIso8601ZuluString(),
            'ends_at' => $this->endsAt->utc()->toIso8601ZuluString(),
            'local_start' => $this->startsAt->setTimezone($tz)->format('Y-m-d\TH:i'),
            'duration_minutes' => (int) $this->startsAt->diffInMinutes($this->endsAt),
            'consultation_types' => $this->consultationTypes,
        ];
    }
}
