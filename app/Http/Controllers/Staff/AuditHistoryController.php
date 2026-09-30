<?php

namespace App\Http\Controllers\Staff;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\InstitutionDate;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class AuditHistoryController
{
    public function __invoke(Request $request, AuditLogger $logger)
    {
        $filters = $request->validate([
            'actor' => 'nullable|integer|min:1', 'action' => ['nullable', Rule::enum(AuditAction::class)],
            'entity' => ['nullable', Rule::in(array_keys(Relation::morphMap()))],
            'entity_id' => 'nullable|integer|min:1', 'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'page' => 'sometimes|integer|min:1',
        ]);
        $entries = AuditLog::query()->with('actor:id,name')
            ->when($filters['actor'] ?? null, fn ($query, $value) => $query->where('actor_id', $value))
            ->when($filters['action'] ?? null, fn ($query, $value) => $query->where('action', $value))
            ->when($filters['entity'] ?? null, fn ($query, $value) => $query->where('auditable_type', $value))
            ->when($filters['entity_id'] ?? null, fn ($query, $value) => $query->where('auditable_id', $value))
            ->when($filters['from'] ?? null, fn ($query, $value) => $query->where('created_at', '>=', InstitutionDate::boundary($value)))
            ->when($filters['to'] ?? null, fn ($query, $value) => $query->where('created_at', '<', InstitutionDate::boundary($value, true)))
            ->latest('id')->paginate(25)->withQueryString()->through(fn ($entry) => [
                'id' => $entry->id, 'actor' => $entry->actor?->name ?? 'System / unavailable actor',
                'action' => AuditAction::tryFrom($entry->action)?->label() ?? $entry->action,
                'entity' => $entry->auditable_type, 'entityId' => $entry->auditable_id,
                'timestamp' => $entry->created_at->toIso8601String(), 'reason' => $entry->reason,
                'before' => $logger->sanitize($entry->old_values ?? []), 'after' => $logger->sanitize($entry->new_values ?? []),
            ]);

        return Inertia::render('staff/audit-history/index', [
            'entries' => $entries, 'filters' => $filters,
            'actors' => User::whereIn('id', AuditLog::select('actor_id')->whereNotNull('actor_id')->distinct())->orderBy('name')->get(['id', 'name']),
            'actions' => collect(AuditAction::cases())->map(fn ($action) => ['value' => $action->value, 'label' => $action->label()]),
            'entities' => array_keys(Relation::morphMap()),
        ])->toResponse($request)->header('Cache-Control', 'no-store, private');
    }
}
