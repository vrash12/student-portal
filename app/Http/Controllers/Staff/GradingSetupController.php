<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Grading\CopyGradingWeightsRequest;
use App\Models\AcademicPeriod;
use App\Models\ClassSubject;
use App\Services\Grading\GradingSchemeService;
use App\Services\Grading\GradingSetupOverview;
use App\Services\Grading\GradingThresholds;
use App\Support\QueryFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Grading Setup: one page that shows every setting deciding a candidate's
 * grades, standing and qualification, in the order the system applies them,
 * with what is still missing and a link to the page that changes each one.
 * Authorization: `can:grading.configure` on the routes.
 */
class GradingSetupController extends Controller
{
    public function __construct(
        private readonly GradingSetupOverview $overview,
        private readonly GradingSchemeService $schemes,
    ) {}

    public function index(Request $request): Response
    {
        $viewer = $request->user();
        $periods = $this->overview->periods();
        $periodIds = array_column($periods, 'id');
        $requested = QueryFilters::id($request, 'period');
        // The requested period if it exists, else the active one, else the newest.
        $periodId = $requested !== '' && in_array((int) $requested, $periodIds, true) ? (int) $requested : ($periodIds[0] ?? null);
        $period = $periodId === null ? null : AcademicPeriod::query()->findOrFail($periodId);

        return Inertia::render('staff/grading-setup/index', [
            'period' => $period === null ? null : ['id' => $period->id, 'name' => $period->name, 'isActive' => $period->is_active],
            'periods' => $periods,
            'thresholds' => $period === null ? null : GradingThresholds::forPeriod($period)?->toArray(),
            'subjectWeights' => $period === null ? [] : $this->overview->subjectWeights($period),
            'copySources' => $this->overview->copySources($period?->id),
            'areas' => $this->overview->areas($period),
            'sources' => $this->overview->sources(),
            'example' => $this->overview->workedExample($period),
            'can' => [
                'manageClasses' => $viewer->hasPermission(Permission::ManageClassBatches),
                'managePeriods' => $viewer->hasPermission(Permission::ManageAcademicPeriods),
                'configurePerformance' => $viewer->hasPermission(Permission::ConfigurePerformance),
                'configureFitness' => $viewer->hasPermission(Permission::ConfigureFitness),
                'manageAttendance' => $viewer->hasPermission(Permission::ManageAttendance),
            ],
        ]);
    }

    public function copy(CopyGradingWeightsRequest $request): RedirectResponse
    {
        $source = ClassSubject::query()->findOrFail($request->integer('source'));
        $targets = ClassSubject::query()->whereKey($request->targetIds())->get();

        $copied = $this->schemes->copy($source, $targets);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $copied === 1 ? 'Weights copied to 1 subject.' : "Weights copied to {$copied} subjects.",
        ]);

        // Back to the period the administrator was looking at (sent with the form).
        $period = $request->validated('period');

        return redirect()->route('grading-setup.index', $period === null ? [] : ['period' => (int) $period]);
    }
}
