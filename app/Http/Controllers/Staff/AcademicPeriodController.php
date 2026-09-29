<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\AcademicPeriodRequest;
use App\Models\AcademicPeriod;
use App\Services\AcademicPeriodService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AcademicPeriodController extends Controller
{
    public function __construct(private readonly AcademicPeriodService $periods) {}

    public function index(): Response
    {
        $periods = AcademicPeriod::query()
            ->withCount('classBatches')
            ->orderByDesc('starts_on')
            ->get()
            ->map(fn (AcademicPeriod $period): array => [
                ...$this->present($period),
                'classCount' => (int) $period->class_batches_count,
            ])
            ->all();

        return Inertia::render('staff/academic-periods/index', ['periods' => $periods]);
    }

    public function create(): Response
    {
        return Inertia::render('staff/academic-periods/create');
    }

    public function store(AcademicPeriodRequest $request): RedirectResponse
    {
        $period = $this->periods->create($request->periodData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Academic period {$period->name} created."]);

        return redirect()->route('academic-periods.index');
    }

    public function edit(AcademicPeriod $academicPeriod): Response
    {
        return Inertia::render('staff/academic-periods/edit', ['period' => $this->present($academicPeriod)]);
    }

    public function update(AcademicPeriodRequest $request, AcademicPeriod $academicPeriod): RedirectResponse
    {
        $this->periods->update($academicPeriod, $request->periodData());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Academic period updated.']);

        return redirect()->route('academic-periods.index');
    }

    public function activate(AcademicPeriod $academicPeriod): RedirectResponse
    {
        $this->periods->activate($academicPeriod);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$academicPeriod->name} is now the active academic period."]);

        return redirect()->route('academic-periods.index');
    }

    /**
     * @return array{id: int, name: string, startsOn: string, endsOn: string, isActive: bool}
     */
    private function present(AcademicPeriod $period): array
    {
        return [
            'id' => $period->id,
            'name' => $period->name,
            'startsOn' => $period->starts_on->toDateString(),
            'endsOn' => $period->ends_on->toDateString(),
            'isActive' => $period->is_active,
        ];
    }
}
