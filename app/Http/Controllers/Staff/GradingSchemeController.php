<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Grading\GradingSchemeRequest;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Services\Grading\Gradebook;
use App\Services\Grading\GradingSchemeService;
use App\Services\Grading\GradingSetupOverview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administrative grading setup of one subject of a class: its categories
 * and weights. Authorization: ClassSubjectPolicy::configureGrading.
 */
class GradingSchemeController extends Controller
{
    private const RETURN_TO_SETUP = 'setup';

    public function __construct(
        private readonly GradingSchemeService $schemes,
        private readonly Gradebook $gradebook,
        private readonly GradingSetupOverview $overview,
    ) {}

    public function edit(Request $request, ClassBatch $classBatch, ClassSubject $classSubject): Response
    {
        $categories = $this->gradebook->scheme($classSubject);

        return Inertia::render('staff/classes/grading', [
            'offering' => $this->gradebook->offering($classSubject),
            'categories' => $categories,
            // An empty setup can start from another subject's weights (prefill only).
            'copySources' => $categories === [] ? $this->overview->copySources($classSubject->classBatch->academic_period_id, $classSubject->id) : [],
            // Opened from Grading Setup: return there after saving or cancelling.
            'returnTo' => $request->query('return') === self::RETURN_TO_SETUP ? self::RETURN_TO_SETUP : null,
            // Standing in this subject uses the period's passing and warning grades.
            'thresholds' => $this->gradebook->thresholds($classSubject),
            'periodId' => $classSubject->classBatch->academic_period_id,
            'hasFinalizedAssessments' => $classSubject->assessments()->finalized()->exists(),
            'totalWeight' => GradingSchemeService::TOTAL_WEIGHT,
            'maxCategories' => GradingSchemeRequest::MAX_CATEGORIES,
            'can' => [
                'viewClass' => $request->user()->hasPermission(Permission::ManageClassBatches),
            ],
        ]);
    }

    public function update(GradingSchemeRequest $request, ClassBatch $classBatch, ClassSubject $classSubject): RedirectResponse
    {
        $this->schemes->save($classSubject, $request->categories(), $request->reason());

        $classSubject->loadMissing('subject');
        Inertia::flash('toast', ['type' => 'success', 'message' => "Weights for {$classSubject->subject->name} saved."]);

        if ($request->input('return') === self::RETURN_TO_SETUP) {
            return redirect()->route('grading-setup.index', ['period' => $classBatch->academic_period_id]);
        }

        // Return to the class, where the other subjects are set up.
        return $request->user()->hasPermission(Permission::ManageClassBatches)
            ? redirect()->route('classes.show', $classBatch)
            : redirect()->route('classes.grading.edit', [$classBatch, $classSubject]);
    }
}
