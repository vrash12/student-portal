<?php

namespace Tests\Feature\Examinations;

use App\Enums\SystemRole;
use App\Models\Candidate;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\ExaminationQuestion;
use App\Models\QuestionChoice;
use App\Services\Examinations\CandidateAttemptService;
use App\Services\Examinations\ManualEssayGradingService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Examinations\Delivery\BuildsDeliveryFixtures;
use Tests\TestCase;

/**
 * Item analysis (difficulty, choice distribution, discrimination) of an
 * examination's submitted attempts. Examinations belong to Batch A /
 * Subject 1, taught by Instructor Alpha.
 */
class ItemAnalysisTest extends TestCase
{
    use BuildsDeliveryFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDeliveryFixtures();
    }

    private function url(Examination $exam, array $query = []): string
    {
        return '/examinations/'.$exam->id.'/analysis'.($query === [] ? '' : '?'.http_build_query($query));
    }

    /**
     * @return list<Candidate>
     */
    private function candidates(int $count): array
    {
        return Candidate::factory()->count($count)->create(['class_batch_id' => $this->batchA->id])->all();
    }

    /**
     * @return list<int> choice ids in stored order (A, B, C, ...)
     */
    private function choices(ExaminationQuestion $item): array
    {
        return QuestionChoice::query()->where('question_id', $item->question_id)->orderBy('position')->pluck('id')->all();
    }

    /**
     * Starts and submits an attempt through the candidate service.
     *
     * @param  array<int, int|string|null>  $answers  answer by examination question id; missing items stay unanswered
     * @param  list<int>|null  $onlyItems  deliver only these examination questions (a random subset)
     */
    private function submitWith(Candidate $candidate, Examination $exam, array $answers, ?array $onlyItems = null, bool $submit = true): ExaminationAttempt
    {
        $service = app(CandidateAttemptService::class);
        $attempt = $service->start($candidate->user, $exam, null);
        if ($onlyItems !== null) {
            $attempt->delivery = array_values(array_filter($attempt->delivery, fn (array $item): bool => in_array($item['id'], $onlyItems, true)));
            $attempt->scoring_key = array_filter($attempt->scoring_key, fn (int $itemId): bool => in_array($itemId, $onlyItems, true), ARRAY_FILTER_USE_KEY);
            $attempt->save();
        }
        $last = count($attempt->delivery) - 1;
        foreach ($attempt->delivery as $position => $item) {
            $data = ['revision' => $attempt->revision, 'position' => $position, 'answer' => $answers[$item['id']] ?? null];
            if ($position < $last) {
                $data['next_position'] = $position + 1;
            }
            $attempt = $service->save($candidate->user, $attempt, $data, $submit && $position === $last);
        }

        return $attempt->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function analysis(Examination $exam, array $query = []): array
    {
        $response = $this->actingAs($this->alpha)->get($this->url($exam, $query))->assertOk();

        return $this->inertiaProps($response)['analysis'];
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private function question(array $analysis, ExaminationQuestion $item): array
    {
        foreach ($analysis['questions'] as $question) {
            if ($question['id'] === $item->id) {
                return $question;
            }
        }
        $this->fail('Question '.$item->id.' is not in the analysis.');
    }

    /**
     * @param  array<string, mixed>  $question
     * @return list<string>
     */
    private function flagCodes(array $question): array
    {
        return array_column($question['flags'], 'code');
    }

    // Authorization -----------------------------------------------------------

    public function test_the_assigned_instructor_can_open_the_analysis(): void
    {
        $exam = $this->makeExamination();
        $this->addItem($exam, $this->mcq());

        $this->actingAs($this->alpha)->get($this->url($exam))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/examinations/analysis')
                ->where('examination.id', $exam->id)
                ->where('examination.subject', 'Subject 1')
                ->where('analysis.scope', 'latest')
                ->where('analysis.sort', 'missed')
                ->has('generatedAt'));
    }

    public function test_other_instructors_administrators_candidates_and_guests_are_denied(): void
    {
        $exam = $this->makeExamination();
        $this->addItem($exam, $this->mcq());
        $this->submitWith($this->candidateInA, $exam, []);

        $this->actingAs($this->bravo)->get($this->url($exam))->assertForbidden();
        $this->actingAs($this->userWithRole(SystemRole::AcademicAdministrator))->get($this->url($exam))->assertForbidden();
        $this->actingAs($this->userWithRole(SystemRole::SuperAdministrator))->get($this->url($exam))->assertForbidden();
        $this->actingAs($this->candidateInA->user)->get($this->url($exam))->assertForbidden();
        auth()->logout();
        $this->get($this->url($exam))->assertRedirect('/login');
    }

    public function test_invalid_scope_and_sort_are_rejected(): void
    {
        $exam = $this->makeExamination();

        $this->actingAs($this->alpha)->get($this->url($exam, ['scope' => 'everything']))->assertSessionHasErrors('scope');
        $this->actingAs($this->alpha)->get($this->url($exam, ['sort' => 'name']))->assertSessionHasErrors('sort');
    }

    // Empty state and exclusions --------------------------------------------

    public function test_empty_state_when_nothing_is_submitted(): void
    {
        $exam = $this->makeExamination();
        $this->addItem($exam, $this->mcq());
        $this->submitWith($this->candidateInA, $exam, [], submit: false);

        $analysis = $this->analysis($exam);

        $this->assertSame(0, $analysis['summary']['submittedAttempts']);
        $this->assertSame(0, $analysis['summary']['candidates']);
        $this->assertNull($analysis['summary']['mean']);
        $this->assertSame([], $analysis['questions']);
        $this->assertSame(1, $analysis['undeliveredQuestions']);
    }

    public function test_in_progress_and_expired_unsubmitted_attempts_are_excluded_and_auto_submitted_ones_counted(): void
    {
        $exam = $this->makeExamination(['auto_submit' => false]);
        $item = $this->addItem($exam, $this->mcq(1));
        [$expired, $inProgress, $submitted] = $this->candidates(3);
        $correct = $this->choices($item)[0];

        $this->submitWith($expired, $exam, [$item->id => $correct], submit: false);
        $this->travel(31)->minutes();
        $this->submitWith($inProgress, $exam, [$item->id => $correct], submit: false);
        $this->submitWith($submitted, $exam, [$item->id => $this->choices($item)[1]]);

        $analysis = $this->analysis($exam);

        $this->assertSame('expired', ExaminationAttempt::where('candidate_id', $expired->id)->value('status'));
        $this->assertSame('in_progress', ExaminationAttempt::where('candidate_id', $inProgress->id)->value('status'));
        $this->assertSame(1, $analysis['summary']['submittedAttempts']);
        $this->assertSame(1, $this->question($analysis, $item)['delivered']);
        $this->assertSame(0, $this->question($analysis, $item)['correct']);

        $autoExam = $this->makeExamination(['auto_submit' => true, 'title' => 'Auto-submit exam']);
        $autoItem = $this->addItem($autoExam, $this->mcq(1));
        $this->submitWith($expired, $autoExam, [$autoItem->id => $this->choices($autoItem)[0]], submit: false);
        $this->travel(31)->minutes();

        $autoAnalysis = $this->analysis($autoExam);

        $this->assertSame('submitted', ExaminationAttempt::where('examination_id', $autoExam->id)->value('status'));
        $this->assertSame(1, $autoAnalysis['summary']['submittedAttempts']);
        $this->assertSame(1, $this->question($autoAnalysis, $autoItem)['correct']);
    }

    // Objective items -------------------------------------------------------

    public function test_percent_correct_choice_distribution_and_summary_use_known_answers(): void
    {
        $exam = $this->makeExamination(['passing_score' => '60.00']);
        $mcq = $this->addItem($exam, $this->mcq(2));
        $trueFalse = $this->addItem($exam, $this->trueFalse(true));
        [$a, $b, $c, $d] = $this->candidates(4);
        [$optionA, $optionB, $optionC] = $this->choices($mcq);
        [$true, $false] = $this->choices($trueFalse);

        // B is correct. Two correct, one distractor A, one unanswered.
        $this->submitWith($a, $exam, [$mcq->id => $optionB, $trueFalse->id => $true]);
        $this->submitWith($b, $exam, [$mcq->id => $optionB, $trueFalse->id => $true]);
        $this->submitWith($c, $exam, [$mcq->id => $optionA, $trueFalse->id => $true]);
        $this->submitWith($d, $exam, [$trueFalse->id => $false]);

        $analysis = $this->analysis($exam);
        $question = $this->question($analysis, $mcq);

        $this->assertSame(4, $question['delivered']);
        $this->assertSame(3, $question['answered']);
        $this->assertSame(1, $question['unanswered']);
        $this->assertEquals(25, $question['unansweredPercent']);
        $this->assertSame(2, $question['correct']);
        $this->assertEquals(50, $question['percentCorrect']);
        $this->assertSame('multiple_choice', $question['type']['value']);
        $this->assertSame(['A', 'B', 'C', 'D'], array_column($question['choices'], 'label'));
        $this->assertSame([$optionA, $optionB, $optionC], array_slice(array_column($question['choices'], 'id'), 0, 3));
        $this->assertSame([false, true, false, false], array_column($question['choices'], 'isCorrect'));
        $this->assertSame([1, 2, 0, 0], array_column($question['choices'], 'count'));
        $this->assertEquals([25, 50, 0, 0], array_column($question['choices'], 'percent'));
        $this->assertSame('Option B', $question['choices'][1]['text']);
        $this->assertArrayNotHasKey('image', $question['choices'][0]);

        $tf = $this->question($analysis, $trueFalse);
        $this->assertEquals(75, $tf['percentCorrect']);
        $this->assertSame([3, 1], array_column($tf['choices'], 'count'));

        // Scores: 100, 100, 50, 0.
        $summary = $analysis['summary'];
        $this->assertSame(4, $summary['submittedAttempts']);
        $this->assertSame(4, $summary['candidates']);
        $this->assertSame(0, $summary['awaitingGrading']);
        $this->assertEquals(62.5, $summary['mean']);
        $this->assertEquals(75, $summary['median']);
        $this->assertEquals(100, $summary['highest']);
        $this->assertEquals(0, $summary['lowest']);
        $this->assertEquals(60, $summary['passingScore']);
        $this->assertSame(2, $summary['passed']);
        $this->assertSame(2, $summary['failed']);
        $this->assertEquals(50, $summary['passRate']);
        // Score ranges, lowest first: 0 and 50 are below 60, both 100s are 90–100.
        $this->assertSame(['Below 60', '60–69.99', '70–79.99', '80–89.99', '90–100'], array_column($summary['scoreDistribution'], 'label'));
        $this->assertSame([2, 0, 0, 0, 2], array_column($summary['scoreDistribution'], 'value'));
    }

    public function test_analysis_uses_the_attempt_snapshot_not_the_current_question_bank(): void
    {
        $exam = $this->makeExamination();
        $item = $this->addItem($exam, $this->mcq(1));
        $this->submitWith($this->candidateInA, $exam, [$item->id => $this->choices($item)[0]]);
        $prompt = $item->question->prompt;

        // Simulates a later bank change (published questions are locked in the application).
        DB::table('questions')->where('id', $item->question_id)->update(['prompt' => 'Changed later']);
        DB::table('question_choices')->where('question_id', $item->question_id)->update(['text' => 'Changed choice']);

        $this->assertSame('Changed later', $item->question->fresh()->prompt);

        $question = $this->question($this->analysis($exam), $item);

        $this->assertSame($prompt, $question['prompt']);
        $this->assertSame('Option A', $question['choices'][0]['text']);
    }

    // Essays ----------------------------------------------------------------

    public function test_essays_report_graded_and_ungraded_responses(): void
    {
        $exam = $this->makeExamination();
        $mcq = $this->addItem($exam, $this->mcq(1));
        $essay = $this->addItem($exam, $this->essay(), '4.00');
        [$a, $b, $c, $d] = $this->candidates(4);
        $correct = $this->choices($mcq)[0];

        $gradedHigh = $this->submitWith($a, $exam, [$mcq->id => $correct, $essay->id => 'Synthetic response one']);
        $gradedLow = $this->submitWith($b, $exam, [$mcq->id => $correct, $essay->id => 'Synthetic response two']);
        $this->submitWith($c, $exam, [$mcq->id => $correct, $essay->id => 'Synthetic response three']);
        $this->submitWith($d, $exam, [$mcq->id => $correct]);
        $grading = app(ManualEssayGradingService::class);
        $grading->grade($this->alpha, $gradedHigh, $essay->id, '3', null, 0, null);
        $grading->grade($this->alpha, $gradedLow, $essay->id, '1', null, 0, null);

        $analysis = $this->analysis($exam);
        $question = $this->question($analysis, $essay);

        $this->assertSame('essay', $question['type']['value']);
        $this->assertSame(4, $question['delivered']);
        $this->assertSame(3, $question['answered']);
        $this->assertSame(1, $question['unanswered']);
        $this->assertNull($question['percentCorrect']);
        $this->assertNull($question['correct']);
        $this->assertSame([], $question['choices']);
        $this->assertSame(2, $question['essay']['graded']);
        $this->assertSame(2, $question['essay']['ungraded']);
        $this->assertEquals(2, $question['essay']['averageScore']);
        $this->assertEquals(4, $question['essay']['maxPoints']);
        $this->assertEquals(50, $question['essay']['averagePercent']);
        $this->assertEquals(50, $question['difficulty']);

        $summary = $analysis['summary'];
        $this->assertSame(4, $summary['submittedAttempts']);
        $this->assertSame(2, $summary['awaitingGrading']);
        $this->assertSame(2, $summary['scoredAttempts']);
        // (1 + 3) / 5 = 80% and (1 + 1) / 5 = 40%.
        $this->assertEquals(60, $summary['mean']);
        $this->assertEquals(80, $summary['highest']);
        $this->assertEquals(40, $summary['lowest']);
        $this->assertNull($summary['passRate']);
        // Attempts awaiting essay grading are not in the score distribution.
        $this->assertSame(2, array_sum(array_column($summary['scoreDistribution'], 'value')));
        $this->assertSame([1, 0, 0, 1, 0], array_column($summary['scoreDistribution'], 'value'));
    }

    // Scope -----------------------------------------------------------------

    public function test_latest_attempt_per_candidate_is_the_default_and_all_attempts_can_be_chosen(): void
    {
        $exam = $this->makeExamination([]);
        $item = $this->addItem($exam, $this->mcq(1));
        [$correct, $wrong] = $this->choices($item);
        [$retaker, $single] = $this->candidates(2);

        // Retakes are no longer allowed (2026-10-03), but examinations from
        // before keep theirs: the first attempt is parked on another
        // examination while the retake is taken, then put back.
        $first = $this->submitWith($retaker, $exam, [$item->id => $wrong]);
        $parked = $this->makeExamination([]);
        $first->forceFill(['examination_id' => $parked->id])->save();
        $this->submitWith($retaker, $exam, [$item->id => $correct])->forceFill(['attempt_number' => 2])->save();
        $first->forceFill(['examination_id' => $exam->id])->save();
        $this->submitWith($single, $exam, [$item->id => $correct]);

        $latest = $this->analysis($exam);
        $this->assertSame('latest', $latest['scope']);
        $this->assertSame(2, $latest['summary']['submittedAttempts']);
        $this->assertSame(2, $latest['summary']['candidates']);
        $this->assertEquals(100, $this->question($latest, $item)['percentCorrect']);

        $all = $this->analysis($exam, ['scope' => 'all']);
        $this->assertSame('all', $all['scope']);
        $this->assertSame(3, $all['summary']['submittedAttempts']);
        $this->assertSame(2, $all['summary']['candidates']);
        $this->assertSame(3, $this->question($all, $item)['delivered']);
        $this->assertEquals(66.67, $this->question($all, $item)['percentCorrect']);
    }

    public function test_a_question_delivered_to_only_some_attempts_uses_times_delivered(): void
    {
        $exam = $this->makeExamination();
        $always = $this->addItem($exam, $this->mcq(1));
        $sometimes = $this->addItem($exam, $this->mcq(1));
        $never = $this->addItem($exam, $this->mcq(1));
        [$a, $b, $c, $d] = $this->candidates(4);
        $correct = $this->choices($sometimes)[0];
        $wrong = $this->choices($sometimes)[1];

        $this->submitWith($a, $exam, [$sometimes->id => $correct], [$always->id, $sometimes->id]);
        $this->submitWith($b, $exam, [$sometimes->id => $wrong], [$always->id, $sometimes->id]);
        $this->submitWith($c, $exam, [], [$always->id]);
        $this->submitWith($d, $exam, [], [$always->id]);

        $analysis = $this->analysis($exam);
        $question = $this->question($analysis, $sometimes);

        $this->assertSame(4, $this->question($analysis, $always)['delivered']);
        $this->assertSame(2, $question['delivered']);
        $this->assertSame(2, $question['answered']);
        $this->assertSame(0, $question['unanswered']);
        $this->assertEquals(50, $question['percentCorrect']);
        $this->assertEquals([50, 50, 0, 0], array_column($question['choices'], 'percent'));
        $this->assertNotContains($never->id, array_column($analysis['questions'], 'id'));
        $this->assertSame(1, $analysis['undeliveredQuestions']);
    }

    public function test_a_real_random_subset_counts_each_question_where_it_was_drawn(): void
    {
        $exam = $this->makeExamination(['question_draw_count' => 2]);
        $items = [$this->addItem($exam, $this->mcq(1)), $this->addItem($exam, $this->mcq(1)), $this->addItem($exam, $this->mcq(1)), $this->addItem($exam, $this->mcq(1))];
        $drawn = [];
        foreach ($this->candidates(6) as $candidate) {
            $attempt = $this->submitWith($candidate, $exam, array_combine(
                array_map(fn (ExaminationQuestion $item): int => $item->id, $items),
                array_map(fn (ExaminationQuestion $item): int => $this->choices($item)[0], $items),
            ));
            $this->assertCount(2, $attempt->delivery);
            foreach ($attempt->delivery as $delivered) {
                $drawn[$delivered['id']] = ($drawn[$delivered['id']] ?? 0) + 1;
            }
        }

        $analysis = $this->analysis($exam);
        $this->assertSame(12, array_sum(array_column($analysis['questions'], 'delivered')));
        foreach ($analysis['questions'] as $question) {
            $this->assertSame($drawn[$question['id']], $question['delivered']);
            $this->assertEquals(100, $question['percentCorrect']);
        }
        $this->assertSame(4 - count($drawn), $analysis['undeliveredQuestions']);
    }

    // Discrimination, flags, and sorting --------------------------------------

    /**
     * Ten attempts of five one-point items. Group size: round(10 x 27%) = 3.
     *
     *   top 3      anchors 1–4 correct, target wrong   80%
     *   middle 4   anchors 1–2 correct, target wrong   40%
     *   bottom 3   no anchor correct, target correct   20%
     *
     * @return array{0: Examination, 1: list<ExaminationQuestion>, 2: ExaminationQuestion}
     */
    private function discriminationDataset(int $attempts = 10): array
    {
        $exam = $this->makeExamination();
        $anchors = [];
        for ($i = 0; $i < 4; $i++) {
            $anchors[] = $this->addItem($exam, $this->mcq(1));
        }
        $target = $this->addItem($exam, $this->mcq(1));
        foreach (array_slice($this->candidates(10), 0, $attempts) as $index => $candidate) {
            $group = $index < 3 ? 'top' : ($index < 7 ? 'middle' : 'bottom');
            $correctAnchors = ['top' => 4, 'middle' => 2, 'bottom' => 0][$group];
            $answers = [];
            foreach ($anchors as $position => $anchor) {
                $answers[$anchor->id] = $this->choices($anchor)[$position < $correctAnchors ? 0 : 1];
            }
            $answers[$target->id] = $this->choices($target)[$group === 'bottom' ? 0 : 1];
            $this->submitWith($candidate, $exam, $answers);
        }

        return [$exam, $anchors, $target];
    }

    public function test_discrimination_index_compares_the_upper_and_lower_27_percent(): void
    {
        [$exam, $anchors, $target] = $this->discriminationDataset();

        $analysis = $this->analysis($exam);

        $this->assertTrue($analysis['discrimination']['available']);
        $this->assertSame(3, $analysis['discrimination']['groupSize']);
        $this->assertSame(10, $analysis['discrimination']['rankedAttempts']);
        $this->assertNull($analysis['discrimination']['reason']);

        $targetRow = $this->question($analysis, $target);
        $this->assertEquals(30, $targetRow['percentCorrect']);
        $this->assertEquals(-1, $targetRow['discrimination']);
        $this->assertContains('negative_discrimination', $this->flagCodes($targetRow));
        $this->assertContains('distractor_preferred', $this->flagCodes($targetRow));
        $this->assertNotContains('mostly_missed', $this->flagCodes($targetRow));

        // Anchor 1: all top, all middle, no bottom correct.
        $anchorRow = $this->question($analysis, $anchors[0]);
        $this->assertEquals(70, $anchorRow['percentCorrect']);
        $this->assertEquals(1, $anchorRow['discrimination']);
        $this->assertSame([], $this->flagCodes($anchorRow));
    }

    public function test_discrimination_is_not_shown_below_ten_submitted_attempts(): void
    {
        [$exam, , $target] = $this->discriminationDataset(9);

        $analysis = $this->analysis($exam);

        $this->assertFalse($analysis['discrimination']['available']);
        $this->assertNull($analysis['discrimination']['groupSize']);
        $this->assertStringContainsString('at least 10 submitted attempts', $analysis['discrimination']['reason']);
        $this->assertStringContainsString('9 are available', $analysis['discrimination']['reason']);
        $this->assertSame([null], array_values(array_unique(array_column($analysis['questions'], 'discrimination'), SORT_REGULAR)));
        $this->assertNotContains('negative_discrimination', $this->flagCodes($this->question($analysis, $target)));
    }

    public function test_flags_describe_missed_easy_and_distractor_items(): void
    {
        $exam = $this->makeExamination();
        $missed = $this->addItem($exam, $this->mcq(1));
        $easy = $this->addItem($exam, $this->mcq(1));
        $balanced = $this->addItem($exam, $this->mcq(1));
        foreach ($this->candidates(4) as $index => $candidate) {
            $this->submitWith($candidate, $exam, [
                // 1 of 4 correct; distractor B chosen 3 times.
                $missed->id => $this->choices($missed)[$index === 0 ? 0 : 1],
                $easy->id => $this->choices($easy)[0],
                // 2 of 4 correct; distractors B and C once each.
                $balanced->id => $this->choices($balanced)[[0, 0, 1, 2][$index]],
            ]);
        }

        $analysis = $this->analysis($exam);

        $this->assertSame(['mostly_missed', 'distractor_preferred'], $this->flagCodes($this->question($analysis, $missed)));
        $this->assertSame('Most candidates missed this', $this->question($analysis, $missed)['flags'][0]['label']);
        $this->assertSame(['very_easy'], $this->flagCodes($this->question($analysis, $easy)));
        $this->assertSame([], $this->flagCodes($this->question($analysis, $balanced)));
    }

    public function test_questions_sort_by_most_missed_exam_order_or_discrimination(): void
    {
        [$exam, $anchors, $target] = $this->discriminationDataset();
        $order = [...array_map(fn (ExaminationQuestion $item): int => $item->id, $anchors), $target->id];

        // Percent correct: anchors 70, 70, 30, 30; target 30. Ties keep exam order.
        $missed = array_column($this->analysis($exam)['questions'], 'id');
        $this->assertSame([$anchors[2]->id, $anchors[3]->id, $target->id, $anchors[0]->id, $anchors[1]->id], $missed);

        $this->assertSame($order, array_column($this->analysis($exam, ['sort' => 'order'])['questions'], 'id'));

        $byDiscrimination = array_column($this->analysis($exam, ['sort' => 'discrimination'])['questions'], 'id');
        $this->assertSame($target->id, $byDiscrimination[0]);
        $this->assertSame([1, 2, 3, 4, 5], array_column($this->analysis($exam, ['sort' => 'order'])['questions'], 'position'));
    }

    // Privacy and efficiency ------------------------------------------------

    public function test_payload_contains_no_candidate_identity_or_individual_answers(): void
    {
        $exam = $this->makeExamination();
        $mcq = $this->addItem($exam, $this->mcq(1));
        $essay = $this->addItem($exam, $this->essay());
        $candidates = $this->candidates(3);
        $attempts = [];
        foreach ($candidates as $candidate) {
            $attempts[] = $this->submitWith($candidate, $exam, [$mcq->id => $this->choices($mcq)[0], $essay->id => 'Private essay text of '.$candidate->candidate_number]);
        }

        $props = $this->inertiaProps($this->actingAs($this->alpha)->get($this->url($exam))->assertOk());
        $json = json_encode($props['analysis'] + ['examination' => $props['examination']]);

        foreach ($candidates as $candidate) {
            $this->assertStringNotContainsString($candidate->candidate_number, $json);
            $this->assertStringNotContainsString($candidate->full_name, $json);
        }
        $this->assertStringNotContainsString('Private essay text', $json);
        $keys = [];
        $collect = function (array $value) use (&$collect, &$keys): void {
            foreach ($value as $key => $child) {
                $keys[] = $key;
                if (is_array($child)) {
                    $collect($child);
                }
            }
        };
        $collect($props['analysis']);
        foreach (['candidate', 'candidateId', 'candidate_id', 'candidates_list', 'attempt', 'attemptId', 'attempts', 'answers', 'answer', 'name', 'scoring_key'] as $forbidden) {
            $this->assertNotContains($forbidden, $keys, "The analysis payload must not contain '{$forbidden}'.");
        }
    }

    public function test_query_count_stays_constant_as_attempts_grow(): void
    {
        $exam = $this->makeExamination();
        $mcq = $this->addItem($exam, $this->mcq(1));
        $essay = $this->addItem($exam, $this->essay());
        $candidates = $this->candidates(8);
        $submit = fn (Candidate $candidate) => $this->submitWith($candidate, $exam, [$mcq->id => $this->choices($mcq)[0], $essay->id => 'Synthetic']);
        $countQueries = function () use ($exam): int {
            $count = 0;
            DB::listen(function () use (&$count): void {
                $count++;
            });
            // A fresh user each time: relations loaded by an earlier request (role, campus) are not carried over.
            $this->actingAs($this->alpha->fresh())->get($this->url($exam, ['scope' => 'all']))->assertOk();

            return $count;
        };

        array_map($submit, array_slice($candidates, 0, 2));
        $few = $countQueries();
        array_map($submit, array_slice($candidates, 2));
        $many = $countQueries();

        $this->assertGreaterThan(0, $few);
        $this->assertSame($few, $many);
    }

    public function test_the_analysis_is_saved_as_a_pdf_by_the_assigned_instructor_only(): void
    {
        $exam = $this->makeExamination(['passing_score' => '60.00']);
        $mcq = $this->addItem($exam, $this->mcq(2));
        [$a, $b] = $this->candidates(2);
        [$optionA, $optionB] = $this->choices($mcq);
        $this->submitWith($a, $exam, [$mcq->id => $optionB]);
        $this->submitWith($b, $exam, [$mcq->id => $optionA]);

        $pdf = $this->actingAs($this->alpha)->get(route('examinations.analysis.pdf', ['examination' => $exam, 'scope' => 'all', 'sort' => 'order']))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $pdf->getContent());

        $this->actingAs($this->bravo)->get(route('examinations.analysis.pdf', $exam))->assertForbidden();
        $this->actingAs($this->userWithRole(SystemRole::SuperAdministrator))->get(route('examinations.analysis.pdf', $exam))->assertForbidden();
        $this->actingAs($a->user)->get(route('examinations.analysis.pdf', $exam))->assertForbidden();
        $this->actingAs($this->alpha)->get(route('examinations.analysis.pdf', ['examination' => $exam, 'sort' => 'random']))->assertSessionHasErrors('sort');
    }

    public function test_no_pdf_before_anything_is_submitted(): void
    {
        $exam = $this->makeExamination();
        $this->addItem($exam, $this->mcq());

        $this->actingAs($this->alpha)->get(route('examinations.analysis.pdf', $exam))->assertNotFound();
    }
}
