<?php

namespace Tests\Feature\QuestionBank;

use App\Enums\QuestionType;
use App\Enums\SystemRole;
use App\Models\Question;
use App\Services\QuestionBank\QuestionContent;
use App\Services\QuestionBank\QuestionPresenter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The question bank list (UI_UX_DESIGN.md §54): only the subjects the user
 * teaches, filters that never widen that scope, search, and pagination.
 */
class QuestionBankListTest extends TestCase
{
    use BuildsQuestionBankFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildQuestionBankFixtures();
    }

    public function test_lists_only_questions_of_the_subjects_the_user_teaches(): void
    {
        $first = $this->createQuestion($this->subject1, $this->multipleChoice('Subject 1 first question?'));
        $second = $this->createQuestion($this->subject1, QuestionContent::essay('Subject 1 essay prompt.'), author: $this->bravo);
        $this->createQuestion($this->subject2, $this->multipleChoice('Hidden question of Subject 2?'), author: $this->bravo);
        Question::factory()->forSubject($this->subject3)->create(['prompt' => 'Hidden question of Subject 3?']);

        $props = $this->propsOf($this->actingAs($this->alpha)->get('/question-bank')->assertOk());

        $this->assertSame([['id' => $this->subject1->id, 'code' => 'SUBJ-1', 'name' => 'Subject 1']], $props['subjects']);
        $this->assertSame(2, $props['questions']['total']);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], array_column($props['questions']['data'], 'id'));
        $this->assertDoesNotReveal($props, ['Hidden question', 'Subject 2', 'Subject 3', 'SUBJ-2', 'SUBJ-3']);

        // Bravo teaches Subjects 1 and 2.
        $props = $this->propsOf($this->actingAs($this->bravo)->get('/question-bank')->assertOk());
        $this->assertSame(['Subject 1', 'Subject 2'], array_column($props['subjects'], 'name'));
        $this->assertSame(3, $props['questions']['total']);
        $this->assertDoesNotReveal($props, ['Hidden question of Subject 3']);
    }

    public function test_rows_identify_questions_without_choices_answers_or_explanations(): void
    {
        $question = $this->createQuestion(
            $this->subject1,
            QuestionContent::multipleChoice('Which planet is the sample planet?', $this->choices([['Distinct choice text', false], ['Correct choice text', true]])),
            topic: 'Topic 1',
            points: '2.5',
            explanation: 'Secret explanation text.',
        );

        $this->actingAs($this->alpha)
            ->get('/question-bank')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/question-bank/index')
                ->has('questions.data', 1)
                ->has('questions.data.0', fn (Assert $row) => $row
                    ->where('id', $question->id)
                    ->where('subject', ['id' => $this->subject1->id, 'code' => 'SUBJ-1', 'name' => 'Subject 1'])
                    ->where('topic.name', 'Topic 1')
                    ->etc()
                    ->where('type', ['value' => 'multiple_choice', 'label' => 'Multiple Choice'])
                    ->where('excerpt', 'Which planet is the sample planet?')
                    ->where('points', '2.5')
                    ->where('isActive', true)
                    ->where('isLocked', false)));

        $props = $this->propsOf($this->actingAs($this->alpha)->get('/question-bank'));
        $this->assertSame(
            ['id', 'subject', 'topic', 'type', 'excerpt', 'points', 'isActive', 'isLocked'],
            array_keys($props['questions']['data'][0]),
        );
        $this->assertDoesNotReveal($props, ['Distinct choice text', 'Correct choice text', 'Secret explanation text', 'isCorrect', 'explanation']);
    }

    public function test_the_excerpt_is_one_line_of_about_two_hundred_characters(): void
    {
        $this->createQuestion($this->subject1, QuestionContent::essay("First line.\n\nSecond   line\tafter a tab."));
        $long = trim(str_repeat('word ', 100));
        $this->createQuestion($this->subject1, QuestionContent::essay($long));

        $rows = $this->propsOf($this->actingAs($this->alpha)->get('/question-bank'))['questions']['data'];
        $excerpts = array_column($rows, 'excerpt');

        $this->assertContains('First line. Second line after a tab.', $excerpts);

        $shortened = array_values(array_filter($excerpts, fn (string $excerpt): bool => str_starts_with($excerpt, 'word')))[0];
        $this->assertStringEndsWith('…', $shortened);
        $this->assertLessThanOrEqual(QuestionPresenter::EXCERPT_LENGTH + 1, mb_strlen($shortened));
        $this->assertGreaterThan(QuestionPresenter::EXCERPT_LENGTH - 30, mb_strlen($shortened));
        // Cut at a word boundary.
        $this->assertStringEndsWith('word…', $shortened);
    }

    public function test_the_excerpt_keeps_characters_that_look_like_markup(): void
    {
        $this->createQuestion($this->subject1, QuestionContent::essay('Is 3 < 5 and 7 > 2 in <b>this</b> case?'));

        $rows = $this->propsOf($this->actingAs($this->alpha)->get('/question-bank'))['questions']['data'];

        $this->assertSame('Is 3 < 5 and 7 > 2 in <b>this</b> case?', $rows[0]['excerpt']);
    }

    public function test_newest_questions_come_first(): void
    {
        $older = $this->createQuestion($this->subject1, $this->multipleChoice('Older question?'));
        $this->travel(1)->minutes();
        $newer = $this->createQuestion($this->subject1, $this->multipleChoice('Newer question?'));
        // Created in the same second: the higher id is newer.
        $newest = $this->createQuestion($this->subject1, $this->multipleChoice('Newest question?'));

        $rows = $this->propsOf($this->actingAs($this->alpha)->get('/question-bank'))['questions']['data'];

        $this->assertSame([$newest->id, $newer->id, $older->id], array_column($rows, 'id'));
    }

    public function test_search_matches_part_of_the_question_text_ignoring_case_and_treats_wildcards_literally(): void
    {
        $percent = $this->createQuestion($this->subject1, QuestionContent::essay('A discount of 50% applies.'));
        $digits = $this->createQuestion($this->subject1, QuestionContent::essay('A discount of 505 applies.'));
        $underscore = $this->createQuestion($this->subject1, QuestionContent::essay('Name it snake_case.'));
        $letter = $this->createQuestion($this->subject1, QuestionContent::essay('Name it snakeXcase.'));
        $backslash = $this->createQuestion($this->subject1, QuestionContent::essay('The folder is C:\\temp here.'));
        $this->createQuestion($this->subject2, QuestionContent::essay('A discount of 50% in Subject 2.'), author: $this->bravo);

        $this->actingAs($this->alpha);
        $this->assertSame([$percent->id], $this->listedIds(['search' => '50%']));
        $this->assertSame([$underscore->id], $this->listedIds(['search' => 'e_c']));
        $this->assertSame([$backslash->id], $this->listedIds(['search' => ':\\t']));
        $this->assertEqualsCanonicalizing([$percent->id, $digits->id], $this->listedIds(['search' => 'DISCOUNT']));
        $this->assertEqualsCanonicalizing([$underscore->id, $letter->id], $this->listedIds(['search' => '  snake  ']));
        $this->assertSame([], $this->listedIds(['search' => 'nothing like this']));

        $this->actingAs($this->alpha)
            ->get('/question-bank?search=%20snake%20')
            ->assertInertia(fn (Assert $page) => $page->where('filters.search', 'snake'));
    }

    public function test_filters_by_subject_topic_type_and_status(): void
    {
        // Bravo teaches Subjects 1 and 2.
        $mcq = $this->createQuestion($this->subject1, $this->multipleChoice('Subject 1 multiple choice?'), topic: 'Topic 1', author: $this->bravo);
        $trueFalse = $this->createQuestion($this->subject1, QuestionContent::trueFalse('Subject 1 statement.', true), topic: 'Topic 2', author: $this->bravo);
        $essay = $this->createQuestion($this->subject2, QuestionContent::essay('Subject 2 essay.'), topic: 'Topic 1', author: $this->bravo);
        $inactive = $this->createQuestion($this->subject2, $this->multipleChoice('Subject 2 retired question?'), author: $this->bravo);
        $this->service()->deactivate($inactive, $this->bravo);
        $topic1 = $this->subject1->questionTopics()->where('name', 'Topic 1')->sole();

        $this->actingAs($this->bravo);
        $this->assertEqualsCanonicalizing([$mcq->id, $trueFalse->id], $this->listedIds(['subject' => $this->subject1->id]));
        $this->assertEqualsCanonicalizing([$essay->id, $inactive->id], $this->listedIds(['subject' => $this->subject2->id]));
        $this->assertSame([$mcq->id], $this->listedIds(['subject' => $this->subject1->id, 'topic' => $topic1->id]));
        $this->assertSame([$trueFalse->id], $this->listedIds(['type' => QuestionType::TrueFalse->value]));
        $this->assertEqualsCanonicalizing([$mcq->id, $inactive->id], $this->listedIds(['type' => QuestionType::MultipleChoice->value]));
        $this->assertSame([$essay->id], $this->listedIds(['type' => QuestionType::Essay->value]));
        $this->assertSame([$inactive->id], $this->listedIds(['status' => 'inactive']));
        $this->assertEqualsCanonicalizing([$mcq->id, $trueFalse->id, $essay->id], $this->listedIds(['status' => 'active']));
        $this->assertSame([$inactive->id], $this->listedIds(['subject' => $this->subject2->id, 'type' => 'multiple_choice', 'status' => 'inactive']));

        $this->get('/question-bank?subject='.$this->subject1->id.'&topic='.$topic1->id.'&type=multiple_choice&status=active&search=choice')
            ->assertInertia(fn (Assert $page) => $page->where('filters', [
                'search' => 'choice',
                'subject' => (string) $this->subject1->id,
                'topic' => (string) $topic1->id,
                'type' => 'multiple_choice',
                'status' => 'active',
            ]));
    }

    public function test_topic_options_are_the_selected_subjects_topics_that_have_questions(): void
    {
        $this->createQuestion($this->subject1, $this->multipleChoice('First?'), topic: 'Topic B');
        $this->createQuestion($this->subject1, $this->multipleChoice('Second?'), topic: 'Topic A');
        $this->topic($this->subject1, 'Topic Without Questions');
        $this->createQuestion($this->subject2, $this->multipleChoice('Other subject?'), topic: 'Other Subject Topic', author: $this->bravo);

        // No subject selected: no topic options (the topic filter is disabled).
        $this->actingAs($this->alpha)
            ->get('/question-bank')
            ->assertInertia(fn (Assert $page) => $page->where('topics', []));

        $props = $this->propsOf($this->actingAs($this->alpha)->get('/question-bank?subject='.$this->subject1->id));
        $this->assertSame(['Topic A', 'Topic B'], array_column($props['topics'], 'name'));
        $this->assertDoesNotReveal($props, ['Topic Without Questions', 'Other Subject Topic']);
    }

    public function test_topic_filter_is_ignored_without_a_subject_or_for_a_topic_of_another_subject(): void
    {
        $inSubject1 = $this->createQuestion($this->subject1, $this->multipleChoice('Subject 1 question?'), topic: 'Topic 1', author: $this->bravo);
        $inSubject2 = $this->createQuestion($this->subject2, $this->multipleChoice('Subject 2 question?'), topic: 'Topic 1', author: $this->bravo);
        $subject2Topic = $this->subject2->questionTopics()->sole();

        $this->actingAs($this->bravo);

        // Without a subject the topic is ignored.
        $this->assertEqualsCanonicalizing([$inSubject1->id, $inSubject2->id], $this->listedIds(['topic' => $subject2Topic->id]));
        $this->get('/question-bank?topic='.$subject2Topic->id)->assertInertia(fn (Assert $page) => $page->where('filters.topic', ''));

        // A topic of another subject is ignored too.
        $this->assertSame([$inSubject1->id], $this->listedIds(['subject' => $this->subject1->id, 'topic' => $subject2Topic->id]));
        $this->get('/question-bank?subject='.$this->subject1->id.'&topic='.$subject2Topic->id)
            ->assertInertia(fn (Assert $page) => $page->where('filters.subject', (string) $this->subject1->id)->where('filters.topic', ''));
    }

    public function test_invalid_and_out_of_scope_filter_values_are_ignored_and_echoed_as_empty(): void
    {
        $own = $this->createQuestion($this->subject1, $this->multipleChoice('Own question?'));
        $this->createQuestion($this->subject2, $this->multipleChoice('Not taught by Alpha?'), author: $this->bravo);
        Question::factory()->forSubject($this->subject3)->create();

        $emptyFilters = ['search' => '', 'subject' => '', 'topic' => '', 'type' => '', 'status' => ''];
        $queries = [
            'subject='.$this->subject2->id,
            'subject='.$this->subject3->id,
            'subject=999999',
            'subject=abc',
            'subject=-1',
            'subject[]='.$this->subject1->id,
            'topic=1',
            'topic[]=1',
            'type=matching',
            'type[]=essay',
            'status=deleted',
            'status[]=active',
            'search[]=Own',
            'page=abc',
        ];

        foreach ($queries as $query) {
            $response = $this->actingAs($this->alpha)->get('/question-bank?'.$query)->assertOk();
            $props = $this->propsOf($response);

            $this->assertSame($emptyFilters, $props['filters'], "Filters for [{$query}]");
            $this->assertSame([$own->id], array_column($props['questions']['data'], 'id'), "Questions for [{$query}]");
            $this->assertDoesNotReveal($props, ['Not taught by Alpha', 'Subject 2', 'Subject 3']);
        }
    }

    public function test_results_are_paginated_ten_per_page_and_keep_the_filters(): void
    {
        foreach (range(1, 15) as $number) {
            Question::factory()->forSubject($this->subject1)->createdBy($this->alpha)->create([
                'prompt' => "Paged question {$number}",
                'created_at' => now()->subMinutes(100 - $number),
            ]);
        }
        Question::factory()->forSubject($this->subject2)->count(3)->create();

        $first = $this->propsOf($this->actingAs($this->alpha)->get('/question-bank?search=Paged'))['questions'];
        $this->assertCount(10, $first['data']);
        $this->assertSame(15, $first['total']);
        $this->assertSame(1, $first['from']);
        $this->assertSame(10, $first['to']);
        $this->assertSame(2, $first['last_page']);
        $this->assertSame('Paged question 15', Question::query()->findOrFail($first['data'][0]['id'])->prompt);
        $this->assertStringContainsString('search=Paged', (string) $first['next_page_url']);

        $second = $this->propsOf($this->actingAs($this->alpha)->get('/question-bank?search=Paged&page=2'))['questions'];
        $this->assertCount(5, $second['data']);
        $this->assertSame(11, $second['from']);
        $this->assertSame(15, $second['to']);
        $this->assertSame('Paged question 1', Question::query()->findOrFail($second['data'][4]['id'])->prompt);
        $this->assertSame([], array_intersect(array_column($first['data'], 'id'), array_column($second['data'], 'id')));
    }

    public function test_pages_past_the_end_and_huge_page_numbers_are_empty_instead_of_failing(): void
    {
        $this->createQuestion($this->subject1);

        foreach (['2', '999999', '9223372036854775807', '99999999999999999999'] as $page) {
            $response = $this->actingAs($this->alpha)->get('/question-bank?page='.$page)->assertOk();
            $questions = $this->propsOf($response)['questions'];

            $this->assertSame(1, $questions['total'], "Total for page [{$page}]");
        }
    }

    public function test_instructor_without_subjects_sees_no_subjects_and_no_questions(): void
    {
        $this->createQuestion($this->subject1, $this->multipleChoice('Somebody else question?'));
        $newcomer = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Charlie']);

        $props = $this->propsOf($this->actingAs($newcomer)->get('/question-bank')->assertOk());

        $this->assertSame([], $props['subjects']);
        $this->assertSame(0, $props['questions']['total']);
        $this->assertSame([], $props['questions']['data']);
        $this->assertSame(QuestionType::options(), $props['types']);
        $this->assertDoesNotReveal($props, ['Somebody else question', 'Subject 1']);
    }

    /**
     * @param  array<string, int|string>  $query
     * @return list<int>
     */
    private function listedIds(array $query): array
    {
        $response = $this->get('/question-bank?'.http_build_query($query))->assertOk();

        return array_column($this->propsOf($response)['questions']['data'], 'id');
    }
}
