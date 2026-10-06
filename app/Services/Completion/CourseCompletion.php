<?php

namespace App\Services\Completion;

use App\Enums\AcademicStanding;
use App\Enums\AreaStatus;
use App\Enums\CandidateStatus;
use App\Enums\GradeStatus;
use App\Enums\QualificationStatus;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Services\Grading\GradingThresholds;
use App\Services\Monitoring\CandidateProfileRecord;
use App\Services\Performance\AreaResult;
use App\Services\Performance\CandidateQualification;
use App\Services\Performance\QualificationEngine;

/**
 * The end of the course for one candidate (owner request, 2026-10-06): the
 * figures printed on the Transcript of Records and the Certificate of
 * Completion, read from the existing engines (subject grades, phase averages
 * and CGPA from CourseRecordService; final course grade, qualification and
 * class rank from QualificationEngine). Nothing is calculated or stored here.
 *
 * - The transcript can be printed at any time; it is marked PROVISIONAL
 *   until the course is over for the candidate (status Completed, every
 *   subject final, every weighted area graded).
 * - The certificate is issued only to a candidate whose status is Completed,
 *   whose every subject has a final grade, and who qualified.
 *
 * Class rank is staff only (owner decision 2026-10-01): these documents are
 * issued by administrators (CandidatePolicy::issueCompletionDocuments).
 */
final class CourseCompletion
{
    public function __construct(
        private readonly CandidateProfileRecord $records,
        private readonly QualificationEngine $qualification,
    ) {}

    /**
     * @return array{cgpa: array{grade: float|null, complete: bool, gradedSubjects: int, totalSubjects: int}, finalGrade: array{score: float|null, complete: bool}, qualification: array{status: array<string, string>, reasons: list<string>, pending: list<string>}|null, rank: int|null, classSize: int, transcriptFinal: bool, certificate: array{eligible: bool, reasons: list<string>}}
     */
    public function summary(Candidate $candidate): array
    {
        $candidate->loadMissing('classBatch.academicPeriod');
        $academics = $this->records->academics($candidate);
        [$qualification, $classSize] = $this->ranked($candidate);

        return $this->summarize($candidate, $academics, $qualification, $classSize);
    }

    /**
     * Everything the transcript prints.
     *
     * @return array<string, mixed>
     */
    public function transcript(Candidate $candidate): array
    {
        $candidate->loadMissing(['classBatch.academicPeriod', 'classBatch.campus', 'campus']);
        $academics = $this->records->academics($candidate);
        [$qualification, $classSize] = $this->ranked($candidate);
        $period = $candidate->classBatch?->academicPeriod;
        $thresholds = $period === null ? null : GradingThresholds::forPeriod($period);

        return [
            'summary' => $this->summarize($candidate, $academics, $qualification, $classSize),
            'passingGrade' => $thresholds?->passingGrade(),
            'phases' => $this->phases($academics),
            'totalUnits' => array_sum(array_map(fn (array $subject): float => (float) $subject['units'], $academics['subjects'])),
            'areas' => $qualification === null ? [] : $this->areas($qualification),
        ];
    }

    /**
     * Candidates of the class whose certificate can be issued, in name order.
     *
     * @return list<array{candidate: Candidate, summary: array<string, mixed>}>
     */
    public function certifiable(ClassBatch $classBatch): array
    {
        $ranked = $this->qualification->forClass($classBatch);
        $classSize = count(array_filter($ranked, fn (CandidateQualification $item): bool => $item->rank !== null));
        $byCandidate = [];
        foreach ($ranked as $item) {
            $byCandidate[$item->candidate->id] = $item;
        }

        $certifiable = [];
        $candidates = $classBatch->candidates()
            ->where('status', CandidateStatus::Completed->value)
            ->with(['classBatch.academicPeriod', 'campus'])
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->get();
        foreach ($candidates as $candidate) {
            $summary = $this->summarize($candidate, $this->records->academics($candidate), $byCandidate[$candidate->id] ?? null, $classSize);
            if ($summary['certificate']['eligible']) {
                $certifiable[] = ['candidate' => $candidate, 'summary' => $summary];
            }
        }

        return $certifiable;
    }

    /**
     * The candidate's results ranked against the whole class, and how many
     * candidates of the class are ranked.
     *
     * @return array{0: CandidateQualification|null, 1: int}
     */
    private function ranked(Candidate $candidate): array
    {
        $class = $candidate->classBatch;
        if ($class === null) {
            return [null, 0];
        }

        $ranked = $this->qualification->forClass($class);
        $classSize = count(array_filter($ranked, fn (CandidateQualification $item): bool => $item->rank !== null));
        foreach ($ranked as $item) {
            if ($item->candidate->id === $candidate->id) {
                return [$item, $classSize];
            }
        }

        // Not in the class's ranking (withdrawn): results without a rank.
        return [$this->qualification->forCandidate($candidate, withRank: false), $classSize];
    }

