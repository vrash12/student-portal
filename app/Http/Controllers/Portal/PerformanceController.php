<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Performance\CandidatePerformanceRecord;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My Performance": the signed-in candidate's own area results,
 * qualification checklist, merits/demerits and attendance. The identity
 * always comes from the account, never from the request. No class rank
 * (staff only) and nothing about other candidates; voided merits/demerits,
 * staff names and staff remarks are left out.
 */
class PerformanceController extends Controller
{
    /** Latest attendance sessions listed; the summary counts every session. */
    private const RECENT_SESSIONS = 10;

    public function show(Request $request, CandidatePerformanceRecord $record): Response
    {
        $candidate = $request->user()->candidate()->with('classBatch.academicPeriod')->firstOrFail();
        $qualification = $record->qualification($candidate, withRank: false);

        return Inertia::render('portal/performance', [
            'candidate' => [
                'name' => $candidate->full_name,
                'number' => $candidate->candidate_number,
                'className' => $candidate->classBatch?->name,
                'period' => $candidate->classBatch?->academicPeriod->name,
            ],
            'areas' => $qualification['areas'],
            // Null without a class.
            'result' => $qualification['result'],
            'conduct' => $record->ownConduct($candidate),
            'attendance' => $record->attendance($candidate, self::RECENT_SESSIONS, withRemarks: false),
        ]);
    }
}
