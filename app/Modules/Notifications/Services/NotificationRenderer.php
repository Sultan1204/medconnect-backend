<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;

/**
 * Turns a stored notification into display text in the user's language.
 * Text is generated on demand so it follows the user's current language.
 */
class NotificationRenderer
{
    /**
     * @return array{title: string, body: string}
     */
    public function render(Notification $notification, string $locale): array
    {
        $locale = in_array($locale, config('medconnect.supported_locales'), true) ? $locale : 'en';
        $key = 'notifications.'.$notification->type->value;
        $replace = $this->replacements($notification, $locale);

        return [
            'title' => __("{$key}.title", $replace, $locale),
            'body' => __("{$key}.body", $replace, $locale),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function replacements(Notification $notification, string $locale): array
    {
        $params = $notification->params;
        $replace = [];

        if (isset($params['starts_at'])) {
            $replace['time'] = Carbon::parse($params['starts_at'])
                ->setTimezone(config('medconnect.display_timezone'))
                ->locale($locale)
                ->isoFormat('ddd D MMM, HH:mm');
        }

        if (isset($params['amount'])) {
            $replace['amount'] = Money::format((int) $params['amount'], (string) ($params['currency'] ?? config('medconnect.currency')));
        }

        if (isset($params['status'])) {
            $group = $notification->type === NotificationType::PrescriptionStatusChanged ? 'prescription_status' : 'pharmacy_status';
            $replace['status'] = __("notifications.{$group}.{$params['status']}", [], $locale);
        }

        if (isset($params['reference'])) {
            $replace['reference'] = (string) $params['reference'];
        }

        if ($notification->type === NotificationType::ConsultationEmergency) {
            $replace['numbers'] = collect(config('medconnect.emergency.numbers'))->pluck('number')->implode(' / ');
        }

        return $replace;
    }
}
