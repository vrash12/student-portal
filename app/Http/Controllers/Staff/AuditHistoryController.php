<?php

namespace App\Http\Controllers\Staff;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\InstitutionDate;
use App\Support\ListCharts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        $query = AuditLog::query()->with('actor:id,name')
            ->when($filters['actor'] ?? null, fn ($query, $value) => $query->where('actor_id', $value))
            ->when($filters['action'] ?? null, fn ($query, $value) => $query->where('action', $value))
            ->when($filters['entity'] ?? null, fn ($query, $value) => $query->where('auditable_type', $value))
            ->when($filters['entity_id'] ?? null, fn ($query, $value) => $query->where('auditable_id', $value))
            ->when($filters['from'] ?? null, fn ($query, $value) => $query->where('created_at', '>=', InstitutionDate::boundary($value)))
            ->when($filters['to'] ?? null, fn ($query, $value) => $query->where('created_at', '<', InstitutionDate::boundary($value, true)));
        $charts = [$this->activityByDay($query), ListCharts::bars('Most Frequent Actions', 'The most common actions among the matching entries.',
            ListCharts::countBy($query, 'action', fn (mixed $value): string => AuditAction::tryFrom((string) $value)?->label() ?? (string) $value, 8), 'entry', 'entries')];
        $entries = $query->latest('id')->paginate(10)->withQueryString()->through(fn ($entry) => [
            'id' => $entry->id, 'actor' => $entry->actor?->name ?? 'System / unavailable actor',
            'action' => AuditAction::tryFrom($entry->action)?->label() ?? $entry->action,
            'entity' => $entry->auditable_type, 'entityId' => $entry->auditable_id,
            'timestamp' => $entry->created_at->toIso8601String(), 'reason' => $entry->reason,
            'before' => $logger->sanitize($entry->old_values ?? []), 'after' => $logger->sanitize($entry->new_values ?? []),
        ]);

        return Inertia::render('staff/audit-history/index', [
            'entries' => $entries, 'filters' => $filters, 'charts' => $charts,
            'actors' => User::whereIn('id', AuditLog::select('actor_id')->whereNotNull('actor_id')->distinct())->orderBy('name')->get(['id', 'name']),
            'actions' => collect(AuditAction::cases())->map(fn ($action) => ['value' => $action->value, 'label' => $action->label()]),
            'entities' => array_keys(Relation::morphMap()),
        ])->toResponse($request)->header('Cache-Control', 'no-store, private');
    }

    /**
     * Matching entries per day over the last 14 days (institution timezone).
     *
     * @param  Builder<AuditLog>  $query
     * @return array<string, mixed>
     */
    private function activityByDay(Builder $query): array
    {
        $timezone = (string) config('institution.timezone');
        $days = collect(range(13, 0))->mapWithKeys(fn (int $back): array => [now($timezone)->subDays($back)->toDateString() => 0])->all();
        (clone $query)->toBase()->where('created_at', '>=', now($timezone)->subDays(13)->startOfDay()->utc())
            ->orderBy('id')->pluck('created_at')
            ->each(function (mixed $createdAt) use (&$days, $timezone): void {
                $day = Carbon::parse((string) $createdAt, 'UTC')->timezone($timezone)->toDateString();
                if (array_key_exists($day, $days)) {
                    $days[$day]++;
                }
            });

        return ListCharts::columns('Activity in the Last 14 Days', 'Matching entries recorded each day.',
            collect($days)->map(fn (int $count, string $day): array => ['label' => Carbon::parse($day)->format('M j'), 'value' => $count])->values()->all(), 'entry', 'entries');
    }
}
