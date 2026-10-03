<?php

namespace Tests\Feature\Examinations\Delivery;

use App\Models\Candidate;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\ExaminationQuestion;
use Tests\TestCase;

/**
 * Random question subsets: each attempt receives question_draw_count
 * questions drawn at random from the examination (for example 5 of 12).
 */
class QuestionSubsetDeliveryTest extends TestCase
{
    use BuildsDeliveryFixtures;

    private Examination $exam;

    /** @var list<ExaminationQuestion> */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDeliveryFixtures();

        $this->exam = $this->makeExamination(['question_draw_count' => 5]);
        for ($i = 1; $i <= 12; $i++) {
            $this->items[] = $this->addItem($this->exam, $this->mcq(($i % 4) + 1), '2.00');
        }
    }

    /**
     * @return list<int> delivered examination question ids, in delivery order
     */
    private function deliveredIds(ExaminationAttempt $attempt): array
    {
        return array_map(fn (array $item): int => (int) $item['id'], $attempt->delivery);
    }

    private function submit(ExaminationAttempt $attempt): void
    {
        $current = $attempt->fresh();
        $itemId = $current->delivery[$current->current_position]['id'];
        $this->post('/portal/attempts/'.$attempt->id.'/submit', [
            'revision' => $current->revision,
            'position' => $current->current_position,
            'answer' => $current->answers[$itemId]['value'] ?? null,
        ])->assertRedirect('/portal/attempts/'.$attempt->id.'/success');
    }

    public function test_an_attempt_receives_the_configured_number_of_distinct_questions_in_examination_order(): void
    {
        $attempt = $this->startFor($this->candidateInA, $this->exam);
        $ids = $this->deliveredIds($attempt);

        $this->assertCount(5, $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertEmpty(array_diff($ids, array_map(fn (ExaminationQuestion $item): int => $item->id, $this->items)));

        $positions = ExaminationQuestion::query()->whereKey($ids)->pluck('position', 'id');
        $ordered = $ids;
        usort($ordered, fn (int $a, int $b): int => $positions[$a] <=> $positions[$b]);
        $this->assertSame($ordered, $ids, 'Without question randomization, drawn questions keep the examination order.');

        // Only the drawn questions are scored.
        $this->assertCount(5, $attempt->scoring_key);
    }

    public function test_attempts_draw_different_selections(): void
    {
        $selections = [];
        $candidates = Candidate::factory()->count(8)->create(['class_batch_id' => $this->batchA->id]);
        foreach ($candidates as $candidate) {
            $ids = $this->deliveredIds($this->startFor($candidate, $this->exam));
            sort($ids);
            $selections[] = implode(',', $ids);
        }

        // 792 possible selections of 5 from 12: eight identical draws would be a defect, not chance.
        $this->assertGreaterThan(1, count(array_unique($selections)));
    }

    public function test_a_new_attempt_draws_again_and_refreshing_keeps_the_same_questions(): void
    {
        $attempt = $this->startFor($this->candidateInA, $this->exam);
        $first = $this->deliveredIds($attempt);

        // Starting again while in progress resumes the same attempt and selection.
        $this->assertSame($first, $this->deliveredIds($this->startFor($this->candidateInA, $this->exam)));
        $this->actingAs($this->candidateInA->user)->get('/portal/attempts/'.$attempt->id)->assertOk();
        $this->assertSame($first, $this->deliveredIds($attempt->fresh()));
    }

    public function test_scoring_uses_only_the_drawn_questions(): void
    {
        $attempt = $this->startFor($this->candidateInA, $this->exam);
        $this->actingAs($this->candidateInA->user);
        foreach ($attempt->delivery as $position => $delivered) {
            $this->saveAnswer($attempt, $position, $this->correctChoiceId($delivered))->assertOk();
        }
        $this->submit($attempt);

        $attempt->refresh();
        $this->assertSame('graded', $attempt->result_status);
        $this->assertSame('10.00', $attempt->total_points);
        $this->assertSame('10.00', $attempt->earned_points);
        $this->assertSame('100.00', $attempt->percentage);
        $this->assertSame($this->deliveredIds($attempt), array_map('intval', array_keys($attempt->item_scores)));
    }

    public function test_candidate_and_staff_pages_show_questions_per_attempt(): void
    {
        $props = $this->inertiaProps($this->actingAs($this->candidateInA->user)->get('/portal/examinations/'.$this->exam->id)->assertOk());
        $this->assertSame(5, $props['examination']['questionCount']);

        $monitoring = $this->inertiaProps($this->actingAs($this->alpha)->get('/examinations/'.$this->exam->id)->assertOk())['monitoring'];
        $this->assertSame([5], array_values(array_unique(array_column($monitoring['candidates'], 'questionCount'))));

        $this->assertSame('10.00', $this->exam->attemptMaximumPoints());
    }

    public function test_combined_with_question_randomization_the_selection_is_shuffled_and_still_sized(): void
    {
        $exam = $this->makeExamination(['question_draw_count' => 11, 'randomize_questions' => true]);
        foreach (range(1, 12) as $i) {
            $this->addItem($exam, $this->trueFalse($i % 2 === 0));
        }

        $orders = [];
        $candidates = Candidate::factory()->count(6)->create(['class_batch_id' => $this->batchA->id]);
        foreach ($candidates as $candidate) {
            $ids = $this->deliveredIds($this->startFor($candidate, $exam));
            $this->assertCount(11, $ids);
            $orders[] = implode(',', $ids);
        }
        $this->assertGreaterThan(1, count(array_unique($orders)));
    }

    public function test_a_draw_count_covering_every_question_delivers_them_all(): void
    {
        $exam = $this->makeExamination(['question_draw_count' => 3]);
        $items = [$this->addItem($exam, $this->mcq()), $this->addItem($exam, $this->mcq(), '4.00'), $this->addItem($exam, $this->essay(), '3.00')];

        $this->assertFalse($exam->drawsSubset());
        $this->assertSame(array_map(fn (ExaminationQuestion $item): int => $item->id, $items), $this->deliveredIds($this->startFor($this->candidateInA, $exam)));
        $this->assertSame('8.00', $exam->attemptMaximumPoints());
    }
}
