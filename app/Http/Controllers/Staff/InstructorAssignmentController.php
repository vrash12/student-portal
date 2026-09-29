<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreInstructorAssignmentRequest;
use App\Models\ClassSubject;
use App\Models\InstructorAssignment;
use App\Models\User;
use App\Services\InstructorAssignmentService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Used from both the class page and the instructor page, so both actions
 * return the user to the page they came from.
 */
class InstructorAssignmentController extends Controller
{
    public function __construct(private readonly InstructorAssignmentService $assignments) {}

    public function store(StoreInstructorAssignmentRequest $request): RedirectResponse
    {
        $offering = ClassSubject::query()->with(['classBatch', 'subject'])->findOrFail($request->integer('class_subject_id'));
        $instructor = User::query()->findOrFail($request->integer('instructor_id'));

        $this->assignments->assign($offering, $instructor);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$instructor->name} assigned to {$offering->subject->name} for {$offering->classBatch->name}.",
        ]);

        return redirect()->back();
    }

    public function destroy(InstructorAssignment $instructorAssignment): RedirectResponse
    {
        $instructorAssignment->loadMissing(['instructor', 'classSubject.subject', 'classSubject.classBatch']);
        $message = "{$instructorAssignment->instructor->name} removed from {$instructorAssignment->classSubject->subject->name} for {$instructorAssignment->classSubject->classBatch->name}.";

        $this->assignments->unassign($instructorAssignment);

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect()->back();
    }
}
