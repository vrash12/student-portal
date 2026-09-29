<?php

namespace App\Services\Grading;

use App\Models\Assessment;
use App\Models\AssessmentCategory;
use App\Models\AssessmentScore;
use App\Models\ClassSubject;

/**
 * The single authoritative grade calculation (AGENTS.md §15). Controllers,
 * reports, and the frontend display these results; they never recompute them.
 *
 * Method, applied per candidate and subject:
 *
 * 1. Only finalized assessments count.
 * 2. Category percentage = raw points earned / points possible × 100, over
 *    the category's finalized assessments that the candidate has a score for
 *    (so larger assessments carry more weight within a category).
 * 3. Weighted score = category percentage × category weight / 100.
 * 4. Grade = sum of weighted scores / sum of the weights of the categories
 *    scored so far × 100. Before every category is assessed this is the
 *    current (provisional) grade; afterwards it is the final grade.
 * 5. Missing scores are never treated as zero. They are counted and reported
 *    (GradeStatus::MissingScores) so the grade is not silently lowered.
 *
 * Results are rounded half up to two decimals only at the end; intermediate
 * values stay unrounded.
 */
final class GradeCalculationService
{
    public const DECIMALS = 2;

    /**
     * Percentage of a single score, e.g. 45 of 50 = 90.00.
     */
    public function scorePercentage(float $score, float $maxScore): float
    {
        return self::round($score / $maxScore * 100);
    }

    /**
     * @param  list<CategoryWeight>  $categories  the grading scheme
     * @param  list<CountedAssessment>  $assessments  finalized assessments of the subject
     * @param  array<int, float|null>  $scores  raw score by assessment id; a missing key or null means no score
     */
    public function subjectGrade(array $categories, array $assessments, array $scores): SubjectGrade
    {
        $byCategory = [];
        foreach ($assessments as $assessment) {
            $byCategory[$assessment->categoryId][] = $assessment;
        }

        $categoryGrades = [];
        $weightedSum = 0.0;
        $assessedWeight = 0.0;
        $missingScores = 0;
        $pendingCategories = 0;

        foreach ($categories as $category) {
            $categoryAssessments = $byCategory[$category->id] ?? [];
            $earned = 0.0;
            $possible = 0.0;
            $scoredCount = 0;

            foreach ($categoryAssessments as $assessment) {
                $score = $scores[$assessment->id] ?? null;
                if ($score === null) {
                    continue;
                }

                $earned += $score;
                $possible += $assessment->maxScore;
                $scoredCount++;
            }

            $missingScores += count($categoryAssessments) - $scoredCount;
            if ($categoryAssessments === []) {
                $pendingCategories++;
            }

            $percentage = null;
            $weightedScore = null;
            if ($scoredCount > 0) {
                $percentage = $earned / $possible * 100;
                $weightedScore = $percentage * $category->weight / 100;
                $weightedSum += $weightedScore;
                $assessedWeight += $category->weight;
            }

            $categoryGrades[] = new CategoryGrade(
                categoryId: $category->id,
                name: $category->name,
                weight: $category->weight,
                assessmentCount: count($categoryAssessments),
                scoredCount: $scoredCount,
                earned: self::round($earned),
                possible: self::round($possible),
                percentage: $percentage === null ? null : self::round($percentage),
                weightedScore: $weightedScore === null ? null : self::round($weightedScore),
            );
        }

        return new SubjectGrade(
            categories: $categoryGrades,
            grade: $assessedWeight > 0 ? self::round($weightedSum / $assessedWeight * 100) : null,
            assessedWeight: self::round($assessedWeight),
            missingScores: $missingScores,
            pendingCategories: $pendingCategories,
        );
    }

    /**
     * Grades of several candidates in one class subject, loaded with three
     * queries regardless of the number of candidates.
     *
     * @param  list<int>  $candidateIds
     * @return array<int, SubjectGrade> keyed by candidate id
     */
    public function forOffering(ClassSubject $offering, array $candidateIds): array
    {
        $categories = $this->scheme($offering);
        $assessments = $this->countedAssessments($offering);
        $scores = $this->scoresByCandidate($assessments, $candidateIds);

        $grades = [];
        foreach ($candidateIds as $candidateId) {
            $grades[$candidateId] = $this->subjectGrade($categories, $assessments, $scores[$candidateId] ?? []);
        }

        return $grades;
    }

