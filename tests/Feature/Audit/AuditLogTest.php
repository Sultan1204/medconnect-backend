<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    public function test_records_actor_subject_and_request_context(): void
    {
        $actor = User::factory()->admin()->create();
        $subject = User::factory()->patient()->create();

        $log = app(AuditLogger::class)->record('users.viewed', $subject, ['actor' => $actor, 'purpose' => 'support']);

        $this->assertSame($actor->id, $log->actor_id);
        $this->assertSame('admin', $log->actor_role);
        $this->assertSame('user', $log->subject_type);
        $this->assertSame($subject->id, $log->subject_id);
        $this->assertSame('support', $log->purpose);
    }

    public function test_entries_cannot_be_modified_through_eloquent(): void
    {
        $log = app(AuditLogger::class)->record('test.event');

        $this->expectException(LogicException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_entries_cannot_be_modified_or_deleted_in_the_database(): void
    {
        $log = app(AuditLogger::class)->record('test.event');

        // Each probe runs in its own savepoint: a failed statement would otherwise
        // abort the surrounding test transaction in PostgreSQL.
        foreach (['update' => fn () => DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered']),
            'delete' => fn () => DB::table('audit_logs')->where('id', $log->id)->delete()] as $operation => $probe) {
            try {
                DB::transaction($probe);
                $this->fail("{$operation} should have been rejected");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage(), $operation);
            }
        }

        $this->assertSame('test.event', AuditLog::findOrFail($log->id)->action);
    }

    public function test_changed_fields_lists_names_without_values(): void
    {
        $user = User::factory()->create(['name' => 'Before']);
        $user->update(['name' => 'After']);

        $this->assertSame(['fields' => ['name']], AuditLogger::changedFields($user));
    }
}
