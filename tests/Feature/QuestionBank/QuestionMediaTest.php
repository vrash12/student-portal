<?php

namespace Tests\Feature\QuestionBank;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Question;
use App\Models\QuestionMedia;
use App\Services\QuestionBank\QuestionMediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Images, audio, and video attached to questions: upload, description,
 * removal, locking, duplication, authorization, and private serving.
 */
class QuestionMediaTest extends TestCase
{
    use BuildsQuestionBankFixtures;

    private Question $question;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildQuestionBankFixtures();
        $this->question = $this->createQuestion($this->subject1, topic: 'Topic 1');
    }

    private function image(string $name = 'diagram.png', int $kilobytes = 0): UploadedFile
    {
        $file = UploadedFile::fake()->image($name, 640, 480);

        return $kilobytes > 0 ? $file->size($kilobytes) : $file;
    }

    private function mp3(): UploadedFile
    {
        // An ID3 header is enough for the content-based type detection.
        return UploadedFile::fake()->createWithContent('clip.mp3', "ID3\x03\x00\x00\x00\x00\x00\x0A".str_repeat("\xFF\xFB\x90\x64", 256));
    }

    private function upload(UploadedFile $file, string $description = 'A labelled diagram of the sample device.', ?Question $question = null): TestResponse
    {
        return $this->actingAs($this->alpha)
            ->from('/question-bank/'.($question ?? $this->question)->id.'/edit')
            ->post('/question-bank/'.($question ?? $this->question)->id.'/media', ['file' => $file, 'description' => $description]);
    }

    public function test_an_instructor_uploads_an_image_with_alternative_text(): void
    {
        $this->upload($this->image())->assertSessionHasNoErrors()->assertRedirect('/question-bank/'.$this->question->id.'/edit');

        $media = QuestionMedia::query()->sole();
        $this->assertSame('image', $media->kind);
        $this->assertSame('image/png', $media->mime_type);
        $this->assertSame(1, $media->position);
        $this->assertSame([640, 480], [$media->width, $media->height]);
        $this->assertSame('A labelled diagram of the sample device.', $media->description);
        $this->assertStringStartsWith('question-media/'.$this->question->id.'/', $media->path);
        Storage::disk('local')->assertExists($media->path);

        $this->actingAs($this->alpha)->get('/question-bank/'.$this->question->id.'/edit')
            ->assertInertia(fn (Assert $page) => $page
                ->where('question.media.0.id', $media->id)
                ->where('question.media.0.url', '/question-media/'.$media->id)
                ->where('question.media.0.description', 'A labelled diagram of the sample device.')
                ->missing('question.media.0.path'));
    }

    public function test_audio_is_recognized_from_its_content(): void
    {
        $this->upload($this->mp3(), 'Recording of the sample announcement.')->assertSessionHasNoErrors();

        $this->assertSame(['audio', 'audio/mpeg'], [QuestionMedia::query()->sole()->kind, QuestionMedia::query()->sole()->mime_type]);
    }

    public function test_unsupported_or_disguised_files_are_rejected(): void
    {
        $svg = UploadedFile::fake()->createWithContent('drawing.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->upload($svg)->assertSessionHasErrors('file');

        $disguised = UploadedFile::fake()->createWithContent('photo.png', 'this is plain text, not an image');
        $this->upload($disguised)->assertSessionHasErrors('file');

        $this->upload(UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))->assertSessionHasErrors('file');

        $this->assertSame(0, QuestionMedia::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_size_limits_and_required_description(): void
    {
        $this->upload($this->image(kilobytes: 6000))->assertSessionHasErrors('file');
        $this->upload($this->image(), '')->assertSessionHasErrors('description');
        $this->upload($this->image(), str_repeat('x', 501))->assertSessionHasErrors('description');

        $this->assertSame(0, QuestionMedia::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_question_holds_at_most_four_files(): void
    {
        for ($i = 1; $i <= QuestionMediaService::MAX_PER_QUESTION; $i++) {
            $this->upload($this->image("image{$i}.png"))->assertSessionHasNoErrors();
        }

        $this->upload($this->image('extra.png'))->assertSessionHasErrors('file');
        $this->assertSame([1, 2, 3, 4], QuestionMedia::query()->orderBy('position')->pluck('position')->all());
        $this->assertCount(4, Storage::disk('local')->allFiles());
    }

    public function test_the_description_can_be_changed_and_media_removed(): void
    {
        $this->upload($this->image('first.png'));
        $this->upload($this->image('second.png'));
        [$first, $second] = QuestionMedia::query()->orderBy('position')->get()->all();

        $this->actingAs($this->alpha)
            ->put("/question-bank/{$this->question->id}/media/{$second->id}", ['description' => 'Updated description.'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Updated description.', $second->fresh()->description);

        $this->actingAs($this->alpha)->delete("/question-bank/{$this->question->id}/media/{$first->id}")->assertSessionHasNoErrors();

        $this->assertModelMissing($first);
        Storage::disk('local')->assertMissing($first->path);
        Storage::disk('local')->assertExists($second->path);
        $this->assertSame(1, $second->fresh()->position);
    }

    public function test_media_of_another_question_cannot_be_changed_through_this_question(): void
    {
        $other = $this->createQuestion($this->subject1, $this->multipleChoice('Another question?'));
        $this->upload($this->image(), question: $other);
        $media = QuestionMedia::query()->sole();

        $this->actingAs($this->alpha)->delete("/question-bank/{$this->question->id}/media/{$media->id}")->assertNotFound();
        $this->assertModelExists($media);
    }

    public function test_media_of_a_locked_question_cannot_change(): void
    {
        $this->upload($this->image());
        $media = QuestionMedia::query()->sole();
        $this->question->forceFill(['locked_at' => now()])->save();

        $this->upload($this->image('new.png'))->assertSessionHasErrors('file');
        $this->actingAs($this->alpha)->put("/question-bank/{$this->question->id}/media/{$media->id}", ['description' => 'Changed'])->assertSessionHasErrors('file');
        $this->actingAs($this->alpha)->delete("/question-bank/{$this->question->id}/media/{$media->id}")->assertSessionHasErrors('file');

        $this->assertSame(1, QuestionMedia::query()->count());
        $this->assertSame('A labelled diagram of the sample device.', $media->fresh()->description);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_duplicating_a_question_copies_its_media_files(): void
    {
        $this->upload($this->image());
        $original = QuestionMedia::query()->sole();

        $this->actingAs($this->alpha)->post("/question-bank/{$this->question->id}/duplicate")->assertSessionHasNoErrors();

        $copy = QuestionMedia::query()->where('question_id', '!=', $this->question->id)->sole();
        $this->assertNotSame($original->path, $copy->path);
        $this->assertSame($original->description, $copy->description);
        $this->assertSame(Storage::disk('local')->get($original->path), Storage::disk('local')->get($copy->path));
    }

    public function test_only_staff_who_manage_the_subject_can_change_media(): void
    {
        // Subject 2 is taught by Bravo only.
        $question = $this->createQuestion($this->subject2, $this->multipleChoice('Subject 2 question?'), author: $this->bravo);
        foreach ([$this->alpha, $this->formerInstructor(), $this->academicAdmin, $this->superAdmin, $this->candidateInA->user] as $user) {
            $this->actingAs($user)->post("/question-bank/{$question->id}/media", ['file' => $this->image(), 'description' => 'x'])->assertForbidden();
        }
        $this->assertSame(0, QuestionMedia::query()->count());
    }

    public function test_media_is_served_privately_to_teaching_staff_of_the_subject_only(): void
    {
        $this->upload($this->image());
        $media = QuestionMedia::query()->sole();

        $response = $this->actingAs($this->alpha)->get('/question-media/'.$media->id)->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // Bravo teaches Subject 1 in Batch B, so Bravo may see it too.
        $this->actingAs($this->bravo)->get('/question-media/'.$media->id)->assertOk();

        $subject2Question = $this->createQuestion($this->subject2, $this->multipleChoice('Subject 2 question?'), author: $this->bravo);
        $this->actingAs($this->bravo)->post("/question-bank/{$subject2Question->id}/media", ['file' => $this->image(), 'description' => 'Subject 2 diagram.'])->assertSessionHasNoErrors();
        $subject2Media = QuestionMedia::query()->where('question_id', $subject2Question->id)->sole();

        $this->actingAs($this->alpha)->get('/question-media/'.$subject2Media->id)->assertForbidden();
        $this->actingAs($this->academicAdmin)->get('/question-media/'.$media->id)->assertForbidden();
        $this->actingAs($this->candidateInA->user)->get('/question-media/'.$media->id)->assertForbidden();
        auth()->logout();
        $this->get('/question-media/'.$media->id)->assertRedirect('/login');
    }

    public function test_the_audit_log_records_media_changes_without_file_names_or_descriptions(): void
    {
        $this->upload($this->image('secret-answer-diagram.png'), 'Shows the answer region clearly.');
        $media = QuestionMedia::query()->sole();
        $this->actingAs($this->alpha)->delete("/question-bank/{$this->question->id}/media/{$media->id}");

        $entries = AuditLog::query()->where('action', AuditAction::QuestionUpdated->value)->where('auditable_id', $this->question->id)->get();
        $this->assertCount(2, $entries);
        $this->assertSame(['media'], $entries[0]->new_values['changed']);
        $encoded = json_encode($entries->map->only(['old_values', 'new_values'])->all());
        $this->assertStringNotContainsString('secret-answer-diagram', $encoded);
        $this->assertStringNotContainsString('answer region', $encoded);
        $this->assertStringNotContainsString('question-media/', $encoded);
    }
}
