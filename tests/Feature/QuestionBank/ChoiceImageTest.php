<?php

namespace Tests\Feature\QuestionBank;

use App\Models\Question;
use App\Models\QuestionMedia;
use App\Services\QuestionBank\QuestionContent;
use App\Services\QuestionBank\QuestionData;
use App\Services\QuestionBank\QuestionPresenter;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Answer choices of multiple-choice questions can show one image each.
 */
class ChoiceImageTest extends TestCase
{
    use BuildsQuestionBankFixtures;

    private Question $question;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildQuestionBankFixtures();
        $this->question = $this->createQuestion($this->subject1, $this->multipleChoice('Which picture shows the sample device?', 4, 2));
    }

    private function uploadForChoice(int $position, ?UploadedFile $file = null, ?Question $question = null): TestResponse
    {
        $question ??= $this->question;

        return $this->actingAs($this->alpha)
            ->from("/question-bank/{$question->id}/edit")
            ->post("/question-bank/{$question->id}/media", [
                'file' => $file ?? UploadedFile::fake()->image("choice{$position}.png", 200, 150),
                'description' => "Picture for choice {$position}.",
                'choice' => $position,
            ]);
    }

    public function test_an_image_is_attached_to_a_choice_and_shown_to_staff(): void
    {
        $this->uploadForChoice(2)->assertSessionHasNoErrors();

        $media = QuestionMedia::query()->sole();
        $choice = $this->question->choices()->where('position', 2)->sole();
        $this->assertSame($choice->id, $media->question_choice_id);
        $this->assertNull($media->position);
        $this->assertSame(0, $this->question->media()->count(), 'Choice images are not question-level media.');

        $staff = app(QuestionPresenter::class)->staff($this->question->fresh());
        $this->assertSame('/question-media/'.$media->id, $staff['choices'][1]['image']['url']);
        $this->assertNull($staff['choices'][0]['image']);
        $this->assertSame([], $staff['media']);
    }

    public function test_the_candidate_view_includes_choice_images_without_urls_or_answers(): void
    {
        $this->uploadForChoice(2);
        $view = app(QuestionPresenter::class)->forCandidate($this->question->fresh());

        $this->assertSame(['id', 'text', 'image'], array_keys($view['choices'][1]));
        $this->assertSame(['id', 'kind', 'description', 'mimeType', 'width', 'height'], array_keys($view['choices'][1]['image']));
        $this->assertNull($view['choices'][0]['image']);
        $this->assertStringNotContainsString('question-media/', json_encode($view));
        $this->assertStringNotContainsString('isCorrect', json_encode($view));
    }

    public function test_only_images_one_per_choice_and_existing_choices_of_multiple_choice_questions(): void
    {
        $mp3 = UploadedFile::fake()->createWithContent('clip.mp3', "ID3\x03\x00\x00\x00\x00\x00\x0A".str_repeat("\xFF\xFB\x90\x64", 256));
        $this->uploadForChoice(1, $mp3)->assertSessionHasErrors('file');

        $this->uploadForChoice(1)->assertSessionHasNoErrors();
        $this->uploadForChoice(1)->assertSessionHasErrors('target');
        $this->uploadForChoice(5)->assertSessionHasErrors('target');
        $this->uploadForChoice(7)->assertSessionHasErrors('choice');

        $trueFalse = $this->createQuestion($this->subject1, QuestionContent::trueFalse('A sample statement.', true));
        $this->uploadForChoice(1, question: $trueFalse)->assertSessionHasErrors('target');

        $this->assertSame(1, QuestionMedia::query()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_choice_images_do_not_count_toward_the_four_question_files(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->actingAs($this->alpha)->post("/question-bank/{$this->question->id}/media", ['file' => UploadedFile::fake()->image("q{$i}.png"), 'description' => 'Figure.'])->assertSessionHasNoErrors();
        }

        $this->uploadForChoice(1)->assertSessionHasNoErrors();
        $this->assertSame(5, QuestionMedia::query()->count());
    }

    public function test_removing_a_choice_or_changing_the_type_removes_its_image_and_file(): void
    {
        $this->uploadForChoice(4);
        $this->uploadForChoice(1);
        [$first, $fourth] = [
            QuestionMedia::query()->whereHas('choice', fn ($q) => $q->where('position', 1))->sole(),
            QuestionMedia::query()->whereHas('choice', fn ($q) => $q->where('position', 4))->sole(),
        ];

        // Edit down to three choices: choice D and its image go away.
        $this->service()->update($this->question, new QuestionData(null, '1', null, $this->multipleChoice('Which picture shows the sample device?', 3, 2)), $this->alpha);
        $this->assertModelMissing($fourth);
        Storage::disk('local')->assertMissing($fourth->path);
        $this->assertModelExists($first);

        // Becoming an essay removes every choice image.
        $this->service()->update($this->question->fresh(), new QuestionData(null, '1', null, QuestionContent::essay('Describe the sample device.')), $this->alpha);
        $this->assertSame(0, QuestionMedia::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_choice_image_is_removed_through_its_own_question_only(): void
    {
        $this->uploadForChoice(2);
        $media = QuestionMedia::query()->sole();
        $other = $this->createQuestion($this->subject1, $this->multipleChoice('Another question?'));

        $this->actingAs($this->alpha)->delete("/question-bank/{$other->id}/media/{$media->id}")->assertNotFound();
        $this->assertModelExists($media);

        $this->actingAs($this->alpha)->from("/question-bank/{$this->question->id}/edit")
            ->delete("/question-bank/{$this->question->id}/media/{$media->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($media);
        Storage::disk('local')->assertMissing($media->path);
    }

    public function test_locked_questions_keep_their_choice_images(): void
    {
        $this->uploadForChoice(1);
        $media = QuestionMedia::query()->sole();
        $this->question->forceFill(['locked_at' => now()])->save();

        $this->uploadForChoice(2)->assertSessionHasErrors('file');
        $this->actingAs($this->alpha)->delete("/question-bank/{$this->question->id}/media/{$media->id}")->assertSessionHasErrors('file');
        $this->assertModelExists($media);
    }

    public function test_duplicating_copies_choice_images_to_the_matching_choices(): void
    {
        $this->uploadForChoice(3);
        $this->actingAs($this->alpha)->post("/question-bank/{$this->question->id}/duplicate")->assertSessionHasNoErrors();

        $copy = Question::query()->whereKeyNot($this->question->id)->latest('id')->firstOrFail();
        $image = QuestionMedia::query()->where('question_id', $copy->id)->sole();
        $this->assertSame($copy->choices()->where('position', 3)->value('id'), $image->question_choice_id);
        Storage::disk('local')->assertExists($image->path);
    }

    public function test_the_database_keeps_choice_images_consistent(): void
    {
        $this->uploadForChoice(1);
        $media = QuestionMedia::query()->sole();
        $other = $this->createQuestion($this->subject1, $this->multipleChoice('Another question?'));

        foreach ([
            // Choice of another question.
            fn () => DB::table('question_media')->where('id', $media->id)->update(['question_choice_id' => $other->choices()->value('id')]),
            // Choice images have no position; question media need one.
            fn () => DB::table('question_media')->where('id', $media->id)->update(['position' => 1]),
            // Only images on choices.
            fn () => DB::table('question_media')->where('id', $media->id)->update(['kind' => 'audio']),
        ] as $index => $violation) {
            try {
                $violation();
                $this->fail("constraint {$index} not enforced");
            } catch (QueryException) {
            }
        }
        $this->assertTrue(true);
    }
}