    /**
     * @param  array<string, mixed>  $academics  CandidateProfileRecord::academics
     * @return array<string, mixed>
     */
    private function summarize(Candidate $candidate, array $academics, ?CandidateQualification $qualification, int $classSize): array
    {
        $cgpa = $academics['course']['cgpa'];
        $finalGrade = ['score' => $qualification?->overall, 'complete' => $qualification?->overallComplete ?? false];
        $completed = $candidate->status === CandidateStatus::Completed;

        $reasons = [];
        if ($candidate->class_batch_id === null) {
            $reasons[] = 'The candidate is not assigned to a class.';
        } else {
            if (! $completed) {
                $reasons[] = 'The candidate\'s status is '.$candidate->status->label().'. Set it to Completed when the candidate finishes the course.';
            }
            if (! $cgpa['complete']) {
                $final = count(array_filter($academics['subjects'], fn (array $subject): bool => $subject['result']['status']['value'] === GradeStatus::Complete->value));
                $reasons[] = 'Every subject needs a final grade (every component assessed, no score missing): '.$final.' of '.count($academics['subjects']).' '.(count($academics['subjects']) === 1 ? 'subject is' : 'subjects are').' final.';
            }
            if ($qualification === null || $qualification->qualification->status !== QualificationStatus::Qualified) {
                $reasons[] = match ($qualification?->qualification->status) {
                    QualificationStatus::NotQualified => 'The candidate has not qualified: '.implode(' ', $qualification->qualification->reasons),
                    default => 'The candidate\'s qualification is still pending.',
                };
            }
        }

        return [
            'cgpa' => $cgpa,
            'finalGrade' => $finalGrade,
            'qualification' => $qualification?->qualification->toArray(),
            'rank' => $qualification?->rank,
            'classSize' => $classSize,
            'transcriptFinal' => $completed && $cgpa['complete'] && $finalGrade['complete'],
            'certificate' => ['eligible' => $reasons === [], 'reasons' => $reasons],
        ];
    }

    /**
     * Subjects by training phase, with each phase's average.
     *
     * @param  array<string, mixed>  $academics
     * @return list<array{phase: array<string, mixed>|null, average: float|null, complete: bool, units: string, subjects: list<array<string, mixed>>}>
     */
    private function phases(array $academics): array
    {
        $subjects = [];
        foreach ($academics['subjects'] as $subject) {
            $subjects[$subject['id']] = $subject;
        }

        return array_map(fn (array $phase): array => [
            'phase' => $phase['phase'],
            'average' => $phase['average'],
            'complete' => $phase['complete'],
            'units' => $phase['units'],
            'subjects' => array_map(function (array $entry) use ($subjects): array {
                $subject = $subjects[$entry['classSubjectId']];
                $result = $subject['result'];

                return [
                    'code' => $subject['code'],
                    'name' => $subject['name'],
                    'units' => $subject['units'],
                    'grade' => $result['grade'],
                    'remark' => self::remark($result),
                ];
            }, $phase['subjects']),
        ], $academics['course']['phases']);
    }

    /**
     * Passed, Failed, In progress or Incomplete, from the subject's standing.
     *
     * @param  array<string, mixed>  $result  SubjectGrade::toArray
     */
    private static function remark(array $result): string
    {
        if ($result['grade'] === null || $result['standing'] === null) {
            return 'Incomplete';
        }
        if ($result['status']['value'] !== GradeStatus::Complete->value) {
            return 'In progress';
        }

        return match (AcademicStanding::from($result['standing']['value'])) {
            AcademicStanding::Passing, AcademicStanding::AtRisk => 'Passed',
            AcademicStanding::Failing => 'Failed',
            AcademicStanding::Incomplete => 'Incomplete',
        };
    }

    /**
     * @return list<array{name: string, share: float|null, grade: float|null, status: string, mustPass: bool}>
     */
    private function areas(CandidateQualification $qualification): array
    {
        $totalWeight = array_sum(array_map(fn (AreaResult $result): float => $result->area->weight, $qualification->areas));

        return array_map(fn (AreaResult $result): array => [
            'name' => $result->area->name,
            'share' => $totalWeight > 0 && $result->area->weight > 0 ? round(100 * $result->area->weight / $totalWeight, 2) : null,
            'grade' => $result->grade,
            'status' => match ($result->status) {
                AreaStatus::Passed => 'Passed',
                AreaStatus::Failed => 'Not met',
                default => $result->status->label(),
            },
            'mustPass' => $result->area->mustPass,
        ], $qualification->areas);
    }
}
