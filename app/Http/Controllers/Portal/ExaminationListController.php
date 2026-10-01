<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\CandidateHomeService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Examinations" (candidate portal): the candidate's open and scheduled
 * examinations and their own results. Identity comes from the account.
 */
class ExaminationListController extends Controller
{
    public function __invoke(Request $request, CandidateHomeService $service): Response
    {
        $candidate = $request->user()->candidate()->firstOrFail();

        return Inertia::render('portal/examinations/index', $service->examinations($candidate));
    }
}
