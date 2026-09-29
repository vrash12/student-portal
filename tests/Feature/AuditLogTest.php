<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    public function test_entries_cannot_be_modified(): void
    {
        $entry = $this->app->make(AuditLogger::class)->record(AuditAction::Login);

        $this->expectException(LogicException::class);

        $entry->forceFill(['action' => 'tampered'])->save();
    }

    public function test_entries_cannot_be_deleted(): void
    {
        $entry = $this->app->make(AuditLogger::class)->record(AuditAction::Login);

        $this->expectException(LogicException::class);

        $entry->delete();
    }

    public function test_sensitive_values_are_never_stored(): void
    {
        $entry = $this->app->make(AuditLogger::class)->record(AuditAction::UserUpdated, newValues: [
            'name' => 'Visible Name',
            'password' => 'plain-secret',
            'password_confirmation' => 'plain-secret',
            'current_password' => 'old-secret',
            'remember_token' => 'token-value',
        ]);

        $this->assertSame(['name' => 'Visible Name'], AuditLog::query()->findOrFail($entry->id)->new_values);
    }

    public function test_request_context_is_captured(): void
    {
        $user = $this->userWithRole(SystemRole::Instructor, ['username' => 'instructor.test']);

        $this->withHeader('User-Agent', 'Examination Tablet 01')
            ->post('/login', ['username' => 'instructor.test', 'password' => 'password']);

        $entry = AuditLog::query()->where('action', AuditAction::Login->value)->sole();
        $this->assertSame($user->id, $entry->actor_id);
        $this->assertSame('127.0.0.1', $entry->ip_address);
        $this->assertSame('Examination Tablet 01', $entry->user_agent);
        $this->assertNotNull($entry->created_at);
    }
}
