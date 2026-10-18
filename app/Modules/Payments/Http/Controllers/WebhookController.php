<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Services\WebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Payments - Webhooks
 */
class WebhookController
{
    /**
     * Payment provider webhook.
     *
     * Authenticated by the provider's signature header, not a bearer token.
     * Always responds 200 for verified events (including duplicates) so the
     * provider stops retrying; 400 for invalid signatures.
     *
     * @unauthenticated
     */
    public function __invoke(Request $request, WebhookProcessor $processor): JsonResponse
    {
        return response()->json(['received' => true, 'result' => $processor->handle($request)]);
    }
}
