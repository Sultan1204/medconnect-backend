<?php

namespace App\Modules\Consultations\Http\Controllers;

use App\Modules\Consultations\Services\EmergencyScreening;
use Illuminate\Http\JsonResponse;

/**
 * @tags Consultations - Emergency
 */
class EmergencyInfoController
{
    /**
     * Emergency disclaimer, numbers and screening questions.
     *
     * Clients must display the disclaimer before booking and in every
     * consultation screen. Localized via Accept-Language.
     *
     * @unauthenticated
     */
    public function __invoke(EmergencyScreening $screening): JsonResponse
    {
        $ar = app()->getLocale() === 'ar';

        $questions = [];
        foreach (config('medconnect.emergency.screening_questions') as $key => $question) {
            $questions[] = ['key' => $key, 'question' => $ar ? $question['ar'] : $question['en']];
        }

        return response()->json(['data' => [
            'disclaimer' => $ar
                ? 'ميدكونكت ليست خدمة طوارئ. في حالات الطوارئ اتصل بالإسعاف فوراً أو توجه إلى أقرب قسم طوارئ.'
                : 'MedConnect is not an emergency service. In an emergency, call an ambulance immediately or go to the nearest emergency department.',
            'numbers' => $screening->numbers(),
            'screening_questions' => $questions,
        ]])->setPublic()->setMaxAge(3600);
    }
}
