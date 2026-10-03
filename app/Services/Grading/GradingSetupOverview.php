<?php

namespace App\Services\Grading;

use App\Enums\PerformanceSource;
use App\Models\AcademicPeriod;
use App\Models\AssessmentCategory;
use App\Models\ClassSubject;
use App\Models\ConductType;
use App\Models\FitnessEvent;
use App\Models\PerformanceArea;
use App\Models\Subject;
use App\Support\CampusScope;
use App\Support\DecimalValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read model of the Grading Setup page: every setting that decides a
 * candidate's grades, standing and qualification, gathered in the order the
 * system applies them, with what is still missing. It only reads; each
 * setting is still changed on its own page and service.
 *
 * The worked example is calculated by GradeCalculationService itself, so
 * the explanation can never drift from the real rule.
 */
final class GradingSetupOverview
{
    private const COPY_SOURCE_LIMIT = 60;

    /** Sample component results of the worked example (percent). */
    private const SAMPLE_RESULTS = [90, 80, 85, 70, 95, 75, 88, 82, 78, 92];

    public function __construct(private readonly GradeCalculationService $calculator) {}

    /**
     * Periods that can be chosen, active first, then the newest.
     *
     * @return list<array{id: int, name: string, isActive: bool}>
     */
    public function periods(): array
    {
        return AcademicPeriod::query()
            ->orderByDesc('is_active')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (AcademicPeriod $period): array => ['id' => $period->id, 'name' => $period->name, 'isActive' => $period->is_active])
            ->values()
            ->all();
    }

    /**
     * The subjects of every class of the period with their weights, ordered
     * by class and subject.
     *
     * @return list<array{classSubjectId: int, classBatch: array{id: int, name: string}, subject: array{id: int, code: string, name: string}, phase: array{id: int, number: int, name: string}|null, units: string, components: list<array{name: string, weight: string}>, assessmentCount: int, finalizedCount: int}>
     */
    public function subjectWeights(AcademicPeriod $period, ?CampusScope $campus = null): array
    {
        return $this->offeringsOf($period, $campus)
            ->map(fn (ClassSubject $offering): array => [
                'classSubjectId' => $offering->id,
                'classBatch' => ['id' => $offering->classBatch->id, 'name' => $offering->classBatch->name],
                'subject' => ['id' => $offering->subject->id, 'code' => $offering->subject->code, 'name' => $offering->subject->name],
                'phase' => $offering->trainingPhase?->toSummary(),
                'units' => DecimalValue::display($offering->units),
                'components' => $this->components($offering->assessmentCategories),
                'assessmentCount' => (int) $offering->assessments_count,
                'finalizedCount' => (int) $offering->finalized_count,
            ])
            ->values()
            ->all();
    }

    /**
     * Subjects whose weights can be copied: every class subject that has
     * weights, those of the given period first, then the newest periods.
     *
     * @return list<array{classSubjectId: int, label: string, components: list<array{name: string, weight: string}>}>
     */
    public function copySources(?int $preferredPeriodId, ?int $exceptClassSubjectId = null, ?CampusScope $campus = null): array
    {
        $campus ??= CampusScope::everyCampus();
        $labelsCampuses = $campus->labelsCampuses();

        return $campus->constrain(ClassSubject::query(), 'class_subjects.campus_id')
            ->select('class_subjects.*')
            ->join('class_batches', 'class_batches.id', '=', 'class_subjects.class_batch_id')
            ->join('academic_periods', 'academic_periods.id', '=', 'class_batches.academic_period_id')
            ->join('subjects', 'subjects.id', '=', 'class_subjects.subject_id')
            ->whereHas('assessmentCategories')
            ->when($exceptClassSubjectId !== null, fn (Builder $query) => $query->whereKeyNot($exceptClassSubjectId))
            ->with(['classBatch.academicPeriod', 'classBatch.campus:id,code', 'subject', 'assessmentCategories'])
            ->orderByRaw('case when class_batches.academic_period_id = ? then 0 else 1 end', [$preferredPeriodId ?? 0])
            ->orderByDesc('academic_periods.starts_on')
            ->orderBy('class_batches.name')
            ->orderBy('subjects.name')
            ->limit(self::COPY_SOURCE_LIMIT)
            ->get()
            ->map(fn (ClassSubject $offering): array => [
                'classSubjectId' => $offering->id,
                'label' => $campus->classLabel($offering->classBatch->name, $offering->classBatch->campus?->code, $labelsCampuses)." · {$offering->subject->name} ({$offering->classBatch->academicPeriod->name})",
                'components' => $this->components($offering->assessmentCategories),
            ])
            ->values()
            ->all();
    }

    /**
     * Performance areas with each weight's share of the total, and the
     * problems that would make qualification wrong or always Pending.
     *
     * @return array{list: list<array<string, mixed>>, totalWeight: float, hasMustPass: bool, emptySubjectAreas: list<string>, unmappedSubjects: list<string>}
     */
    public function areas(?AcademicPeriod $period, ?CampusScope $campus = null): array
    {
        $campus ??= CampusScope::everyCampus();
        $areas = PerformanceArea::query()->active()->ordered()->with(['subjects' => fn ($subjects) => $subjects->orderBy('name')])->get();
        $total = (float) $areas->sum(fn (PerformanceArea $area): float => (float) $area->weight);

        // Subjects taught in the period whose grades reach no active area.
        $activeSubjectAreaIds = $areas->where('source', PerformanceSource::Subjects)->modelKeys();
        $unmapped = $period === null ? [] : Subject::query()
            ->whereHas('classSubjects.classBatch', fn (Builder $classes) => $campus->constrain($classes->where('academic_period_id', $period->id), 'class_batches.campus_id'))
            ->where(fn (Builder $subjects) => $subjects->whereNull('performance_area_id')->orWhereNotIn('performance_area_id', $activeSubjectAreaIds ?: [0]))
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return [
            'list' => $areas->map(fn (PerformanceArea $area): array => [
                'id' => $area->id,
                'name' => $area->name,
                'source' => ['value' => $area->source->value, 'label' => $area->source->label()],
                'weight' => DecimalValue::display($area->weight),
                // The share the overall score really gives the area: weights are relative.
                'share' => $total > 0 ? round((float) $area->weight / $total * 100, 1) : 0.0,
                'passingGrade' => DecimalValue::display($area->passing_grade),
                'mustPass' => $area->must_pass,
                'subjects' => $area->subjects->pluck('name')->values()->all(),
                'conductRule' => $area->source === PerformanceSource::Conduct ? [
                    'base' => DecimalValue::display($area->base_rating),
                    'merit' => DecimalValue::display($area->merit_value),
                    'demerit' => DecimalValue::display($area->demerit_value),
                ] : null,
            ])->values()->all(),
            'totalWeight' => round($total, 2),
            'hasMustPass' => $areas->contains('must_pass', true),
            'emptySubjectAreas' => $areas
                ->filter(fn (PerformanceArea $area): bool => $area->source === PerformanceSource::Subjects && $area->subjects->isEmpty())
                ->pluck('name')
                ->values()
                ->all(),
            'unmappedSubjects' => $unmapped,
        ];
    }

    /**
     * @return array{fitnessEvents: int, conductTypes: int}
     */
    public function sources(): array
    {
        return [
            'fitnessEvents' => FitnessEvent::query()->active()->count(),
            'conductTypes' => ConductType::query()->active()->count(),
        ];
    }

    /**
     * How one subject grade is worked out, with sample results, calculated
     * by GradeCalculationService. Uses the period's first subject with
     * weights, or a generic two-component example.
     *
     * @return array<string, mixed>
     */
    public function workedExample(?AcademicPeriod $period, ?CampusScope $campus = null): array
    {
        $offering = $period === null ? null : $this->offeringsOf($period, $campus)->first(fn (ClassSubject $offering): bool => $offering->assessmentCategories->isNotEmpty());

        $weights = $offering === null
            ? [new CategoryWeight(1, 'Quizzes', 40.0), new CategoryWeight(2, 'Examinations', 60.0)]
            : $offering->assessmentCategories->values()->map(fn (AssessmentCategory $category): CategoryWeight => new CategoryWeight($category->id, $category->name, (float) $category->weight))->all();

        // One finalized assessment out of 100 per component, with a sample result.
        $assessments = [];
        $scores = [];
        foreach ($weights as $index => $weight) {
            $assessments[] = new CountedAssessment($index + 1, $weight->id, 100.0);
            $scores[$index + 1] = (float) self::SAMPLE_RESULTS[$index % count(self::SAMPLE_RESULTS)];
        }

        $thresholds = $period === null ? null : GradingThresholds::forPeriod($period);
        $full = $this->calculator->subjectGrade($weights, $assessments, $scores, $thresholds);

        // The same results while only the first half of the components is assessed.
        $assessedCount = max(1, intdiv(count($weights), 2));
        $partial = count($weights) > 1
            ? $this->calculator->subjectGrade($weights, array_slice($assessments, 0, $assessedCount), $scores, $thresholds)
            : null;

        return [
            'source' => $offering === null ? null : "{$offering->classBatch->name} · {$offering->subject->name}",
            'components' => array_map(fn (CategoryGrade $category): array => [
                'name' => $category->name,
                'weight' => $category->weight,
                'result' => $category->percentage,
                'points' => $category->weightedScore,
            ], $full->categories),
            'grade' => $full->grade,
            'standing' => $full->standing?->toArray(),
            'partial' => $partial === null ? null : [
                'assessed' => array_map(fn (CategoryWeight $weight): string => $weight->name, array_slice($weights, 0, $assessedCount)),
                'assessedWeight' => $partial->assessedWeight,
                'grade' => $partial->grade,
            ],
        ];
    }

    /**
     * @return Collection<int, ClassSubject>
     */
    private function offeringsOf(AcademicPeriod $period, ?CampusScope $campus = null): Collection
    {
        return ($campus ?? CampusScope::everyCampus())->constrain(ClassSubject::query(), 'class_subjects.campus_id')
            ->select('class_subjects.*')
            ->join('class_batches', 'class_batches.id', '=', 'class_subjects.class_batch_id')
            ->join('subjects', 'subjects.id', '=', 'class_subjects.subject_id')
            ->where('class_batches.academic_period_id', $period->id)
            ->with(['classBatch:id,name,academic_period_id', 'subject:id,code,name', 'trainingPhase:id,number,name,starts_on,ends_on', 'assessmentCategories'])
            ->withCount([
                'assessments',
                'assessments as finalized_count' => fn (Builder $assessments) => $assessments->finalized(),
            ])
            ->orderBy('class_batches.name')
            ->orderBy('subjects.name')
            ->get();
    }

    /**
     * @param  iterable<AssessmentCategory>  $categories
     * @return list<array{name: string, weight: string}>
     */
    private function components(iterable $categories): array
    {
        $components = [];
        foreach ($categories as $category) {
            $components[] = ['name' => $category->name, 'weight' => DecimalValue::display($category->weight)];
        }

        return $components;
    }
}
