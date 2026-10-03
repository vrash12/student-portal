<?php

namespace App\Services\Performance;

use App\Models\AcademicPeriod;
use App\Models\ClassBatch;
use App\Support\CampusScope;

/**
 * Qualification across every class of the active academic period, for the
 * administrator dashboard (performance.view). The results and summaries
 * come from QualificationEngine; this class only gathers the period's
 * classes. A fixed number of queries per class, whatever its size.
 */
final class QualificationOverview
{
    public function __construct(private readonly QualificationEngine $engine) {}

    /**
     * Null without an active period. `configured` is false while no area is
     * active: nothing is evaluated then, as nobody can be qualified yet.
     *
     * @return array{period: array{id: int, name: string}, configured: bool, classCount: int, counts: array{total: int, qualified: int, notQualified: int, pending: int}, mostCommonUnmet: array{areaId: int, name: string, count: int}|null}|null
     */
    public function activePeriod(?CampusScope $campus = null): ?array
    {
        $campus ??= CampusScope::everyCampus();
        $period = AcademicPeriod::query()->active()->first();
        if ($period === null) {
            return null;
        }

        // Classes of the campus scope; ranks stay within each class.
        $classes = $campus->constrain(ClassBatch::query(), 'class_batches.campus_id')->where('academic_period_id', $period->id)->orderBy('name')->orderBy('id')->get();
        $areas = $this->engine->activeAreas();

        $qualifications = [];
        if ($areas !== []) {
            foreach ($classes as $class) {
                $class->setRelation('academicPeriod', $period);
                array_push($qualifications, ...$this->engine->forClass($class));
            }
        }

        return [
            'period' => ['id' => $period->id, 'name' => $period->name],
            'configured' => $areas !== [],
            'classCount' => $classes->count(),
            'counts' => $this->engine->counts($qualifications),
            'mostCommonUnmet' => $this->engine->mostCommonUnmet($qualifications, $areas),
        ];
    }
}
