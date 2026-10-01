<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\CandidateHomeService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My Grades" (candidate portal): own standing, current subject grades,
 * finalized assessments still without a score, and the assessment history.
 */
class GradesController extends Controller
{
    public function __invoke(Request $request, CandidateHomeService $service): Response
    {
        $candidate = $request->user()->candidate()->with('classBatch.academicPeriod')->firstOrFail();

        return Inertia::render('portal/grades', $service->grades($candidate));
    }
}