    /**
     * One candidate's grades in several class subjects, loaded with three
     * queries regardless of the number of subjects.
     *
     * @param  list<int>  $classSubjectIds
     * @return array<int, SubjectGrade> keyed by class subject id
     */
    public function forCandidate(int $candidateId, array $classSubjectIds): array
    {
        if ($classSubjectIds === []) {
            return [];
        }

        $categories = AssessmentCategory::query()
            ->whereIn('class_subject_id', $classSubjectIds)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'class_subject_id', 'name', 'weight'])
            ->groupBy('class_subject_id');

        $assessments = Assessment::query()
            ->whereIn('class_subject_id', $classSubjectIds)
            ->finalized()
            ->get(['id', 'class_subject_id', 'assessment_category_id', 'max_score'])
            ->groupBy('class_subject_id');

        $scores = [];
        if ($assessments->isNotEmpty()) {
            AssessmentScore::query()
                ->where('candidate_id', $candidateId)
                ->whereIn('assessment_id', $assessments->flatten()->pluck('id')->all())
                ->whereNotNull('score')
                ->get(['assessment_id', 'score'])
                ->each(function (AssessmentScore $score) use (&$scores): void {
                    $scores[$score->assessment_id] = (float) $score->score;
                });
        }

        $grades = [];
        foreach ($classSubjectIds as $classSubjectId) {
            $grades[$classSubjectId] = $this->subjectGrade(
                array_map(self::categoryWeight(...), $categories->get($classSubjectId)?->all() ?? []),
                array_map(self::countedAssessment(...), $assessments->get($classSubjectId)?->all() ?? []),
                $scores,
            );
        }

        return $grades;
    }

    /**
     * @return list<CategoryWeight>
     */
    public function scheme(ClassSubject $offering): array
    {
        return $offering->assessmentCategories()
            ->get(['id', 'name', 'weight'])
            ->map(self::categoryWeight(...))
            ->values()
            ->all();
    }

    /**
     * Rounds half up to two decimals. Values are first rounded to ten
     * decimals to remove binary floating-point noise (for example
     * 84.49499999999999 for a true 84.495), so results are reproducible.
     */
    public static function round(float $value): float
    {
        return round(round($value, 10), self::DECIMALS);
    }

    /**
     * @return list<CountedAssessment>
     */
    private function countedAssessments(ClassSubject $offering): array
    {
        return $offering->assessments()
            ->finalized()
            ->get(['id', 'assessment_category_id', 'max_score'])
            ->map(self::countedAssessment(...))
            ->values()
            ->all();
    }

    private static function categoryWeight(AssessmentCategory $category): CategoryWeight
    {
        return new CategoryWeight($category->id, $category->name, (float) $category->weight);
    }

    private static function countedAssessment(Assessment $assessment): CountedAssessment
    {
        return new CountedAssessment($assessment->id, (int) $assessment->assessment_category_id, (float) $assessment->max_score);
    }

    /**
     * @param  list<CountedAssessment>  $assessments
     * @param  list<int>  $candidateIds
     * @return array<int, array<int, float>> candidate id => assessment id => score
     */
    private function scoresByCandidate(array $assessments, array $candidateIds): array
    {
        if ($assessments === [] || $candidateIds === []) {
            return [];
        }

        $scores = [];
        AssessmentScore::query()
            ->whereIn('assessment_id', array_map(fn (CountedAssessment $assessment): int => $assessment->id, $assessments))
            ->whereIn('candidate_id', $candidateIds)
            ->whereNotNull('score')
            ->get(['assessment_id', 'candidate_id', 'score'])
            ->each(function (AssessmentScore $score) use (&$scores): void {
                $scores[$score->candidate_id][$score->assessment_id] = (float) $score->score;
            });

        return $scores;
    }
}
