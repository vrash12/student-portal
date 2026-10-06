<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Schedule\CandidateSchedule;
use App\Services\Schedule\ScheduleWeek;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The candidate's week (owner request, 2026-10-06): their class's training
 * schedule and examinations, from the signed-in candidate's own class only.
 */
class ScheduleController extends Controller
{
    public function __invoke(Request $request, CandidateSchedule $schedule): Response
    {
        $candidate = $request->user()->candidate()->with('classBatch:id,name')->firstOrFail();
        $week = ScheduleWeek::containing($request->query('week'));

        return Inertia::render('portal/schedule', [
            'week' => $week->toArray(),
            'days' => $schedule->days($candidate, $week->start, $week->end),
            'className' => $candidate->classBatch?->name,
        ]);
    }
}
