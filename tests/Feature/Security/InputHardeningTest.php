<?php

namespace Tests\Feature\Security;

use App\Enums\Permission;
use App\Models\ClassSubject;
use App\Models\Examination;
use App\Models\QuestionMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\QuestionBank\BuildsQuestionBankFixtures;
use Tests\TestCase;

/**
 * Milestone 17: malformed input is rejected with validation errors, never a
 * server error, and uploads stay within safe limits.
 */
class InputHardeningTest extends TestCase
{
    use BuildsQuestionBankFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildQuestionBankFixtures();
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function arrayInputs(): array
    {
        return [
            'subject name' => ['/subjects', 'name', ['code' => 'SUBJ-9']],
            'subject code' => ['/subjects', 'code', ['name' => 'Subject 9']],
            'subject description' => ['/subjects', 'description', ['code' => 'SUBJ-9', 'name' => 'Subject 9']],
            'class name' => ['/classes', 'name', []],
            'period name' => ['/academic-periods', 'name', []],
            'candidate first name' => ['/candidates', 'first_name', ['candidate_number' => '2099-0001', 'last_name' => 'Sample']],
            'candidate number' => ['/candidates', 'candidate_number', ['first_name' => 'Sample', 'last_name' => 'Sample']],
            'user username' => ['/users', 'username', ['name' => 'Sample User']],
            'user email' => ['/users', 'email', ['name' => 'Sample User', 'username' => 'sample.user']],
        ];
    }

    /**
     * @param  array<string, mixed>  $other
     */
    #[DataProvider('arrayInputs')]
    public function test_array_values_in_text_fields_are_validation_errors(string $url, string $field, array $other): void
    {
        $this->actingAs($this->superAdmin)
            ->from($url.'/create')
            ->post($url, [...$other, $field => ['x', 'y']])
            ->assertRedirect($url.'/create')
            ->assertSessionHasErrors($field);
    }

    public function test_array_username_on_sign_in_is_a_validation_error(): void
    {
        $this->post('/login', ['username' => ['admin'], 'password' => 'anything'])
            ->assertRedirect()
            ->assertSessionHasErrors('username');
    }

    public function test_a_huge_report_page_number_is_an_empty_page(): void
    {
        $this->actingAs($this->academicAdmin)->get('/reports?page=9223372036854775807')->assertOk();
        // Beyond the integer range: a validation error, not a server error.
        $this->actingAs($this->academicAdmin)->get('/reports?page=100000000000000000000')->assertSessionHasErrors('page');
    }

    public function test_question_images_have_a_pixel_limit(): void
    {
        $question = $this->createQuestion($this->subject1, $this->multipleChoice());

        $this->actingAs($this->alpha)
            ->from("/question-bank/{$question->id}/edit")
            ->post("/question-bank/{$question->id}/media", ['file' => UploadedFile::fake()->image('wide.png', 8001, 10), 'description' => 'A wide figure.'])
            ->assertSessionHasErrors(['file' => 'This image is 8001 × 10 pixels. Images can be at most 8000 pixels wide or tall (40 megapixels); resize it and upload again.']);

        $this->actingAs($this->alpha)
            ->post("/question-bank/{$question->id}/media", ['file' => UploadedFile::fake()->image('ok.png', 8000, 10), 'description' => 'A wide figure.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, QuestionMedia::query()->count());
    }

    public function test_a_csv_of_blank_lines_or_too_many_columns_is_rejected_briefly(): void
    {
        $blank = UploadedFile::fake()->createWithContent('blank.csv', "type,question\n".str_repeat("\n", 200_000));
        $this->actingAs($this->alpha)->post('/question-bank/import', ['subject_id' => $this->subject1->id, 'file' => $blank])
            ->assertSessionHasErrors('file');

        $wide = UploadedFile::fake()->createWithContent('wide.csv', 'type,question,'.implode(',', array_map(fn (int $i): string => "extra{$i}", range(1, 5000)))."\nMC,Question?\n");
        $response = $this->actingAs($this->alpha)->post('/question-bank/import', ['subject_id' => $this->subject1->id, 'file' => $wide])
            ->assertSessionHasErrors('file');
        $message = session('errors')->first('file');
        $this->assertStringContainsString('and 4,990 more', $message);
        $this->assertLessThan(1000, strlen($message));
        $response->assertRedirect();
    }

    public function test_many_rows_stop_at_the_limit(): void
    {
        $rows = str_repeat("essay,Describe the sample.\n", 5000);
        $file = UploadedFile::fake()->createWithContent('many.csv', "type,question\n".$rows);

        $this->actingAs($this->alpha)->post('/question-bank/import', ['subject_id' => $this->subject1->id, 'file' => $file])
            ->assertSessionHasErrors(['file' => 'The file has more than 500 questions. Import at most 500 at a time: split them into smaller files.']);
    }

    public function test_the_exam_question_picker_needs_question_bank_access(): void
    {
        $exam = new Examination;
        $exam->class_subject_id = $this->alphaOffering()->id;
        $exam->created_by = $this->alpha->id;
        $exam->title = 'Picker check';
        $exam->status = 'draft';
        $exam->save();

        $this->actingAs($this->alpha)->get("/examinations/{$exam->id}/questions")->assertOk();

        $examOnly = $this->withCustomRole($this->alpha, 'exam_only', [Permission::AccessStaffArea, Permission::TeachClasses, Permission::ManageExaminations]);
        $this->actingAs($examOnly)->get("/examinations/{$exam->id}/questions")->assertForbidden();
    }

    private function alphaOffering(): ClassSubject
    {
        return ClassSubject::query()->where('class_batch_id', $this->batchA->id)->where('subject_id', $this->subject1->id)->sole();
    }
}
