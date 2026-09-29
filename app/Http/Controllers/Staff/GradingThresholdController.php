<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Grading\GradingThresholdsRequest;
use App\Models\AcademicPeriod;
use App\Services\Grading\GradingThresholds;
use App\Services\Grading\GradingThresholdService;
use App\Support\DecimalValue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The passing and warning grades of an academic period. Authorization:
 * `can:grading.configure` on the routes.
 */
class GradingThresholdController extends Controller
{
    public function __construct(private readonly GradingThresholdService $thresholds) {}

    public function edit(Request $request, AcademicPeriod $academicPeriod): Response
    {
        $academicPeriod->loadCount('classBatches');
        $current = GradingThresholds::forPeriod($academicPeriod);

        // A new period starts without thresholds (there is no built-in
        // default). The most recent other period's values are offered as an
        // editable starting point; nothing is saved until the user saves.
        $suggestionSource = $current !== null ? null : AcademicPeriod::query()
            ->whereKeyNot($academicPeriod->id)
            ->whereNotNull('passing_grade')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();
        $suggested = $suggestionSource === null ? null : GradingThresholds::forPeriod($suggestionSource);

        return Inertia::render('staff/academic-periods/thresholds', [
            'period' => [
                'id' => $academicPeriod->id,
                'name' => $academicPeriod->name,
                'startsOn' => $academicPeriod->starts_on->toDateString(),
                'endsOn' => $academicPeriod->ends_on->toDateString(),
                'isActive' => $academicPeriod->is_active,
                'classCount' => (int) $academicPeriod->class_batches_count,
            ],
            'thresholds' => $current === null ? null : $this->formValues($current),
            'suggestion' => $suggested === null ? null : [
                'fromPeriod' => $suggestionSource->name,
                ...$this->formValues($suggested),
            ],
            // Changing thresholds that are in use changes official standings.
            'requiresReason' => $current !== null && $this->thresholds->hasFinalizedAssessments($academicPeriod),
            'can' => [
                'managePeriods' => $request->user()->hasPermission(Permission::ManageAcademicPeriods),
            ],
        ]);
    }

    public function update(GradingThresholdsRequest $request, AcademicPeriod $academicPeriod): RedirectResponse
    {
        $this->thresholds->save($academicPeriod, $request->passingGrade(), $request->warningGrade(), $request->reason());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Passing and warning grades for {$academicPeriod->name} saved."]);

        return $request->user()->hasPermission(Permission::ManageAcademicPeriods)
            ? redirect()->route('academic-periods.index')
            : redirect()->route('academic-periods.thresholds.edit', $academicPeriod);
    }

    /**
     * Values as the form shows them ("75", "82.5").
     *
     * @return array{passingGrade: string, warningGrade: string}
     */
    private function formValues(GradingThresholds $thresholds): array
    {
        return [
            'passingGrade' => DecimalValue::display($thresholds->passingGrade()),
            'warningGrade' => DecimalValue::display($thresholds->warningGrade()),
        ];
    }
}
