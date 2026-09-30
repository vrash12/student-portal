<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\PostExaminationGradesRequest;
use App\Models\Examination;
use App\Services\Examinations\ExaminationGradebookService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ExaminationGradebookController extends Controller
{
    public function show(Request $request, Examination $examination, ExaminationGradebookService $service)
    {
        $data = $request->validate(['rule' => ['sometimes', Rule::in(['highest', 'latest'])]]);

        return Inertia::render('staff/examinations/post-grades', ['review' => $service->preview($request->user(), $examination, $data['rule'] ?? 'highest')]);
    }

    public function store(PostExaminationGradesRequest $request, Examination $examination, ExaminationGradebookService $service)
    {
        $assessment = $service->post($request->user(), $examination, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Examination scores are posted to the gradebook.']);

        return redirect()->route('assessments.show', $assessment);
    }
}
