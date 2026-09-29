<?php

namespace App\Services\Monitoring;

use App\Enums\AcademicStanding;
use App\Models\ClassSubject;
use App\Services\Grading\SubjectGrade;

/**
 * The single definition of a "concern" (AGENTS.md §16): a subject in which a
 * candidate is Failing, At Risk, or Incomplete, or has missing scores. Used
 * by the monitoring table, the dashboards, and the candidate profile's
 * Current Warnings, so all three always agree.
 */
final class SubjectConcerns
{
    private const CONCERNING = [AcademicStanding::Failing, AcademicStanding::AtRisk, AcademicStanding::Incomplete];

    /**
     * Most serious first (Failing, At Risk, Incomplete, then subjects that
     * only have missing scores), then lowest grade, then subject name.
     *
     * @param  list<array{offering: ClassSubject, grade: SubjectGrade}>  $subjects
     * @return list<array{offering: ClassSubject, grade: SubjectGrade}>
     */
    public static function of(array $subjects): array
    {
        $concerns = array_values(array_filter(
            $subjects,
            fn (array $subject): bool => in_array($subject['grade']->standing, self::CONCERNING, true) || $subject['grade']->missingScores > 0,
        ));

        usort($concerns, function (array $a, array $b): int {
            $severity = ($b['grade']->standing?->severity() ?? -1) <=> ($a['grade']->standing?->severity() ?? -1);
            if ($severity !== 0) {
                return $severity;
            }

            $gradeA = $a['grade']->grade;
            $gradeB = $b['grade']->grade;
            $byGrade = match (true) {
                $gradeA === null && $gradeB === null => 0,
                $gradeA === null => 1,
                $gradeB === null => -1,
                default => $gradeA <=> $gradeB,
            };

            return $byGrade !== 0 ? $byGrade : strcmp($a['offering']->subject->name, $b['offering']->subject->name);
        });

        return $concerns;
    }

    /**
     * Page payload of one concern: the subject, its grade and standing, and
     * missing scores. No comments or individual scores.
     *
     * @param  array{offering: ClassSubject, grade: SubjectGrade}  $concern
     * @return array{classSubjectId: int, subject: string, grade: float|null, standing: array{value: string, label: string, tone: string}|null, missingScores: int, isProvisional: bool}
     */
    public static function present(array $concern): array
    {
        return [
            'classSubjectId' => $concern['offering']->id,
            'subject' => $concern['offering']->subject->name,
            'grade' => $concern['grade']->grade,
            'standing' => $concern['grade']->standing?->toArray(),
            'missingScores' => $concern['grade']->missingScores,
            'isProvisional' => $concern['grade']->isProvisional(),
        ];
    }
}
