<?php

namespace App\Modules\Notifications\Console;

use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Console\Command;

/**
 * Sends the "24 hours" and "1 hour" appointment reminders. Safe to run as often
 * as every few minutes: each reminder is de-duplicated per appointment, window
 * and recipient, and a booking made inside a window only gets the later one.
 */
class SendAppointmentReminders extends Command
{
    protected $signature = 'notifications:send-reminders';

    protected $description = 'Send upcoming-appointment reminders to patients and doctors.';

    /** Hours before the start at which each reminder window opens (window = [hours-1, hours]). */
    private const WINDOWS = ['24h' => 24, '1h' => 1];

    public function handle(NotificationService $notifications): int
    {
        $sent = 0;

        $appointments = Appointment::query()
            ->where('status', AppointmentStatus::Confirmed)
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addHours(self::WINDOWS['24h']))
            ->with(['doctor.user', 'patient.user', 'bookedBy'])
            ->orderBy('starts_at')
            ->lazyById(200);

        foreach ($appointments as $appointment) {
            $window = $this->windowFor($appointment);

            if ($window === null) {
                continue;
            }

            $params = ['appointment_id' => $appointment->id, 'starts_at' => $appointment->starts_at->toIso8601ZuluString()];
            $recipients = [$appointment->patient->user ?? $appointment->bookedBy, $appointment->doctor->user];

            foreach (array_filter($recipients) as $user) {
                if ($notifications->notify($user, NotificationType::AppointmentReminder, $params, "reminder:{$appointment->id}:{$window}:{$user->id}") !== null) {
                    $sent++;
                }
            }
        }

        $this->info("{$sent} reminder(s) queued.");

        return self::SUCCESS;
    }

    private function windowFor(Appointment $appointment): ?string
    {
        $minutes = now()->diffInMinutes($appointment->starts_at, false);

        foreach (self::WINDOWS as $name => $hours) {
            if ($minutes <= $hours * 60 && $minutes > ($hours - 1) * 60) {
                return $name;
            }
        }

        return null;
    }
}
