<?php

namespace Tests\Feature\QuestionBank;

use App\Enums\AuditAction;
use App\Enums\Permission as PermissionCode;
use App\Enums\QuestionType;
use App\Models\AuditLog;
use App\Models\Question;
use App\Models\QuestionChoice;
use App\Models\QuestionTopic;
use App\Models\User;
use App\Services\QuestionBank\QuestionBankService;
use App\Services\QuestionBank\QuestionImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Importing questions from a CSV file into one taught subject: access, the
 * template, the file format, validation of every row with the question
 * form's rules, all or nothing, limits, topics, and auditing.
 */
class QuestionImportTest extends TestCase
{
    use BuildsQuestionBankFixtures;

    private const URL = '/question-bank/import';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildQuestionBankFixtures();
    }

    // ---------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function importRoutes(): array
    {
        return [
            'import page' => ['get', self::URL],
            'upload' => ['post', self::URL],
            'template' => ['get', self::URL.'/template'],
        ];
    }

    #[DataProvider('importRoutes')]
    public function test_guests_are_redirected_to_sign_in(string $method, string $path): void
    {
        $this->send(null, $method, $path)->assertRedirect('/login');

        $this->assertNothingImported();
    }

    #[DataProvider('importRoutes')]
    public function test_candidates_are_forbidden(string $method, string $path): void
    {
        $this->send($this->candidateInA->user, $method, $path)
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('errors/error')->where('status', 403));

        $this->assertNothingImported();
    }

    #[DataProvider('importRoutes')]
    public function test_administrators_are_forbidden(string $method, string $path): void
    {
        foreach ([$this->academicAdmin, $this->superAdmin] as $administrator) {
            $this->send($administrator, $method, $path)->assertForbidden();
        }

        $this->assertNothingImported();
    }

    #[DataProvider('importRoutes')]
    public function test_teaching_staff_without_the_question_bank_permission_are_forbidden(string $method, string $path): void
    {
        $teacher = $this->withCustomRole($this->alpha, 'teacher_without_bank', [
            PermissionCode::AccessStaffArea,
            PermissionCode::TeachClasses,
            PermissionCode::RecordGrades,
        ]);

        $this->send($teacher, $method, $path)->assertForbidden();

        $this->assertNothingImported();
    }

    public function test_instructors_cannot_import_into_a_subject_they_do_not_teach(): void
    {
        // Alpha teaches Subject 1 only.
        foreach ([$this->subject2->id, $this->subject3->id, 999999, 'abc', ''] as $subjectId) {
            $this->import($this->alpha, $this->validFile(), $subjectId)
                ->assertRedirect()
                ->assertSessionHasErrors('subject_id');
        }

        $this->actingAs($this->alpha)
            ->post(self::URL, ['subject_id' => [$this->subject1->id], 'file' => $this->csvUpload($this->validFile())])
            ->assertSessionHasErrors(['subject_id' => 'Select one of the subjects you teach.']);

        $this->assertNothingImported();
    }

    public function test_a_former_instructor_cannot_import_into_a_subject_of_a_leftover_assignment(): void
    {
        $this->import($this->formerInstructor(), $this->validFile())
            ->assertSessionHasErrors(['subject_id' => 'Select one of the subjects you teach.']);

        $this->assertNothingImported();
    }

    public function test_import_page_offers_only_the_taught_subjects_and_the_limits(): void
    {
        $this->actingAs($this->bravo)
            ->get(self::URL.'?subject='.$this->subject2->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/question-bank/import')
                ->where('subjects', [
                    ['id' => $this->subject1->id, 'code' => 'SUBJ-1', 'name' => 'Subject 1'],
                    ['id' => $this->subject2->id, 'code' => 'SUBJ-2', 'name' => 'Subject 2'],
                ])
                ->where('selectedSubjectId', $this->subject2->id)
                ->where('limits', ['maxRows' => 500, 'maxFileKilobytes' => 1024]));

        $selected = fn (User $user, string $query): mixed => $this->propsOf($this->actingAs($user)->get(self::URL.$query))['selectedSubjectId'];

        $this->assertNull($selected($this->bravo, ''));
        $this->assertNull($selected($this->bravo, '?subject='.$this->subject3->id));
        $this->assertNull($selected($this->bravo, '?subject[]='.$this->subject1->id));
        // The only subject Alpha teaches is preselected.
        $this->assertSame($this->subject1->id, $selected($this->alpha, ''));
    }

    // ---------------------------------------------------------------------
    // Template
    // ---------------------------------------------------------------------

    public function test_template_has_the_header_and_one_example_of_each_type(): void
    {
        $response = $this->actingAs($this->alpha)->get(self::URL.'/template');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertDownload('question-import-template.csv');

        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringStartsWith("\u{FEFF}", $content);

        $lines = explode("\r\n", rtrim(substr($content, 3), "\r\n"));
        $this->assertCount(4, $lines);
        $this->assertSame(implode(',', QuestionImportService::COLUMNS), $lines[0]);
        $this->assertSame(
            ['multiple_choice', 'true_false', 'essay'],
            array_map(fn (string $line): string => str_getcsv($line, ',', '"', '')[0], array_slice($lines, 1)),
        );
    }

    public function test_the_template_imports_as_is(): void
    {
        $template = (string) $this->actingAs($this->alpha)->get(self::URL.'/template')->getContent();

        $this->import($this->alpha, $template)->assertSessionHasNoErrors();

        $this->assertSame(['multiple_choice', 'true_false', 'essay'], Question::query()->orderBy('id')->get()->map(fn (Question $question): string => $question->type->value)->all());
    }

    // ---------------------------------------------------------------------
    // Successful imports
    // ---------------------------------------------------------------------

    public function test_imports_every_type_with_topics_points_and_explanations(): void
    {
        $file = $this->csv([
            ['type' => 'multiple_choice', 'question' => 'Which option is correct?', 'choice_a' => 'Option A', 'choice_b' => 'Option B', 'choice_c' => 'Option C', 'correct' => 'b', 'points' => '2.5', 'topic' => 'Topic 1', 'explanation' => 'Option B follows the rule.'],
            ['type' => 'true_false', 'question' => 'The statement is false.', 'correct' => 'False', 'points' => '', 'topic' => 'Topic 2'],
            ['type' => 'essay', 'question' => 'Explain the sample rule.', 'points' => '10', 'explanation' => 'Look for one example.'],
            ['type' => 'MC', 'question' => 'Six choices?', 'choice_a' => '1', 'choice_b' => '2', 'choice_c' => '3', 'choice_d' => '4', 'choice_e' => '5', 'choice_f' => '6', 'correct' => 'F'],
            ['type' => 'tf', 'question' => 'The statement is true.', 'correct' => 'TRUE'],
            ['type' => 'Essay', 'question' => 'Describe the process.'],
        ]);

        $this->import($this->alpha, $file)
            ->assertSessionHasNoErrors()
            ->assertRedirect('/question-bank?subject='.$this->subject1->id)
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '6 questions imported.']);

        $questions = Question::query()->with(['choices', 'topic'])->orderBy('id')->get();
        $this->assertCount(6, $questions);
        $this->assertTrue($questions->every(fn (Question $question): bool => $question->subject_id === $this->subject1->id
            && $question->created_by === $this->alpha->id
            && $question->is_active
            && $question->locked_at === null));

        [$multipleChoice, $trueFalse, $essay, $sixChoices, $trueStatement, $secondEssay] = $questions->all();

        $this->assertSame(QuestionType::MultipleChoice, $multipleChoice->type);
        $this->assertSame('Which option is correct?', $multipleChoice->prompt);
        $this->assertSame('2.50', $multipleChoice->points);
        $this->assertSame('Topic 1', $multipleChoice->topic?->name);
        $this->assertSame('Option B follows the rule.', $multipleChoice->explanation);
        $this->assertSame(
            [['Option A', false], ['Option B', true], ['Option C', false]],
            $multipleChoice->choices->map(fn (QuestionChoice $choice): array => [$choice->text, $choice->is_correct])->all(),
        );

        $this->assertSame(QuestionType::TrueFalse, $trueFalse->type);
        $this->assertSame('1.00', $trueFalse->points, 'Empty points default to 1.');
        $this->assertSame('Topic 2', $trueFalse->topic?->name);
        $this->assertNull($trueFalse->explanation);
        $this->assertSame([['True', false], ['False', true]], $trueFalse->choices->map(fn (QuestionChoice $choice): array => [$choice->text, $choice->is_correct])->all());

        $this->assertSame(QuestionType::Essay, $essay->type);
        $this->assertSame('10.00', $essay->points);
        $this->assertNull($essay->topic);
        $this->assertSame('Look for one example.', $essay->explanation);
        $this->assertCount(0, $essay->choices);

        $this->assertSame(['1', '2', '3', '4', '5', '6'], $sixChoices->choices->pluck('text')->all());
        $this->assertSame(6, $sixChoices->choices->firstWhere('is_correct', true)?->position);
        $this->assertSame(1, $trueStatement->choices->firstWhere('is_correct', true)?->position);
        $this->assertSame(QuestionType::Essay, $secondEssay->type);
    }

    public function test_a_single_question_gets_a_singular_toast(): void
    {
        $this->import($this->alpha, $this->csv([['type' => 'essay', 'question' => 'Describe the process.']]))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '1 question imported.']);
    }

    public function test_header_names_ignore_case_spaces_and_order_and_optional_columns_can_be_left_out(): void
    {
        $file = " Correct ,Choice A,choice-b,QUESTION,Type\n"
            ."B,Yes,No,Is this imported?,multiple_choice\n"
            .",,,Explain it.,essay\n";

        $this->import($this->alpha, $file)->assertSessionHasNoErrors();

        $question = Question::query()->with('choices')->orderBy('id')->firstOrFail();
        $this->assertSame('Is this imported?', $question->prompt);
        $this->assertSame([['Yes', false], ['No', true]], $question->choices->map(fn (QuestionChoice $choice): array => [$choice->text, $choice->is_correct])->all());
        $this->assertSame('1.00', $question->points);
        $this->assertSame(2, Question::query()->count());
    }

    public function test_reads_a_byte_order_mark_crlf_line_endings_and_quoted_fields_with_commas_quotes_and_line_breaks(): void
    {
        $file = "\xEF\xBB\xBFtype,question,choice_a,choice_b,correct,explanation\r\n"
            ."multiple_choice,\"First line, with a comma\r\nSecond line\",\"Say \"\"yes\"\"\",  No  ,a,\"Why:\r\nbecause\"\r\n"
            ."\r\n"
            ."essay,  Trimmed question  ,,,,\r\n";

        $this->import($this->alpha, $file)->assertSessionHasNoErrors();

        $questions = Question::query()->with('choices')->orderBy('id')->get();
        $this->assertCount(2, $questions);
        $this->assertSame("First line, with a comma\nSecond line", $questions[0]->prompt);
        $this->assertSame(['Say "yes"', 'No'], $questions[0]->choices->pluck('text')->all());
        $this->assertSame("Why:\nbecause", $questions[0]->explanation);
        $this->assertSame('Trimmed question', $questions[1]->prompt);
    }

    public function test_row_numbers_are_spreadsheet_rows_even_with_line_breaks_inside_cells_and_blank_rows(): void
    {
        $file = "type,question,correct\n"
            ."true_false,\"Line one\nLine two\nLine three\",True\n"
            ."\n"
            ."true_false,Second statement,Maybe\n";

        $this->import($this->alpha, $file)
            ->assertSessionHasErrors(['rows.4.correct' => 'Enter True or False: whether the statement is true.']);

        $this->assertNothingImported();
    }

    public function test_topics_are_reused_by_name_ignoring_case_and_never_taken_from_another_subject(): void
    {
        $existing = $this->topic($this->subject1, 'Topic 1');
        $otherSubject = $this->topic($this->subject2, 'Shared Name');

        $this->import($this->alpha, $this->csv([
            ['type' => 'essay', 'question' => 'First?', 'topic' => 'topic 1'],
            ['type' => 'essay', 'question' => 'Second?', 'topic' => 'Topic 1'],
            ['type' => 'essay', 'question' => 'Third?', 'topic' => 'Shared Name'],
            ['type' => 'essay', 'question' => 'Fourth?', 'topic' => 'shared name'],
        ]))->assertSessionHasNoErrors();

        $topicIds = Question::query()->orderBy('id')->pluck('question_topic_id')->all();
        $this->assertSame($existing->id, $topicIds[0]);
        $this->assertSame($existing->id, $topicIds[1]);
        $this->assertNotSame($otherSubject->id, $topicIds[2]);
        $this->assertSame($topicIds[2], $topicIds[3]);
        $this->assertSame(2, QuestionTopic::query()->where('subject_id', $this->subject1->id)->count());
        $this->assertSame(1, QuestionTopic::query()->where('subject_id', $this->subject2->id)->count());
    }

    public function test_every_question_is_audited_without_its_text(): void
    {
        $this->import($this->alpha, $this->validFile())->assertSessionHasNoErrors();

        $entries = AuditLog::query()->where('action', AuditAction::QuestionCreated->value)->orderBy('id')->get();
        $this->assertCount(3, $entries);
        $this->assertSame(
            Question::query()->orderBy('id')->pluck('id')->all(),
            $entries->map(fn (AuditLog $entry): int => (int) $entry->auditable_id)->all(),
        );
        $this->assertTrue($entries->every(fn (AuditLog $entry): bool => $entry->actor_id === $this->alpha->id && $entry->auditable_type === 'question'));
        $this->assertSame(['multiple_choice', 'true_false', 'essay'], $entries->pluck('new_values.type')->all());
        $this->assertDoesNotReveal($entries->toArray(), ['Which option is correct?', 'Option B', 'Staff note']);
    }

    public function test_imports_the_maximum_number_of_rows(): void
    {
        $rows = array_map(fn (int $number): array => ['type' => 'essay', 'question' => "Question {$number}?"], range(1, QuestionImportService::MAX_ROWS));

        $this->import($this->alpha, $this->csv($rows))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '500 questions imported.']);

        $this->assertSame(500, Question::query()->count());
    }

    // ---------------------------------------------------------------------
    // Row validation (the question form's rules), all or nothing
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{array<string, string>, string, string}> [row, column, message]
     */
    public static function invalidRows(): array
    {
        $choices = ['choice_a' => 'Option A', 'choice_b' => 'Option B', 'choice_c' => 'Option C', 'choice_d' => 'Option D'];
        $multipleChoice = ['type' => 'multiple_choice', 'question' => 'Which one?', ...$choices, 'correct' => 'B'];

        return [
            'unknown type' => [['type' => 'ranking', 'question' => 'Rank them.'], 'type', QuestionImportService::TYPE_MESSAGE],
            'missing type' => [['type' => '', 'question' => 'Which one?'], 'type', 'Enter the question type. '.QuestionImportService::TYPE_MESSAGE],
            'missing question' => [[...$multipleChoice, 'question' => ''], 'question', 'Enter the question.'],
            'question too long' => [['type' => 'essay', 'question' => str_repeat('x', 5001)], 'question', 'Use at most 5,000 characters.'],
            'letter of an empty choice' => [[...$multipleChoice, 'correct' => 'E'], 'correct', 'Use a letter of a filled choice (A–D).'],
            'missing correct letter' => [[...$multipleChoice, 'correct' => ''], 'correct', 'Enter the letter of the correct choice (A–D).'],
            'number instead of a letter' => [[...$multipleChoice, 'correct' => '2'], 'correct', 'Use the letter of the correct choice (A–D), for example B.'],
            'gap between choices' => [[...$multipleChoice, 'choice_c' => ''], 'choice_c', 'Fill choice C, or move the later choices up: choices are filled from A without gaps.'],
            'one choice only' => [['type' => 'multiple_choice', 'question' => 'Which one?', 'choice_a' => 'Only', 'correct' => 'A'], 'choice_b', 'Fill at least choices A and B. '.QuestionBankService::CHOICE_COUNT_MESSAGE],
            'no choices' => [['type' => 'multiple_choice', 'question' => 'Which one?', 'correct' => 'A'], 'choice_a', 'Fill at least choices A and B. '.QuestionBankService::CHOICE_COUNT_MESSAGE],
            'duplicate choices' => [[...$multipleChoice, 'choice_d' => 'option a'], 'choice_a', QuestionBankService::DUPLICATE_CHOICE_MESSAGE],
            'choice too long' => [[...$multipleChoice, 'choice_c' => str_repeat('c', 1001)], 'choice_c', 'Use at most 1,000 characters.'],
            'true / false answer' => [['type' => 'true_false', 'question' => 'True?', 'correct' => 'Yes'], 'correct', 'Enter True or False: whether the statement is true.'],
            'true / false with choices' => [['type' => 'true_false', 'question' => 'True?', 'choice_a' => 'True', 'correct' => 'True'], 'choice_a', 'Leave the choice columns empty for true / false questions: their choices are always True and False.'],
            'essay with an answer' => [['type' => 'essay', 'question' => 'Explain.', 'correct' => 'A'], 'correct', 'Leave correct empty for essay questions: an instructor grades the answers. Put grading guidance in explanation.'],
            'essay with choices' => [['type' => 'essay', 'question' => 'Explain.', 'choice_b' => 'B'], 'choice_b', 'Leave the choice columns empty for essay questions: they have no answer choices.'],
            'zero points' => [['type' => 'essay', 'question' => 'Explain.', 'points' => '0'], 'points', QuestionBankService::POINTS_MESSAGE],
            'too many points' => [['type' => 'essay', 'question' => 'Explain.', 'points' => '100.01'], 'points', QuestionBankService::POINTS_MESSAGE],
            'points as text' => [['type' => 'essay', 'question' => 'Explain.', 'points' => 'five'], 'points', 'Enter the points as a number, for example 1 or 2.5.'],
            'three decimals' => [['type' => 'essay', 'question' => 'Explain.', 'points' => '1.555'], 'points', 'Use at most two decimal places.'],
            'topic too long' => [['type' => 'essay', 'question' => 'Explain.', 'topic' => str_repeat('t', 101)], 'topic', 'Use at most 100 characters for the topic.'],
            'explanation too long' => [['type' => 'essay', 'question' => 'Explain.', 'explanation' => str_repeat('e', 5001)], 'explanation', 'Use at most 5,000 characters.'],
        ];
    }

    /**
     * @param  array<string, string>  $invalidRow
     */
    #[DataProvider('invalidRows')]
    public function test_each_problem_is_reported_by_row_and_column_and_nothing_is_imported(array $invalidRow, string $column, string $message): void
    {
        // Two valid rows (spreadsheet rows 2 and 3), then the invalid one (row 4).
        $file = $this->csv([
            ['type' => 'essay', 'question' => 'A valid essay.', 'topic' => 'New Topic'],
            ['type' => 'true_false', 'question' => 'A valid statement.', 'correct' => 'True'],
            $invalidRow,
        ]);

        $response = $this->import($this->alpha, $file)->assertSessionHasErrors(["rows.4.{$column}" => $message]);

        $this->assertSame([], array_values(array_filter(
            array_keys(session('errors')->getMessages()),
            fn (string $key): bool => ! str_starts_with($key, 'rows.4.'),
        )), 'Only row 4 has problems.');
        $this->assertNothingImported();
        $this->assertDoesNotReveal(session('errors')->getMessages(), ['A valid essay.', 'Which one?', 'Option A']);
        $response->assertRedirect();
    }

    public function test_a_value_in_a_column_without_a_name_is_reported_for_the_row(): void
    {
        $file = "type,question,\n"
            ."essay,Explain.,\n"
            ."essay,Explain, with a comma not in quotes.\n";

        $this->import($this->alpha, $file)
            ->assertSessionHasErrors(['rows.3.row' => 'This row has a value in a column without a name in the header row. Check for a comma in a value that is not in quotes, or delete the extra column.'])
            ->assertSessionDoesntHaveErrors('rows.2.row');

        $this->assertNothingImported();
    }

    public function test_problems_of_many_rows_are_all_reported_and_listing_stops_after_the_limit(): void
    {
        $this->import($this->alpha, $this->csv([
            ['type' => 'essay', 'question' => ''],
            ['type' => 'essay', 'question' => 'Valid.'],
            ['type' => 'nope', 'question' => 'Valid?', 'points' => '0'],
        ]))->assertSessionHasErrors(['rows.2.question', 'rows.4.type', 'rows.4.points']);

        $rows = array_fill(0, 150, ['type' => 'essay', 'question' => '']);
        $this->import($this->alpha, $this->csv($rows))
            ->assertSessionHasErrors(['file' => 'The file has 150 problems. The first 100 are listed; fix them and upload the file again to see the rest.', 'rows.101.question'])
            ->assertSessionDoesntHaveErrors('rows.102.question');

        $this->assertNothingImported();
    }

    // ---------------------------------------------------------------------
    // Header row
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}> [header row, message]
     */
    public static function invalidHeaders(): array
    {
        return [
            'extra column' => ['type,question,difficulty', 'Remove the column "difficulty". The allowed columns are type, question, choice_a to choice_f, correct, points, topic, and explanation.'],
            'two extra columns' => ['type,question,difficulty,answer', 'Remove the columns "difficulty", "answer". The allowed columns are type, question, choice_a to choice_f, correct, points, topic, and explanation.'],
            'missing question column' => ['type,correct', 'Add the column "question" to the header row.'],
            'missing both required columns' => ['topic,points', 'Add the columns "type" and "question" to the header row.'],
            'repeated column' => ['type,question,Topic,topic', 'The column "topic" appears more than once in the header row. Keep one of each.'],
            'semicolons' => ['type;question;correct', 'Separate the columns with commas, not semicolons. In your spreadsheet, use Save As and choose "CSV UTF-8 (Comma delimited)".'],
            'no header' => [',,', 'The first row must be the header row with the column names (type, question, and so on). Download the template to see the format.'],
        ];
    }

    #[DataProvider('invalidHeaders')]
    public function test_the_header_row_is_checked(string $header, string $message): void
    {
        $this->import($this->alpha, $header."\nessay,Explain.\n")->assertSessionHasErrors(['file' => $message]);

        $this->assertNothingImported();
    }

    // ---------------------------------------------------------------------
    // File limits and content
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}> [contents, message]
     */
    public static function unreadableFiles(): array
    {
        return [
            'empty file' => ['', QuestionImportService::EMPTY_FILE_MESSAGE],
            'only blank lines' => ["\r\n \r\n", QuestionImportService::EMPTY_FILE_MESSAGE],
            'only a byte order mark' => ["\xEF\xBB\xBF", QuestionImportService::EMPTY_FILE_MESSAGE],
            'header only' => ["type,question,correct\r\n", QuestionImportService::NO_ROWS_MESSAGE],
            'header and blank rows' => ["type,question\n,\n\n", QuestionImportService::NO_ROWS_MESSAGE],
            'image renamed to csv' => ["\x89PNG\r\n\x1A\n\0\0\0\rIHDR\0\0\0\x01", QuestionImportService::NOT_CSV_MESSAGE],
            'spreadsheet renamed to csv' => ["PK\x03\x04\x14\0\x06\0\x08\0\0\0!\0", QuestionImportService::NOT_CSV_MESSAGE],
            'UTF-16' => ["\xFF\xFEt\0y\0p\0e\0", QuestionImportService::NOT_CSV_MESSAGE],
            'Windows-1252 text' => ["type,question\nessay,Caf\xE9 question\n", QuestionImportService::NOT_CSV_MESSAGE],
        ];
    }

    #[DataProvider('unreadableFiles')]
    public function test_unreadable_or_empty_files_are_rejected(string $contents, string $message): void
    {
        $this->import($this->alpha, $contents)->assertSessionHasErrors(['file' => $message]);

        $this->assertNothingImported();
    }

    public function test_the_file_is_required_and_must_be_a_csv_file_of_at_most_1_mb(): void
    {
        $this->actingAs($this->alpha)
            ->post(self::URL, ['subject_id' => $this->subject1->id])
            ->assertSessionHasErrors(['file' => 'Choose the CSV file to import.']);

        $this->actingAs($this->alpha)
            ->post(self::URL, ['subject_id' => $this->subject1->id, 'file' => 'not a file'])
            ->assertSessionHasErrors('file');

        $this->import($this->alpha, $this->validFile(), filename: 'questions.xlsx')
            ->assertSessionHasErrors(['file' => 'Choose a CSV file (ending in .csv). In your spreadsheet, use Save As and choose "CSV UTF-8 (Comma delimited)".']);

        $this->actingAs($this->alpha)
            ->post(self::URL, ['subject_id' => $this->subject1->id, 'file' => UploadedFile::fake()->create('questions.csv', QuestionImportService::MAX_FILE_KILOBYTES + 1, 'text/csv')])
            ->assertSessionHasErrors(['file' => 'The file is larger than 1 MB. Split the questions into smaller files and import them one at a time.']);

        $this->assertNothingImported();
    }

    public function test_more_than_the_maximum_number_of_rows_is_rejected(): void
    {
        $rows = array_map(fn (int $number): array => ['type' => 'essay', 'question' => "Question {$number}?"], range(1, QuestionImportService::MAX_ROWS + 1));

        $this->import($this->alpha, $this->csv($rows))
            ->assertSessionHasErrors(['file' => 'The file has 501 questions. Import at most 500 at a time: split them into smaller files.']);

        $this->assertNothingImported();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function send(?User $user, string $method, string $path): TestResponse
    {
        if ($user !== null) {
            $this->actingAs($user);
        }

        return $method === 'post'
            ? $this->post($path, ['subject_id' => $this->subject1->id, 'file' => $this->csvUpload($this->validFile())])
            : $this->get($path);
    }

    private function import(User $user, string $contents, int|string|null $subjectId = null, string $filename = 'questions.csv'): TestResponse
    {
        return $this->actingAs($user)->post(self::URL, [
            'subject_id' => $subjectId ?? $this->subject1->id,
            'file' => $this->csvUpload($contents, $filename),
        ]);
    }

    private function csvUpload(string $contents, string $filename = 'questions.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($filename, $contents);
    }

    /**
     * A CSV file with every column in the header row.
     *
     * @param  list<array<string, string>>  $rows
     */
    private function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        $this->assertIsResource($stream);
        fputcsv($stream, QuestionImportService::COLUMNS, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn (string $column): string => $row[$column] ?? '', QuestionImportService::COLUMNS), ',', '"', '');
        }
        rewind($stream);
        $contents = (string) stream_get_contents($stream);
        fclose($stream);

        return $contents;
    }

    private function validFile(): string
    {
        return $this->csv([
            ['type' => 'multiple_choice', 'question' => 'Which option is correct?', 'choice_a' => 'Option A', 'choice_b' => 'Option B', 'correct' => 'B', 'explanation' => 'Staff note'],
            ['type' => 'true_false', 'question' => 'The statement is true.', 'correct' => 'True'],
            ['type' => 'essay', 'question' => 'Explain the rule.'],
        ]);
    }

    private function assertNothingImported(): void
    {
        $this->assertSame(0, Question::query()->count());
        $this->assertSame(0, QuestionChoice::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::QuestionCreated->value)->count());
        $this->assertSame(0, QuestionTopic::query()->where('name', 'New Topic')->count());
    }
}
