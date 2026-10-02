<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Grading\RecordScoresRequest;
use App\Models\Assessment;
use App\Services\Grading\ScoreRecordingService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Score entry: batch saves on draft score sheets. Finalized scores change only
 * through grade correction requests (GradeCorrectionController).
 * Authorization: AssessmentPolicy::manage on the routes.
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
}
