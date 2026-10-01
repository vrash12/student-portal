<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Fitness\FitnessResults;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Physical Fitness" (candidate portal): the signed-in candidate's own
 * fitness tests, event by event, scored by the fitness engine; never other
 * candidates' results or staff names. Owner decision 2026-10-02: military
 * fitness is for staff only, so the page exists only when
 * institution.portal.show_fitness is on (off by default).
 */
class FitnessController extends Controller
{
    /** Tests listed, newest first. */
    private const HISTORY = 10;

    public function __invoke(Request $request, FitnessResults $fitness): Response
    {
        abort_unless((bool) config('institution.portal.show_fitness'), 404);
        $candidate = $request->user()->candidate()->with('classBatch.academicPeriod')->firstOrFail();

        return Inertia::render('portal/fitness', [
            'candidate' => [
                'name' => $candidate->full_name,
                'number' => $candidate->candidate_number,
                'className' => $candidate->classBatch?->name,
            ],
            'tests' => $fitness->history($candidate, self::HISTORY),
        ]);
    }
}
