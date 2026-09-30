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
        'access_code',
        'prompt', 'question_text', 'answer', 'answers', 'correct_answer', 'is_correct',
        'choices', 'explanation', 'scoring_key', 'delivery', 'comment', 'old_comment', 'new_comment',
        'correct_choice_id', 'correct_position', 'previous_comment', 'feedback',
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
     * Records only the values that differ between two snapshots, with the
     * previous and new value of each. Returns null when nothing changed.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function recordChanges(
        AuditAction $action,
        Model $subject,
        array $before,
        array $after,
        ?string $reason = null,
    ): ?AuditLog {
        $changedKeys = array_keys(array_filter(
            $after,
            fn (mixed $value, string $key): bool => ! array_key_exists($key, $before) || $before[$key] !== $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($changedKeys === []) {
            return null;
        }

        $keys = array_flip($changedKeys);

        return $this->record(
            $action,
            $subject,
            oldValues: array_intersect_key($before, $keys),
            newValues: array_intersect_key($after, $keys),
            reason: $reason,
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>|null
     */
    public function sanitize(array $values): ?array
    {
        $clean = $this->redact($values);

        return $clean === [] ? null : $clean;
    }

    /**
     * Removes redacted keys at every depth. Nested arrays keep their shape:
     * an empty list stays [] (for example "no grading categories before"),
     * so only the top level collapses to null.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function redact(array $values): array
    {
        $clean = array_filter($values, fn ($key) => ! in_array(Str::snake((string) $key), self::REDACTED_KEYS, true), ARRAY_FILTER_USE_KEY);
        foreach ($clean as $key => $value) {
            if (is_array($value)) {
                $clean[$key] = $this->redact($value);
            }
        }

        return $clean;
    }

    private function userAgent(): ?string
    {
        $userAgent = $this->request->userAgent();

        return $userAgent === null ? null : Str::limit($userAgent, 250, '');
    }
}
