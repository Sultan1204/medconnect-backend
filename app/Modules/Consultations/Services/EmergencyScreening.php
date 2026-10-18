<?php

namespace App\Modules\Consultations\Services;

/**
 * Rule-based red-flag screening configured in medconnect.emergency. This is
 * NOT a diagnosis or triage algorithm: any "yes" answer simply directs the
 * patient to emergency services immediately, without waiting for a doctor.
 */
class EmergencyScreening
{
    /**
     * @param  array<string, mixed>  $answers
     * @return list<string> keys of red flags answered "yes"
     */
    public function redFlags(array $answers): array
    {
        $questions = array_keys(config('medconnect.emergency.screening_questions'));

        return array_values(array_filter($questions, fn (string $key) => filter_var($answers[$key] ?? false, FILTER_VALIDATE_BOOLEAN)));
    }

    /**
     * Instructions returned to the client when red flags are present.
     *
     * @return array<string, mixed>
     */
    public function guidance(?string $locale = null): array
    {
        $ar = ($locale ?? app()->getLocale()) === 'ar';

        return [
            'is_emergency' => true,
            'message' => $ar
                ? 'قد تحتاج إلى رعاية طارئة. اتصل بالإسعاف فوراً أو توجه إلى أقرب قسم طوارئ. لا تنتظر الطبيب.'
                : 'You may need emergency care. Call an ambulance now or go to the nearest emergency department. Do not wait for the doctor.',
            'numbers' => $this->numbers($locale),
        ];
    }

    /**
     * @return list<array{number: string, label: string}>
     */
    public function numbers(?string $locale = null): array
    {
        $ar = ($locale ?? app()->getLocale()) === 'ar';

        return array_map(fn (array $n) => ['number' => $n['number'], 'label' => $ar ? $n['label_ar'] : $n['label_en']], config('medconnect.emergency.numbers'));
    }
}
