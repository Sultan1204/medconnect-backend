<?php

namespace App\Modules\Administration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public contact form.
 *
 * @tags Contact
 */
class ContactController extends Controller
{
    /**
     * Send a message to the team.
     *
     * Always answers 202 when the message is accepted. Do not send health information here.
     *
     * @unauthenticated
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:191'],
            'subject' => ['required', 'string', 'min:3', 'max:150'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        ContactMessage::create($data);

        return response()->json(['data' => ['status' => 'received']], 202);
    }
}
