<?php

namespace App\Http\Controllers\Staff;

use App\Enums\ExaminationStatus;
use App\Models\ClassSubject;
use App\Models\Examination;
use App\Models\Question;
use App\Services\Examinations\ExaminationService;
use App\Services\QuestionBank\QuestionPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ExaminationController
{
    public function index(Request $r)
    {
        $e = Examination::with('classSubject.subject', 'classSubject.classBatch')->whereHas('classSubject', fn ($q) => $q->whereHas('instructorAssignments', fn ($x) => $x->where('instructor_id', $r->user()->id)))->latest()->paginate(20);

        return Inertia::render('staff/examinations/index', ['examinations' => $e]);
    }

    public function create(Request $r)
    {
        $offerings = ClassSubject::with('subject', 'classBatch')->whereHas('instructorAssignments', fn ($q) => $q->where('instructor_id', $r->user()->id))->get();

        return Inertia::render('staff/examinations/create', ['offerings' => $offerings]);
    }

    public function store(Request $r, ExaminationService $s)
    {
        $d = $r->validate(['class_subject_id' => 'required|integer', 'title' => 'required|string|max:200', 'description' => 'nullable|string', 'duration_minutes' => 'nullable|integer|min:1', 'attempt_limit' => 'required|integer|min:1', 'passing_score' => 'nullable|numeric|min:0|max:100', 'release_results' => 'sometimes|boolean', 'opens_at' => 'nullable|date', 'closes_at' => 'nullable|date|after:opens_at']);
        $e = $s->create($r->user(), $d);

        return redirect('/examinations/'.$e->id);
    }

    public function show(Examination $examination, Request $r)
    {
        abort_unless($r->user()->teachesOffering($examination->class_subject_id), 403);
        $examination->load('classSubject.subject', 'classSubject.classBatch', 'examinationQuestions.question.choices');

        return Inertia::render('staff/examinations/show', ['examination' => $examination]);
    }

    public function questions(Examination $examination, Request $r, QuestionPresenter $p)
    {
        abort_unless($r->user()->teachesOffering($examination->class_subject_id), 403);
        $qs = Question::with(QuestionPresenter::STAFF_RELATIONS)->where('subject_id', $examination->classSubject->subject_id)->where('is_active', true)->get()->map(fn ($q) => $p->staff($q));

        return Inertia::render('staff/examinations/questions', ['examination' => $examination, 'questions' => $qs]);
    }

    public function syncQuestions(Request $r, Examination $examination, ExaminationService $s)
    {
        $d = $r->validate(['questions' => 'array', 'questions.*.question_id' => 'required|integer', 'questions.*.points' => 'nullable|numeric|min:.01|max:100']);
        $s->syncQuestions($r->user(), $examination, $d['questions'] ?? []);

        return back();
    }

    public function publish(Request $r, Examination $examination, ExaminationService $s)
    {
        $s->publish($r->user(), $examination);

        return back()->with('success', 'Examination published.');
    }

    public function archive(Request $r, Examination $examination)
    {
        abort_unless($r->user()->teachesOffering($examination->class_subject_id), 403);
        $examination->update(['status' => ExaminationStatus::Archived]);

        return back();
    }
}
