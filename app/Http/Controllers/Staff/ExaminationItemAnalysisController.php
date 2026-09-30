<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Examination;
use App\Services\Examinations\ItemAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Item analysis of an examination. Authorized exactly like its essay
 * grading: the route requires examinations.manage, and the instructor must
 * record grades for and teach the examination's class subject.
 */
final class ExaminationItemAnalysisController extends Controller
{
    public function show(Request $request, Examination $examination, ItemAnalysisService $analysis): Response
    {
        abort_unless($request->user()->hasPermission(Permission::RecordGrades) && $request->user()->teachesOffering($examination->class_subject_id), 403);
        $filters = $request->validate([
            'scope' => ['sometimes', Rule::in(ItemAnalysisService::SCOPES)],
            'sort' => ['sometimes', Rule::in(ItemAnalysisService::SORTS)],
        ]);
        $examination->loadMissing('classSubject.subject', 'classSubject.classBatch');

        return Inertia::render('staff/examinations/analysis', [
            'examination' => [
                'id' => $examination->id,
                'title' => $examination->title,
                'kind' => $examination->kind?->label() ?? 'Examination',
                'subject' => $examination->classSubject->subject->name,
                'classBatch' => $examination->classSubject->classBatch->name,
            ],
            'analysis' => $analysis->analyse($examination, $filters['scope'] ?? ItemAnalysisService::SCOPE_LATEST, $filters['sort'] ?? ItemAnalysisService::SORT_MISSED),
            'generatedAt' => now()->toIso8601String(),
        ]);
    }
}
