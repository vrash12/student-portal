<?php

namespace App\Services\Grading;

use App\Enums\AcademicStanding;
use App\Models\Assessment;
use App\Models\AssessmentCategory;
use App\Models\AssessmentScore;
use App\Models\Candidate;
use App\Models\ClassSubject;
use App\Support\DecimalValue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
 * 6. Academic standing compares the grade with the passing and warning
 *    grades of the class's academic period (see standing()).
 *
 * Results are rounded half up to two decimals only at the end; intermediate
 * values stay unrounded.
 *
 * Nothing is stored: grades and standings are calculated when read, so any
 * change to scores, finalization, weights, or thresholds shows immediately.
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
     * @param  GradingThresholds|null  $thresholds  of the class's academic period; null means no standing
     *                                              (required so no caller drops standing by omission)
     */
    public function subjectGrade(array $categories, array $assessments, array $scores, ?GradingThresholds $thresholds): SubjectGrade
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

        $grade = $assessedWeight > 0 ? self::round($weightedSum / $assessedWeight * 100) : null;

        return new SubjectGrade(
            categories: $categoryGrades,
            grade: $grade,
            assessedWeight: self::round($assessedWeight),
            missingScores: $missingScores,
            pendingCategories: $pendingCategories,
            standing: $thresholds === null ? null : $this->standing($grade, $missingScores, $thresholds),
        );
    }

    /**
     * Academic standing in one subject (AGENTS.md §16):
     *
     * - grade below the passing grade: Failing;
     * - at or above the passing grade but below the warning grade: At Risk;
     * - at or above the warning grade: Passing, or Incomplete when scores are
     *   missing, because Passing is never claimed while records are missing;
     * - no grade yet: Incomplete when scores are missing, otherwise no
     *   standing (nothing to judge).
     *
     * Failing and At Risk are kept when scores are missing, so a missing
     * record never hides an early warning. A provisional grade (not every
     * category assessed yet) gets a current standing for the same reason.
     *
     * The grade is compared as displayed (rounded to two decimals) and in
     * whole hundredths, so a grade shown as 75.00 is never Failing at a
     * passing grade of 75.
     */
    public function standing(?float $grade, int $missingScores, GradingThresholds $thresholds): ?AcademicStanding
    {
        if ($grade === null) {
            return $missingScores > 0 ? AcademicStanding::Incomplete : null;
        }

        $hundredths = DecimalValue::toHundredths(self::round($grade));

        $standing = match (true) {
            $hundredths < $thresholds->passingHundredths => AcademicStanding::Failing,
            $hundredths < $thresholds->warningHundredths => AcademicStanding::AtRisk,
            default => AcademicStanding::Passing,
        };

        return $standing === AcademicStanding::Passing && $missingScores > 0 ? AcademicStanding::Incomplete : $standing;
    }

    /**
     * Overall standing across the given subjects: the most serious one
     * (Failing, then At Risk, then Incomplete, then Passing). Subjects
     * without a standing are ignored but counted, so the result says how
     * many subjects it rests on.
     *
     * The rule is "most serious", not an average of grades, so failing one
     * subject is never hidden by good results elsewhere. Pending owner
     * confirmation (see SESSION_HANDOFF.md).
     *
     * @param  array<array-key, SubjectGrade>  $subjectGrades
     */
    public function overallStanding(array $subjectGrades): OverallStanding
    {
        $overall = null;
        $basedOn = 0;
        $isProvisional = false;

        foreach ($subjectGrades as $subjectGrade) {
            $standing = $subjectGrade->standing;
            if ($standing === null) {
                continue;
            }

            $basedOn++;
            $isProvisional = $isProvisional || $subjectGrade->isProvisional();
            if ($overall === null || $standing->severity() > $overall->severity()) {
                $overall = $standing;
            }
        }

        return new OverallStanding($overall, $basedOn, count($subjectGrades), $isProvisional);
    }

    /**
     * Grades of several candidates in one class subject, loaded with a fixed
     * number of queries regardless of the number of candidates.
     *
     * Standing is decided only for candidates who can be graded in the class
     * (assigned to it and not withdrawn). Others keep their grades but get
     * no standing: every assessment finalized after they left would count
     * as a missing score, so a standing would drift without meaning.
     *
     * @param  list<int>  $candidateIds
     * @return array<int, SubjectGrade> keyed by candidate id
     */
    public function forOffering(ClassSubject $offering, array $candidateIds): array
    {
        $categories = $this->scheme($offering);
        $assessments = $this->countedAssessments($offering);
        $scores = $this->scoresByCandidate($assessments, $candidateIds);
        $thresholds = $this->thresholdsFor($offering);

        $monitored = $thresholds === null || $candidateIds === [] ? [] : array_flip(
            Candidate::query()->whereKey($candidateIds)->gradableIn($offering->class_batch_id)->pluck('id')->all(),
        );

        $grades = [];
        foreach ($candidateIds as $candidateId) {
            $grades[$candidateId] = $this->subjectGrade(
                $categories,
                $assessments,
                $scores[$candidateId] ?? [],
                isset($monitored[$candidateId]) ? $thresholds : null,
            );
        }

        return $grades;
    }

    /**
     * The passing and warning grades that apply to a class subject: those of
     * its class's academic period.
     */
    public function thresholdsFor(ClassSubject $offering): ?GradingThresholds
    {
        $offering->loadMissing('classBatch.academicPeriod');

        return GradingThresholds::forPeriod($offering->classBatch->academicPeriod);
    }

    /**
     * One candidate's grades in several class subjects, loaded with a fixed
     * number of queries regardless of the number of subjects. Standing
     * follows the same rule as forOffering(): only in a class the candidate
     * can currently be graded in.
     *
     * @param  list<int>  $classSubjectIds
     * @return array<int, SubjectGrade> keyed by class subject id
     */
    public function forCandidate(Candidate $candidate, array $classSubjectIds): array
    {
        if ($classSubjectIds === []) {
            return [];
        }

        // Each class subject takes the thresholds of its class's period.
        $thresholds = DB::table('class_subjects')
            ->join('class_batches', 'class_batches.id', '=', 'class_subjects.class_batch_id')
            ->join('academic_periods', 'academic_periods.id', '=', 'class_batches.academic_period_id')
            ->whereIn('class_subjects.id', $classSubjectIds)
            ->get(['class_subjects.id', 'class_subjects.class_batch_id', 'academic_periods.passing_grade', 'academic_periods.warning_grade'])
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->id => $candidate->isGradableIn((int) $row->class_batch_id)
                    ? GradingThresholds::fromStored($row->passing_grade, $row->warning_grade)
                    : null,
            ]);

        $categories = $this->categoriesFor($classSubjectIds);
        $assessments = $this->finalizedAssessmentsFor($classSubjectIds);

        $scores = [];
        if ($assessments->isNotEmpty()) {
            AssessmentScore::query()
                ->where('candidate_id', $candidate->id)
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
                $thresholds->get($classSubjectId),
            );
        }

        return $grades;
    }

    /**
     * Grades of many candidates across many class subjects (monitoring), in
     * a fixed number of queries however many there are. Each candidate is
     * calculated only in the class subjects of their own class; standing
     * follows forOffering() (gradable candidates only, the thresholds of the
     * class's period).
     *
     * @param  EloquentCollection<int, ClassSubject>  $offerings
     * @param  EloquentCollection<int, Candidate>  $candidates
     * @return array<int, array<int, SubjectGrade>> candidate id => class subject id => grade
     */
    public function forClasses(EloquentCollection $offerings, EloquentCollection $candidates): array
    {
        if ($offerings->isEmpty() || $candidates->isEmpty()) {
            return [];
        }

        $offerings->loadMissing('classBatch.academicPeriod');
        $offeringIds = $offerings->modelKeys();
        $categories = $this->categoriesFor($offeringIds);
        $assessments = $this->finalizedAssessmentsFor($offeringIds);

        // Plain rows (no models): a whole period can mean tens of thousands
        // of scores. Filtered by assessment only; other candidates' rows are
        // skipped below.
        $population = array_flip($candidates->modelKeys());
        $scores = [];
        if ($assessments->isNotEmpty()) {
            AssessmentScore::query()
                ->toBase()
                ->whereIn('assessment_id', $assessments->flatten()->pluck('id')->all())
                ->whereNotNull('score')
                ->get(['assessment_id', 'candidate_id', 'score'])
                ->each(function (object $row) use (&$scores, $population): void {
                    if (isset($population[$row->candidate_id])) {
                        $scores[(int) $row->candidate_id][(int) $row->assessment_id] = (float) $row->score;
                    }
                });
        }

        $schemes = [];
        $counted = [];
        $thresholds = [];
        foreach ($offerings as $offering) {
            $schemes[$offering->id] = array_map(self::categoryWeight(...), $categories->get($offering->id)?->all() ?? []);
            $counted[$offering->id] = array_map(self::countedAssessment(...), $assessments->get($offering->id)?->all() ?? []);
            $thresholds[$offering->id] = GradingThresholds::forPeriod($offering->classBatch->academicPeriod);
        }

        $byClass = $offerings->groupBy('class_batch_id');
        $grades = [];
        foreach ($candidates as $candidate) {
            foreach ($byClass->get($candidate->class_batch_id, collect()) as $offering) {
                $grades[$candidate->id][$offering->id] = $this->subjectGrade(
                    $schemes[$offering->id],
                    $counted[$offering->id],
                    $scores[$candidate->id] ?? [],
                    $candidate->isGradableIn($offering->class_batch_id) ? $thresholds[$offering->id] : null,
                );
            }
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
     * Grading categories of several class subjects, in scheme order.
     *
     * @param  list<int>  $classSubjectIds
     * @return Collection<int, Collection<int, AssessmentCategory>> keyed by class subject id
     */
    private function categoriesFor(array $classSubjectIds): Collection
    {
        return AssessmentCategory::query()
            ->whereIn('class_subject_id', $classSubjectIds)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'class_subject_id', 'name', 'weight'])
            ->groupBy('class_subject_id');
    }

    /**
     * Finalized assessments of several class subjects.
     *
     * @param  list<int>  $classSubjectIds
     * @return Collection<int, Collection<int, Assessment>> keyed by class subject id
     */
    private function finalizedAssessmentsFor(array $classSubjectIds): Collection
    {
        return Assessment::query()
            ->whereIn('class_subject_id', $classSubjectIds)
            ->finalized()
            ->get(['id', 'class_subject_id', 'assessment_category_id', 'max_score'])
            ->groupBy('class_subject_id');
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
