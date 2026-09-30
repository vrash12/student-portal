<?php

namespace App\Services\Examinations;

use App\Models\Candidate;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use Illuminate\Support\Collection;

final class ExaminationMonitoringService
{
    public const INACTIVE_AFTER_MINUTES = 2;

    /**
     * Monitoring deliberately returns candidate identity and progress only;
     * answer values and confidential scoring data never leave this service.
     *
     * @return array{refreshedAt: string, totals: array<string, int>, candidates: list<array<string, mixed>>}
     */
    public function snapshot(Examination $examination): array
    {
        $classBatchId = (int) $examination->classSubject->class_batch_id;
        $candidates = Candidate::query()->gradableIn($classBatchId)->with('classBatch')->orderBy('candidate_number')->get();
        $attempts = ExaminationAttempt::query()->where('examination_id', $examination->id)->with('candidate')->orderBy('attempt_number')->get()->groupBy('candidate_id')->map(fn (Collection $items): ExaminationAttempt => $items->last());
        $inactiveBefore = now()->subMinutes(self::INACTIVE_AFTER_MINUTES);
        $rows = $candidates->map(function (Candidate $candidate) use ($attempts, $examination, $inactiveBefore): array {
            $attempt = $attempts->get($candidate->id);
            $status = match (true) {
                $attempt === null => 'not_started',
                $attempt->status === 'submitted' => 'submitted',
                $attempt->status === 'expired' => 'expired',
                $attempt->last_activity_at === null || $attempt->last_activity_at->lt($inactiveBefore) => 'inactive',
                default => 'active',
            };
            $answers = $attempt?->answers ?? [];
            $answered = collect($answers)->filter(fn (array $answer): bool => ($answer['value'] ?? null) !== null && ($answer['value'] ?? '') !== '')->count();

            return [
                'candidate' => ['id' => $candidate->id, 'number' => $candidate->candidate_number, 'name' => $candidate->full_name],
                'status' => $status,
                'attemptNumber' => $attempt?->attempt_number,
                'answered' => $attempt === null ? 0 : $answered,
                'questionCount' => $attempt === null ? $examination->examinationQuestions()->count() : count($attempt->delivery ?? []),
                'lastActivityAt' => $attempt?->last_activity_at?->toIso8601String(),
            ];
        })->values();
        $counts = $rows->countBy('status');

        return [
            'refreshedAt' => now()->toIso8601String(),
            'totals' => ['candidates' => $rows->count(), 'active' => $counts->get('active', 0), 'inactive' => $counts->get('inactive', 0), 'submitted' => $counts->get('submitted', 0), 'expired' => $counts->get('expired', 0), 'notStarted' => $counts->get('not_started', 0)],
            'candidates' => $rows->all(),
        ];
    }
}
