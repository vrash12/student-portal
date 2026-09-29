<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Single entry point for writing audit records.
 *
 * Never pass secrets in the value arrays; known sensitive keys are removed
 * defensively before anything is stored.
 */
final class AuditLogger
{
    /**
     * Keys that must never be persisted in audit values.
     */
    private const REDACTED_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'remember_token',
        'token',
        'secret',
    ];

    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function record(
        AuditAction $action,
        ?Model $subject = null,
        array $oldValues = [],
        array $newValues = [],
        ?string $reason = null,
        ?User $actor = null,
    ): AuditLog {
        $actor ??= $this->request->user();

        $entry = new AuditLog;
        $entry->forceFill([
            'actor_id' => $actor?->getKey(),
            'action' => $action->value,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'old_values' => $this->sanitize($oldValues),
            'new_values' => $this->sanitize($newValues),
            'reason' => $reason,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->userAgent(),
        ])->save();

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>|null
     */
    private function sanitize(array $values): ?array
    {
        $clean = array_diff_key($values, array_flip(self::REDACTED_KEYS));

        return $clean === [] ? null : $clean;
    }

    private function userAgent(): ?string
    {
        $userAgent = $this->request->userAgent();

        return $userAgent === null ? null : Str::limit($userAgent, 250, '');
    }
}
