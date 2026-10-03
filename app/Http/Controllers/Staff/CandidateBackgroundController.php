<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Candidates\UpdateCandidateBackgroundRequest;
use App\Models\Candidate;
use App\Services\CandidateBackgroundService;
use App\Support\CandidateBackgroundPresenter;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The candidate's background record: personal details, emergency contact,
 * education and service background (owner request, 2026-10-03). Routes:
 * `can:update,candidate` (candidates.manage).
 */
class CandidateBackgroundController extends Controller
{
    public function edit(Candidate $candidate): Response
    {
        return Inertia::render('staff/candidates/background', [
            'candidate' => ['id' => $candidate->id, 'name' => $candidate->full_name, 'candidateNumber' => $candidate->candidate_number],
            'background' => CandidateBackgroundPresenter::form($candidate),
            'options' => CandidateBackgroundPresenter::options(),
        ]);
    }

    public function update(UpdateCandidateBackgroundRequest $request, Candidate $candidate, CandidateBackgroundService $backgrounds): RedirectResponse
    {
        $backgrounds->save($candidate, $request->background(), $request->education());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Background of {$candidate->full_name} saved."]);

        return redirect()->route('candidates.show', $candidate);
    }
}
