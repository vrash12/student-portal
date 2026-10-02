<?php

namespace App\Support;

use App\Models\GradeCorrectionRequest;

/**
 * Page data for grade correction requests. Load `assessment.classSubject`
 * with its subject and class, `candidate`, `requester` and `decider` first.
 */
final class GradeCorrectionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(GradeCorrectionRequest $request): array
    {
        $assessment = $request->assessment;
        $offering = $assessment->classSubject;

        return [
            'id' => $request->id,
            'status' => $request->status->toArray(),
            'incidentType' => $request->incident_type->toArray(),
            'candidate' => [
                'id' => $request->candidate->id,
                'number' => $request->candidate->candidate_number,
                'name' => $request->candidate->full_name,
            ],
            'assessment' => [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'maxScore' => DecimalValue::display($assessment->max_score),
                'subject' => $offering->subject->name,
                'className' => $offering->classBatch->name,
            ],
            'currentScore' => self::score($request->current_score),
            'proposedScore' => self::score($request->proposed_score),
            'requestedBy' => $request->requester->name,
            'requestedAt' => $request->created_at?->toIso8601String(),
            'decidedBy' => $request->decider?->name,
            'decidedAt' => $request->decided_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function details(GradeCorrectionRequest $request): array
    {
        return [
            ...self::summary($request),
            'currentComment' => $request->current_comment,
            'proposedComment' => $request->proposed_comment,
            'incidentDetails' => $request->incident_details,
            'decisionNote' => $request->decision_note,
        ];
    }

    private static function score(mixed $value): ?string
    {
        return $value === null ? null : DecimalValue::display($value);
    }
}
