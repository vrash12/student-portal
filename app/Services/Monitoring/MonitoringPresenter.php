<?php

namespace App\Services\Monitoring;

use App\Enums\CandidateStatus;
use App\Services\Grading\SubjectGrade;

/**
 * Page payloads for monitored candidates. Only identifiers, names, class,
 * enrollment status, grades, and standings leave the server: never comments,
 * reasons, or individual assessment scores (UI_UX_DESIGN.md §71).
 */
final class MonitoringPresenter
{
    /**
     * A row of the monitoring table.
     *
     * @param  bool  $subjectView  one subject is selected, so the row shows that subject's result
     * @param  list<int>  $taughtOfferingIds  class subjects the viewer teaches (gradebook links)
     * @return array<string, mixed>
     */
    public function row(MonitoredCandidate $entry, bool $subjectView, array $taughtOfferingIds): array
    {
        $subject = $subjectView && $entry->subjects !== [] ? $entry->subjects[0] : null;

        return [
            ...$this->summary($entry, $taughtOfferingIds),
            'overall' => $entry->overall->toArray(),
            'concerns' => array_map(SubjectConcerns::present(...), $entry->concerns()),
            'missingScores' => $entry->missingScores(),
            'subjectResult' => $subject === null ? null : [
                'classSubjectId' => $subject['offering']->id,
                'result' => $this->result($subject['grade']),
                'canOpenGradebook' => in_array($subject['offering']->id, $taughtOfferingIds, true),
            ],
        ];
    }

    /**
     * The short form used by dashboards: who, which class, standing, the
     * most serious subject, and the lowest grade.
     *
     * @param  list<int>  $taughtOfferingIds
     * @return array<string, mixed>
     */
    public function summary(MonitoredCandidate $entry, array $taughtOfferingIds): array
    {
        $candidate = $entry->candidate;
        $lowest = $entry->lowest();
        $concerns = $entry->concerns();
        $mostSerious = $concerns[0] ?? null;

        return [
            'candidate' => [
                'id' => $candidate->id,
                'candidateNumber' => $candidate->candidate_number,
                'name' => $candidate->full_name,
                // Shown only when it explains a result (On Leave, Completed).
                'status' => $candidate->status === CandidateStatus::Enrolled ? null : $candidate->status->label(),
            ],
            'classBatch' => ['id' => $candidate->classBatch->id, 'name' => $candidate->classBatch->name],
            'standing' => $entry->overall->standing?->toArray(),
            'lowest' => $lowest === null ? null : [
                'grade' => $lowest['grade']->grade,
                'subject' => $lowest['offering']->subject->name,
                'isProvisional' => $lowest['grade']->isProvisional(),
            ],
            'mostSerious' => $mostSerious === null ? null : [
                ...SubjectConcerns::present($mostSerious),
                'canOpenGradebook' => in_array($mostSerious['offering']->id, $taughtOfferingIds, true),
            ],
        ];
    }

    /**
     * A subject grade without the per-category breakdown.
     *
     * @return array<string, mixed>
     */
    private function result(SubjectGrade $grade): array
    {
        $result = $grade->toArray();
        unset($result['categories']);

        return $result;
    }
}
