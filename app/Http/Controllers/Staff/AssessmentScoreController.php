<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Grading\CorrectScoreRequest;
use App\Http\Requests\Grading\RecordScoresRequest;
use App\Models\Assessment;
use App\Services\Grading\ScoreRecordingService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Score entry: batch saves on draft score sheets, and single corrections of
 * finalized scores with a reason. Authorization: AssessmentPolicy::manage on
 * the routes.
 */
class AssessmentScoreController extends Controller
{
    public function __construct(private readonly ScoreRecordingService $scores) {}

    public function update(RecordScoresRequest $request, Assessment $assessment): RedirectResponse
    {
        $changed = $this->scores->recordDraftScores($assessment, $request->entries(), $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $changed === 0
                ? 'No changes to save.'
                : ($changed === 1 ? '1 score saved.' : "{$changed} scores saved."),
        ]);

        return redirect()->route('assessments.show', $assessment);
    }

    public function correct(CorrectScoreRequest $request, Assessment $assessment): RedirectResponse
    {
        $this->scores->correctFinalizedScore(
            $assessment,
            $request->integer('candidate_id'),
            $request->scoreValue(),
            $request->commentValue(),
            (string) $request->validated('reason'),
            $request->expectedScore(),
            $request->expectedComment(),
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Score corrected. The change and its reason were recorded.']);

        return redirect()->route('assessments.show', $assessment);
    }
}
