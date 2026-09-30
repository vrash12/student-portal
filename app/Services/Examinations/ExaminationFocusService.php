<?php

namespace App\Services\Examinations;

use App\Models\ExaminationAttempt;
use App\Models\ExaminationFocusEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Records when a candidate leaves and returns to the examination screen
 * (tab or app switch, minimized browser, another window focused).
 *
 * Browsers can only report that the page lost visibility or focus; they
 * cannot tell why. The record is an indicator for instructors to follow up
 * on, never proof of misconduct, and it never affects scoring. Times come
 * from the server clock; the browser only reports how long ago an event
 * happened (so an event queued during a short disconnection keeps roughly
 * its real time), bounded to the attempt.
 */
final class ExaminationFocusService
{
    /** Departures recorded per attempt; later ones are ignored so a script cannot flood the table. */
    public const MAX_EVENTS_PER_ATTEMPT = 500;

    /** How old a reported event may be (a queued event from a longer outage is placed no earlier than this). */
    public const MAX_DELAY_MS = 600_000;

    public function __construct(private readonly CandidateAttemptService $attempts) {}

    /**
     * @param  'left'|'returned'  $event
     * @param  'hidden'|'blur'  $reason
     * @return array{recorded: bool, status: string}
     */
    public function record(User $user, ExaminationAttempt $attempt, string $event, string $reason, int $delayMs): array
    {
        Gate::forUser($user)->authorize('view', $attempt);

        return DB::transaction(function () use ($user, $attempt, $event, $reason, $delayMs): array {
            // Overdue attempts are closed first; nothing is recorded after the deadline.
            $attempt = $this->attempts->expire(ExaminationAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail());
            if ($attempt->status !== 'in_progress' || ! $this->attempts->eligible($user, $attempt->examination)) {
                return ['recorded' => false, 'status' => $attempt->status];
            }

            $latest = ExaminationFocusEvent::query()->where('examination_attempt_id', $attempt->id)->latest('left_at')->latest('id')->first();
            $open = $latest !== null && $latest->returned_at === null ? $latest : null;
            $at = $this->eventTime($attempt, $latest, $delayMs);

            if ($event === 'left') {
                $count = ExaminationFocusEvent::query()->where('examination_attempt_id', $attempt->id)->count();
                if ($open !== null || $count >= self::MAX_EVENTS_PER_ATTEMPT) {
                    return ['recorded' => false, 'status' => $attempt->status];
                }

                $focusEvent = new ExaminationFocusEvent;
                $focusEvent->examination_attempt_id = $attempt->id;
                $focusEvent->reason = $reason;
                $focusEvent->left_at = $at;
                $focusEvent->save();

                return ['recorded' => true, 'status' => $attempt->status];
            }

            if ($open === null) {
                return ['recorded' => false, 'status' => $attempt->status];
            }

            $open->returned_at = $at->max($open->left_at);
            $open->save();

            return ['recorded' => true, 'status' => $attempt->status];
        });
    }

    /**
     * Per attempt: number of departures, total time away in seconds, and
     * since when the candidate is away now (in-progress attempts only).
     * A departure still open when the attempt ended counts until its end.
     *
     * @param  iterable<ExaminationAttempt>  $attempts
     * @return array<int, array{count: int, awaySeconds: int, awaySince: ?string}>
     */
    public function summaries(iterable $attempts): array
    {
        $byId = collect($attempts)->keyBy('id');
        if ($byId->isEmpty()) {
            return [];
        }

        $rows = ExaminationFocusEvent::query()
            ->whereIn('examination_attempt_id', $byId->keys()->all())
            ->groupBy('examination_attempt_id')
            ->selectRaw('examination_attempt_id, count(*) as departures')
            ->selectRaw('coalesce(sum(case when returned_at is not null then timestampdiff(second, left_at, returned_at) else 0 end), 0) as closed_seconds')
            ->selectRaw('max(case when returned_at is null then left_at end) as open_since')
            ->get();

        $summaries = [];
        foreach ($rows as $row) {
            $attempt = $byId->get((int) $row->examination_attempt_id);
            $openSince = $row->open_since === null ? null : Carbon::parse($row->open_since, 'UTC');
            $end = $this->attemptEnd($attempt);
            $openSeconds = $openSince === null ? 0 : max(0, (int) $openSince->diffInSeconds($end, true));

            $summaries[(int) $row->examination_attempt_id] = [
                'count' => (int) $row->departures,
                'awaySeconds' => (int) $row->closed_seconds + $openSeconds,
                'awaySince' => $openSince !== null && $attempt?->status === 'in_progress' ? $openSince->toIso8601String() : null,
            ];
        }

        return $summaries;
    }

    /**
     * Departures of one attempt, oldest first, for staff review.
     *
     * @return list<array{leftAt: string, returnedAt: ?string, seconds: int, reason: string}>
     */
    public function events(ExaminationAttempt $attempt): array
    {
        $end = $this->attemptEnd($attempt);

        return ExaminationFocusEvent::query()
            ->where('examination_attempt_id', $attempt->id)
            ->orderBy('left_at')->orderBy('id')
            ->get()
            ->map(fn (ExaminationFocusEvent $event): array => [
                'leftAt' => $event->left_at->toIso8601String(),
                'returnedAt' => $event->returned_at?->toIso8601String(),
                'seconds' => (int) $event->left_at->diffInSeconds($event->returned_at ?? $end, true),
                'reason' => $event->reason,
            ])
            ->all();
    }

    private function eventTime(ExaminationAttempt $attempt, ?ExaminationFocusEvent $latest, int $delayMs): CarbonInterface
    {
        $at = now()->subMilliseconds(max(0, min($delayMs, self::MAX_DELAY_MS)));
        $floor = $attempt->started_at ?? $at;
        if ($latest !== null) {
            $floor = $floor->max($latest->returned_at ?? $latest->left_at);
        }

        return $at->max($floor);
    }

    private function attemptEnd(?ExaminationAttempt $attempt): CarbonInterface
    {
        if ($attempt === null || $attempt->status === 'in_progress') {
            return now();
        }

        return $attempt->submitted_at ?? $attempt->expires_at ?? now();
    }
}
