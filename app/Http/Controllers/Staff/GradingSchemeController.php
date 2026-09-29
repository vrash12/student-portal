<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Grading\GradingSchemeRequest;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Services\Grading\Gradebook;
use App\Services\Grading\GradingSchemeService;
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
    public function __construct(
        private readonly GradingSchemeService $schemes,
        private readonly Gradebook $gradebook,
    ) {}

    public function edit(Request $request, ClassBatch $classBatch, ClassSubject $classSubject): Response
    {
        return Inertia::render('staff/classes/grading', [
            'offering' => $this->gradebook->offering($classSubject),
            'categories' => $this->gradebook->scheme($classSubject),
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
        Inertia::flash('toast', ['type' => 'success', 'message' => "Grading setup for {$classSubject->subject->name} saved."]);

        // Return to the class, where the other subjects are set up.
        return $request->user()->hasPermission(Permission::ManageClassBatches)
            ? redirect()->route('classes.show', $classBatch)
            : redirect()->route('classes.grading.edit', [$classBatch, $classSubject]);
    }
}
