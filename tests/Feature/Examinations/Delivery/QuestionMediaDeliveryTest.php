<?php

namespace Tests\Feature\Examinations\Delivery;

use App\Models\Candidate;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\Question;
use App\Models\QuestionMedia;
use App\Services\QuestionBank\QuestionMediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Candidates see a question's images and media during their own attempt,
 * through attempt-scoped URLs; staff graders see them on the grading page.
 */
class QuestionMediaDeliveryTest extends TestCase
{
    use BuildsDeliveryFixtures;

    private Examination $exam;

    private Question $withImage;

    private QuestionMedia $media;

    private ExaminationAttempt $attempt;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildDeliveryFixtures();

        $this->withImage = $this->essay();
        $this->media = app(QuestionMediaService::class)->add($this->withImage, UploadedFile::fake()->image('figure.png', 320, 200), 'Figure 1: a sample chart.', $this->alpha);

        $this->exam = $this->makeExamination();
        $this->addItem($this->exam, $this->mcq());
        $this->addItem($this->exam, $this->withImage);
        $this->attempt = $this->startFor($this->candidateInA, $this->exam);
    }

    private function mediaUrl(?ExaminationAttempt $attempt = null, ?QuestionMedia $media = null): string
    {
        return '/portal/attempts/'.($attempt ?? $this->attempt)->id.'/media/'.($media ?? $this->media)->id;
    }

    public function test_the_delivered_question_describes_its_media_without_paths_or_file_names(): void
    {
        $props = $this->inertiaProps($this->actingAs($this->candidateInA->user)->get('/portal/attempts/'.$this->attempt->id)->assertOk());
        $item = collect($props['questions'])->first(fn (array $question): bool => $question['question']['media'] !== []);

        $this->assertSame([[
            'id' => $this->media->id,
            'kind' => 'image',
            'description' => 'Figure 1: a sample chart.',
            'mimeType' => 'image/png',
            'width' => 320,
            'height' => 200,
        ]], $item['question']['media']);
        $encoded = json_encode($props['questions']);
        $this->assertStringNotContainsString('figure.png', $encoded);
        $this->assertStringNotContainsString('question-media/', $encoded);
    }

    public function test_the_candidate_downloads_media_of_their_in_progress_attempt(): void
    {
        $response = $this->actingAs($this->candidateInA->user)->get($this->mediaUrl())->assertOk();

        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_media_outside_the_attempt_is_not_served(): void
    {
        $other = $this->essay();
        $otherMedia = app(QuestionMediaService::class)->add($other, UploadedFile::fake()->image('other.png'), 'Another figure.', $this->alpha);

        $this->actingAs($this->candidateInA->user)->get($this->mediaUrl(media: $otherMedia))->assertNotFound();
    }

    public function test_another_candidate_cannot_use_the_attempt(): void
    {
        $classmate = Candidate::factory()->create(['class_batch_id' => $this->batchA->id]);

        $this->actingAs($classmate->user)->get($this->mediaUrl())->assertForbidden();
        $this->actingAs($this->alpha)->get($this->mediaUrl())->assertForbidden();
    }

    public function test_media_is_not_served_after_the_attempt_ends(): void
    {
        $this->actingAs($this->candidateInA->user)
            ->post('/portal/attempts/'.$this->attempt->id.'/submit', ['position' => 0, 'revision' => $this->attempt->fresh()->revision, 'answer' => null]);
        $this->assertSame('submitted', $this->attempt->fresh()->status);

        $this->actingAs($this->candidateInA->user)->get($this->mediaUrl())->assertNotFound();
    }

    public function test_media_is_not_served_once_the_time_is_up(): void
    {
        $this->travel(2)->hours();

        $this->actingAs($this->candidateInA->user)->get($this->mediaUrl())->assertNotFound();
    }

    public function test_the_grading_page_shows_the_essay_media_to_the_grader(): void
    {
        $item = collect($this->attempt->delivery)->first(fn (array $delivered): bool => $delivered['question']['media'] !== []);
        $this->actingAs($this->candidateInA->user)
            ->post('/portal/attempts/'.$this->attempt->id.'/submit', ['position' => 0, 'revision' => $this->attempt->fresh()->revision, 'answer' => null]);

        $props = $this->inertiaProps($this->actingAs($this->alpha)->get('/examination-attempts/'.$this->attempt->id.'/grading')->assertOk());
        $essay = collect($props['questions'])->firstWhere('id', $item['id']);
        $this->assertSame($this->media->id, $essay['media'][0]['id']);

        $this->actingAs($this->alpha)->get('/question-media/'.$this->media->id)->assertOk();
    }
}
