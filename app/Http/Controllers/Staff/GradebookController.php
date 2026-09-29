<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Services\Grading\Gradebook;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The gradebook of one subject in one class, for the instructors assigned to
 * teach it: grading scheme, assessments, and each candidate's calculated grade.
 */
class GradebookController extends Controller
{
    private const CANDIDATES_PER_PAGE = 50;

    public function __construct(private readonly Gradebook $gradebook) {}

    public function show(Request $request, ClassBatch $classBatch, ClassSubject $classSubject): Response
    {
        $filters = ['search' => QueryFilters::search($request)];

        return Inertia::render('staff/teaching/gradebook/show', [
            'offering' => $this->gradebook->offering($classSubject),
            'scheme' => $this->gradebook->scheme($classSubject),
            'assessments' => $this->gradebook->assessments($classSubject),
            'gradableCount' => $this->gradebook->gradableCount($classSubject),
            'grades' => $this->gradebook->candidateGrades($classSubject, $filters['search'], self::CANDIDATES_PER_PAGE),
            'filters' => $filters,
            'can' => [
                'recordGrades' => $request->user()->can('recordGrades', $classSubject),
            ],
        ]);
    }
}
