<?php

namespace App\Http\Controllers\Staff;

use App\Enums\PerformanceSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\PerformanceAreaRequest;
use App\Models\PerformanceArea;
use App\Models\Subject;
use App\Services\Performance\PerformanceAreaService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Performance areas: weights, passing grades, must-pass rules, subject
 * mapping and the conduct rating rule (route middleware:
 * performance.configure). Areas are deactivated, never deleted.
 */
class PerformanceAreaController extends Controller
{
    public function __construct(private readonly PerformanceAreaService $areas) {}

    public function index(): Response
    {
        $areas = PerformanceArea::query()
            ->with(['subjects' => fn ($query) => $query->orderBy('code')])
            ->ordered()
            ->get();

        $active = $areas->where('is_active', true);

        return Inertia::render('staff/performance-areas/index', [
            'areas' => $areas->map(fn (PerformanceArea $area): array => [
                ...$this->present($area),
                'subjects' => $area->subjects->map(fn (Subject $subject): array => [
                    'id' => $subject->id,
                    'code' => $subject->code,
                    'name' => $subject->name,
                    'isActive' => $subject->is_active,
                ])->values()->all(),
            ])->values()->all(),
            // Weights are relative: the overall score divides by the total of the weights used.
            'activeWeightTotal' => round((float) $active->sum(fn (PerformanceArea $area): float => (float) $area->weight), 2),
            'hasActiveMustPass' => $active->contains('must_pass', true),
            // Active subjects whose grades count toward no area.
            'unmappedSubjects' => Subject::query()
                ->active()
                ->whereNull('performance_area_id')
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Subject $subject): array => ['id' => $subject->id, 'code' => $subject->code, 'name' => $subject->name])
                ->values()
                ->all(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('staff/performance-areas/create', [
            ...$this->formOptions(null),
            'nextSortOrder' => min(999, (int) PerformanceArea::query()->max('sort_order') + 1),
        ]);
    }

    public function store(PerformanceAreaRequest $request): RedirectResponse
    {
        $area = $this->areas->create($request->areaData(), $request->subjectIds());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Performance area {$area->name} created."]);

        return redirect()->route('performance-areas.index');
    }

    public function edit(PerformanceArea $performanceArea): Response
    {
        return Inertia::render('staff/performance-areas/edit', [
            'area' => [
                ...$this->present($performanceArea),
                'subjectIds' => $performanceArea->subjects()->orderBy('code')->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            ],
            ...$this->formOptions($performanceArea),
        ]);
    }

    public function update(PerformanceAreaRequest $request, PerformanceArea $performanceArea): RedirectResponse
    {
        $area = $this->areas->update($performanceArea, $request->areaData(), $request->subjectIds());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Performance area {$area->name} updated."]);

        return redirect()->route('performance-areas.index');
    }

    /**
     * Sources, every subject with the area it currently counts toward, and
     * which fitness, conduct and attendance areas are already active.
     *
     * @return array<string, mixed>
     */
    private function formOptions(?PerformanceArea $editing): array
    {
        $activeBySource = [];
        foreach (PerformanceSource::cases() as $source) {
            $other = $this->areas->activeAreaUsing($source, $editing?->id);
            if ($other !== null) {
                $activeBySource[$source->value] = $other->name;
            }
        }

        return [
            'sources' => PerformanceSource::options(),
            'subjects' => Subject::query()
                ->with('performanceArea:id,name')
                ->orderByDesc('is_active')
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'is_active', 'performance_area_id'])
                ->map(fn (Subject $subject): array => [
                    'id' => $subject->id,
                    'code' => $subject->code,
                    'name' => $subject->name,
                    'isActive' => $subject->is_active,
                    'area' => $subject->performanceArea === null ? null : ['id' => $subject->performanceArea->id, 'name' => $subject->performanceArea->name],
                ])
                ->values()
                ->all(),
            'activeSingleSourceAreas' => (object) $activeBySource,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PerformanceArea $area): array
    {
        return [
            'id' => $area->id,
            'name' => $area->name,
            'description' => $area->description,
            'source' => $area->source->toArray(),
            // Strings as stored ("40.00"), so forms show exactly what was saved.
            'weight' => (string) $area->weight,
            'passingGrade' => (string) $area->passing_grade,
            'mustPass' => $area->must_pass,
            'baseRating' => $area->base_rating === null ? null : (string) $area->base_rating,
            'meritValue' => $area->merit_value === null ? null : (string) $area->merit_value,
            'demeritValue' => $area->demerit_value === null ? null : (string) $area->demerit_value,
            'sortOrder' => $area->sort_order,
            'isActive' => $area->is_active,
        ];
    }
}
