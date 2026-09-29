<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreClassSubjectRequest;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\Subject;
use App\Services\ClassBatchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Subjects taken by a class.
 */
class ClassSubjectController extends Controller
{
    public function __construct(private readonly ClassBatchService $classes) {}

    public function store(StoreClassSubjectRequest $request, ClassBatch $classBatch): RedirectResponse
    {
        $subject = Subject::query()->findOrFail($request->integer('subject_id'));
        $this->classes->addSubject($classBatch, $subject);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$subject->name} added to {$classBatch->name}."]);

        return redirect()->route('classes.show', $classBatch);
    }

    public function destroy(ClassBatch $classBatch, ClassSubject $classSubject): RedirectResponse
    {
        $classSubject->loadMissing('subject');
        $subjectName = $classSubject->subject->name;

        try {
            $this->classes->removeSubject($classSubject);
        } catch (ValidationException $exception) {
            // Removal is triggered from a confirmation dialog, not a form, so
            // the reason is shown as a notification.
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->validator->errors()->first()]);

            return redirect()->route('classes.show', $classBatch);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$subjectName} removed from {$classBatch->name}."]);

        return redirect()->route('classes.show', $classBatch);
    }
}
