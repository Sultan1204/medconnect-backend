<?php

namespace App\Modules\Audit\Services;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Writes audit entries synchronously, in the caller's transaction, so an
 * action and its audit record commit (or roll back) together.
 *
 * Callers must pass only identifiers and field *names* in $changes for
 * clinical data - never clinical content itself.
 */
class AuditLogger
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array{
     *     actor?: User|null,
     *     patient_id?: string|null,
     *     purpose?: string|null,
     *     changes?: array<string, mixed>|null,
     *     metadata?: array<string, mixed>|null,
     *     outcome?: string,
     * }  $options
     */
    public function record(string $action, ?Model $subject = null, array $options = []): AuditLog
    {
        $actor = array_key_exists('actor', $options) ? $options['actor'] : $this->request->user();

        return AuditLog::create([
            'actor_id' => $actor?->getKey(),
            'actor_role' => $actor instanceof User ? $actor->primaryRole() : null,
            'action' => $action,
            'subject_type' => $subject ? self::subjectType($subject) : null,
            'subject_id' => $subject?->getKey(),
            'patient_id' => $options['patient_id'] ?? null,
            'purpose' => $options['purpose'] ?? null,
            'changes' => $options['changes'] ?? null,
            'metadata' => $options['metadata'] ?? null,
            'outcome' => $options['outcome'] ?? 'success',
            'ip_address' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 500) ?: null,
            'request_id' => $this->request->attributes->get('request_id'),
        ]);
    }

    /**
     * @param  array{
     *     actor?: User|null,
     *     patient_id?: string|null,
     *     purpose?: string|null,
     *     changes?: array<string, mixed>|null,
     *     metadata?: array<string, mixed>|null,
     * }  $options
     */
    public function failure(string $action, ?Model $subject = null, array $options = []): AuditLog
    {
        return $this->record($action, $subject, ['outcome' => 'failure'] + $options);
    }

    /**
     * Short, stable subject label (e.g. "user", "clinical_note") independent of PHP namespaces.
     */
    public static function subjectType(Model $subject): string
    {
        return $subject->getMorphClass() === $subject::class
            ? Str::snake(class_basename($subject))
            : $subject->getMorphClass();
    }

    /**
     * Names of attributes that changed, without their values.
     *
     * @return array{fields: list<string>}
     */
    public static function changedFields(Model $model): array
    {
        return ['fields' => array_values(array_diff(array_keys($model->getChanges()), ['updated_at']))];
    }
}
