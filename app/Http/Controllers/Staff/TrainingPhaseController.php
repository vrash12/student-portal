<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\TrainingPhaseRequest;
use App\Models\AcademicPeriod;
use App\Models\TrainingPhase;
use App\Services\TrainingPhaseService;
use App\Support\QueryFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Training phases of each academic year (route middleware:
 * academic_periods.manage). The whole course lasts one year, so a phase
 * belongs to one year and its dates lie inside it. Subjects are placed in a
 * phase on their class's page.
 */
class TrainingPhaseController extends Controller
{
    public function __construct(private readonly TrainingPhaseService $phases) {}

    public function index(Request $request): Response
    {
        $periods = AcademicPeriod::query()->orderByDesc('is_active')->orderByDesc('starts_on')->orderByDesc('id')->get();
        $requested = (int) QueryFilters::id($request, 'period');
        // The requested year, else the active (first) one.
        $period = $periods->firstWhere('id', $requested) ?? $periods->first();

        $phases = $period === null ? collect() : $period->trainingPhases()->withCount('classSubjects')->ordered()->get();
        $last = $phases->last();
        // The next phase: the following number, from the day after the last phase to the end of the year.
        $nextStart = $period === null ? null : ($last === null ? $period->starts_on : $last->ends_on->addDay());

        return Inertia::render('staff/training-phases/index', [
            'periods' => $periods->map(fn (AcademicPeriod $option): array => [
                'id' => $option->id,
                'name' => $option->name,
                'isActive' => $option->is_active,
            ])->values()->all(),
            'period' => $period === null ? null : [
                'id' => $period->id,
                'name' => $period->name,
                'startsOn' => $period->starts_on->toDateString(),
                'endsOn' => $period->ends_on->toDateString(),
            ],
            'phases' => $phases->map(fn (TrainingPhase $phase): array => [
                ...$phase->toSummary(),
                'subjectCount' => (int) $phase->class_subjects_count,
            ])->values()->all(),
            'next' => $period === null || $nextStart === null || $nextStart->gt($period->ends_on) ? null : [
                'number' => min(TrainingPhase::MAX_NUMBER, (int) $phases->max('number') + 1),
                'startsOn' => $nextStart->toDateString(),
                'endsOn' => $period->ends_on->toDateString(),
            ],
        ]);
    }

    public function store(TrainingPhaseRequest $request): RedirectResponse
    {
        $period = $request->period();
        $phase = $this->phases->create($period, $request->phaseData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$phase->name} added to {$period->name}."]);

        return redirect()->route('training-phases.index', ['period' => $period->id]);
    }

    public function update(TrainingPhaseRequest $request, TrainingPhase $trainingPhase): RedirectResponse
    {
        $phase = $this->phases->update($trainingPhase, $request->phaseData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$phase->name} saved."]);

        return redirect()->route('training-phases.index', ['period' => $phase->academic_period_id]);
    }

    public function destroy(TrainingPhase $trainingPhase): RedirectResponse
    {
        $back = redirect()->route('training-phases.index', ['period' => $trainingPhase->academic_period_id]);

        try {
            $this->phases->delete($trainingPhase);
        } catch (ValidationException $exception) {
            // Deleting is confirmed in a dialog, not a form, so the reason is a notification.
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->validator->errors()->first()]);

            return $back;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$trainingPhase->name} deleted."]);

        return $back;
    }
}
