<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Performance\CandidatePerformanceRecord;
use App\Services\Performance\PortalQualification;
use App\Services\Performance\QualificationEngine;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My Performance": the signed-in candidate's own area results,
 * qualification checklist, merits/demerits and attendance. The identity
 * always comes from the account, never from the request. No class rank
 * (staff only) and nothing about other candidates; voided merits/demerits,
 * staff names and staff remarks are left out. Fitness areas are left out
 * while military fitness is staff only (PortalQualification).
 */
class PerformanceController extends Controller
{
    /** Latest attendance sessions listed; the summary counts every session. */
    private const RECENT_SESSIONS = 10;

    public function show(Request $request, CandidatePerformanceRecord $record, QualificationEngine $engine): Response
    {
        $candidate = $request->user()->candidate()->with('classBatch.academicPeriod')->firstOrFail();
        $qualification = PortalQualification::of($engine->forCandidate($candidate, withRank: false));

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
            // Areas assessed by staff only (fitness), counted in the status and overall score but not listed.
            'staffAssessedAreas' => $qualification['staffAssessedAreas'],
            'conduct' => $record->ownConduct($candidate),
            'attendance' => $record->attendance($candidate, self::RECENT_SESSIONS, withRemarks: false),
        ]);
    }
}
