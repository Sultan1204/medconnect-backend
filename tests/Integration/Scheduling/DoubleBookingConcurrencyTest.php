<?php

namespace Tests\Integration\Scheduling;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Actions\BookAppointment;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AvailabilityRule;
use App\Support\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\IntegrationTestCase;
use Tests\Support\SchedulingFixtures;

/**
 * Proves double-booking protection under real concurrency, with committed
 * data, separate database sessions and separate PHP processes.
 */
class DoubleBookingConcurrencyTest extends IntegrationTestCase
{
    use SchedulingFixtures;

    /**
     * A doctor available two days from now (real clock - child processes
     * cannot see Carbon test time) at 09:00 Riyadh.
     *
     * @return array{Doctor, CarbonImmutable}
     */
    private function doctorWithRealSlot(): array
    {
        $day = CarbonImmutable::now('Asia/Riyadh')->addDays(2)->startOfDay();
        $doctor = Doctor::factory()->approved()->create(['video_fee_amount' => 15000]);

        AvailabilityRule::create([
            'doctor_id' => $doctor->id, 'day_of_week' => (int) $day->format('w'),
            'start_time' => '09:00', 'end_time' => '10:00', 'slot_minutes' => 30, 'consultation_types' => ['video'],
        ]);

        return [$doctor, $day->setTime(9, 0)->utc()];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Doctor $doctor, Patient $patient, CarbonImmutable $start): array
    {
        return [
            'id' => (string) Str::uuid7(), 'patient_id' => $patient->id, 'doctor_id' => $doctor->id,
            'booked_by_user_id' => $patient->user_id, 'consultation_type' => 'video',
            'starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $start->addMinutes(30)->format('Y-m-d H:i:s'),
            'status' => 'confirmed', 'price_amount' => 15000, 'currency' => 'SAR',
            'cancellation_policy' => json_encode(['free_cancellation_hours' => 24, 'late_cancellation_refund_percent' => 0]),
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    public function test_second_session_waits_for_and_is_then_rejected_by_the_first(): void
    {
        [$doctor, $start] = $this->doctorWithRealSlot();
        [$p1, $p2] = [Patient::factory()->create(), Patient::factory()->create()];

        config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
        $first = DB::connection('pgsql');
        $second = DB::connection('pgsql_second');

        $first->beginTransaction();
        $first->table('appointments')->insert($this->row($doctor, $p1, $start));

        // While the first transaction is open, the overlapping insert blocks on it.
        $second->statement("SET lock_timeout = '1s'");
        try {
            $second->table('appointments')->insert($this->row($doctor, $p2, $start->addMinutes(15)));
            $this->fail('The overlapping insert should have waited for the first transaction.');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->getCode(), 'expected lock_not_available while the first booking is in flight');
        }

        $first->commit();

        // Once the first booking commits, the overlap is rejected outright.
        try {
            $second->table('appointments')->insert($this->row($doctor, $p2, $start->addMinutes(15)));
            $this->fail('The overlapping insert should violate the exclusion constraint.');
        } catch (QueryException $e) {
            $this->assertSame('23P01', $e->getCode());
        }

        $this->assertSame(1, Appointment::count());
        DB::disconnect('pgsql_second');
    }

    public function test_parallel_booking_processes_produce_exactly_one_appointment(): void
    {
        [$doctor, $start] = $this->doctorWithRealSlot();

        $userIds = [];
        for ($i = 0; $i < 4; $i++) {
            $user = User::factory()->patient()->create();
            $this->consentingPatient($user);
            $userIds[] = $user->id;
        }

        $doctorId = $doctor->id;
        $startsAt = $start->toIso8601ZuluString();

        // Plain loop: SerializableClosure re-reads closure source, and a closure
        // nested in an arrow function on the same line would be mis-identified.
        $tasks = [];
        foreach ($userIds as $userId) {
            $tasks[] = function () use ($userId, $doctorId, $startsAt) {
                try {
                    app(BookAppointment::class)->handle(User::findOrFail($userId), [
                        'doctor_id' => $doctorId, 'consultation_type' => 'video', 'starts_at' => $startsAt,
                    ]);

                    return 'booked';
                } catch (ApiException $e) {
                    return $e->errorCode;
                }
            };
        }

        $results = Concurrency::driver('process')->run($tasks);

        sort($results);
        $this->assertSame(['booked', 'slot_unavailable', 'slot_unavailable', 'slot_unavailable'], $results);
        $this->assertSame(1, Appointment::where('doctor_id', $doctorId)->count());
    }
}
