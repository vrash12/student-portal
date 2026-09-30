<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\CandidateProfileRecord;
use App\Support\CandidatePresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CandidateProfileController extends Controller
{
    public function __invoke(Request $request, CandidateProfileRecord $record): Response
    {
        $candidate = $request->user()->candidate()->with(['user', 'classBatch.academicPeriod'])->firstOrFail();

        return Inertia::render('portal/profile', [
            'candidate' => [...CandidatePresenter::details($candidate, true), 'account' => CandidatePresenter::account($candidate)],
            'academics' => $record->academics($candidate),
            'assessmentHistory' => $record->assessmentHistory($candidate),
            'examinationResults' => $record->examinationResults($candidate, true),
        ]);
    }
}
