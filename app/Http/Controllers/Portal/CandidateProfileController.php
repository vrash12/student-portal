<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Support\CandidatePresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CandidateProfileController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $candidate = $request->user()->candidate()->with(['user', 'classBatch.academicPeriod'])->firstOrFail();

        // Personal details and documents only; grades and results have their own pages.
        return Inertia::render('portal/profile', [
            'candidate' => [...CandidatePresenter::details($candidate, true), 'account' => CandidatePresenter::account($candidate)],
        ]);
    }
}
