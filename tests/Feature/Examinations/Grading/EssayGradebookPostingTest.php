<?php

namespace Tests\Feature\Examinations\Grading;

use App\Models\Assessment;
use App\Services\Examinations\ExaminationGradebookService;
use App\Services\Examinations\ManualEssayGradingService;
use App\Services\Grading\GradingSchemeService;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Posting edge cases that depend on the real essay-grading flow. Basic
 * posting rules are covered by ExaminationGradebookTest.
 */
class EssayGradebookPostingTest extends TestCase
{
    use BuildsEssayGradingFixtures;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEssayGradingFixtures();
        app(GradingSchemeService::class)->save($this->offeringA1, [['id' => null, 'name' => 'Examinations', 'weight' => '100']], null);
        $this->categoryId = $this->offeringA1->assessmentCategories()->sole()->id;
    }

    private function postToGradebook(): TestResponse
    {
        $preview = app(ExaminationGradebookService::class)->preview($this->alpha, $this->exam->fresh(), 'highest');

        return $this->actingAs($this->alpha)->post('/examinations/'.$this->exam->id.'/gradebook', [
            'title' => 'Posted essay examination', 'assessment_category_id' => $this->categoryId,
            'attempt_rule' => 'highest', 'review_token' => $preview['reviewToken'], 'confirmed' => true,
        ]);
    }

    public function test_pending_essays_block_posting_until_graded_and_later_corrections_do_not_rewrite_posted_score(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);
        $service = app(ManualEssayGradingService::class);
        $service->grade($this->alpha, $attempt, $this->shortEssay->id, '2', null, 0, null);
        $this->releaseResults();
        $this->travel(3)->hours();

        $this->postToGradebook()->assertSessionHasErrors('posting');
        $this->assertSame(0, Assessment::where('source_examination_id', $this->exam->id)->count());

        $service->grade($this->alpha, $attempt, $this->longEssay->id, '4', null, 0, null);
        $this->postToGradebook()->assertSessionHasNoErrors()->assertRedirect();
        $score = Assessment::where('source_examination_id', $this->exam->id)->sole()->scores()->sole();
        $this->assertSame('10.00', $score->score);

        // Posting is a snapshot: regrading changes the attempt, not the finalized gradebook score.
        $corrected = $service->grade($this->alpha, $attempt, $this->longEssay->id, '1', null, 1, 'Rubric recheck');
        $this->assertSame('7.00', $corrected->earned_points);
        $this->assertSame('10.00', $score->fresh()->score);
    }
}
