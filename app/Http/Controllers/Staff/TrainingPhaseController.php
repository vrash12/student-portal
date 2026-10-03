<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\TrainingPhaseRequest;
use App\Models\TrainingPhase;
use App\Services\TrainingPhaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Training phases of the course (route middleware: academic_periods.manage).
 * Subjects are placed in a phase on their class's page.
 */
class TrainingPhaseController extends Controller
{
    public function __construct(private readonly TrainingPhaseService $phases) {}

    public function index(): Response
    {
        $phases = TrainingPhase::query()->withCount('classSubjects')->ordered()->get();

        return Inertia::render('staff/training-phases/index', [
            'phases' => $phases->map(fn (TrainingPhase $phase): array => [
                ...$phase->toSummary(),
                'subjectCount' => (int) $phase->class_subjects_count,
            ])->all(),
            'nextNumber' => min(TrainingPhase::MAX_NUMBER, (int) $phases->max('number') + 1),
        ]);
    }

    public function store(TrainingPhaseRequest $request): RedirectResponse
    {
        $phase = $this->phases->create($request->phaseData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$phase->name} added."]);

        return redirect()->route('training-phases.index');
    }

    public function update(TrainingPhaseRequest $request, TrainingPhase $trainingPhase): RedirectResponse
    {
        $phase = $this->phases->update($trainingPhase, $request->phaseData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$phase->name} saved."]);

        return redirect()->route('training-phases.index');
    }

    public function destroy(TrainingPhase $trainingPhase): RedirectResponse
    {
        try {
            $this->phases->delete($trainingPhase);
        } catch (ValidationException $exception) {
            // Deleting is confirmed in a dialog, not a form, so the reason is a notification.
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->validator->errors()->first()]);

            return redirect()->route('training-phases.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$trainingPhase->name} deleted."]);

        return redirect()->route('training-phases.index');
    }
}
