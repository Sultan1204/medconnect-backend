<?php

namespace App\Modules\Audit\Services;

use App\Modules\Audit\Models\IntegrationEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Throwable;

class IntegrationLogger
{
    public function __construct(private readonly Request $request) {}

    /**
     * Runs an outbound integration call and records its outcome and latency.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @param  (callable(T): ?string)|null  $reference  extracts the external reference from the result
     * @return T
     */
    public function call(string $integration, string $operation, ?Model $subject, callable $call, ?callable $reference = null): mixed
    {
        $started = hrtime(true);

        try {
            $result = $call();
            $this->record($integration, $operation, 'outbound', 'success', $subject, $reference ? $reference($result) : null, $started);

            return $result;
        } catch (Throwable $e) {
            $this->record($integration, $operation, 'outbound', 'failure', $subject, null, $started, class_basename($e));

            throw $e;
        }
    }

    public function inbound(string $integration, string $operation, string $status, ?Model $subject = null, ?string $reference = null, ?string $errorCode = null): void
    {
        $this->record($integration, $operation, 'inbound', $status, $subject, $reference, null, $errorCode);
    }

    private function record(string $integration, string $operation, string $direction, string $status, ?Model $subject, ?string $reference, ?int $started, ?string $errorCode = null): void
    {
        IntegrationEvent::create([
            'integration' => $integration,
            'operation' => $operation,
            'direction' => $direction,
            'status' => $status,
            'subject_type' => $subject ? AuditLogger::subjectType($subject) : null,
            'subject_id' => $subject?->getKey(),
            'external_reference' => $reference !== null ? mb_substr($reference, 0, 200) : null,
            'duration_ms' => $started !== null ? (int) ((hrtime(true) - $started) / 1_000_000) : null,
            'error_code' => $errorCode !== null ? mb_substr($errorCode, 0, 100) : null,
            'request_id' => $this->request->attributes->get('request_id'),
        ]);
    }
}
