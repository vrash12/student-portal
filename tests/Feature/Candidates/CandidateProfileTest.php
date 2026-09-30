<?php

namespace Tests\Feature\Candidates;

use App\Enums\SystemRole;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\Subject;
use App\Services\ClassBatchService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CandidateProfileTest extends TestCase
{
    public function test_portal_hides_unreleased_scores_and_confidential_exam_content(): void
    {
        $admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $batch = ClassBatch::factory()->create();
        $offering = app(ClassBatchService::class)->addSubject($batch, Subject::factory()->create());
        $candidate = Candidate::factory()->create(['class_batch_id' => $batch->id]);
        $exam = new Examination;
        $exam->class_subject_id = $offering->id;
        $exam->created_by = $admin->id;
        $exam->title = 'Synthetic profile quiz';
        $exam->status = 'published';
        $exam->duration_minutes = 10;
        $exam->release_results = false;
        $exam->save();
        $attempt = new ExaminationAttempt;
        $attempt->candidate_id = $candidate->id;
        $attempt->examination_id = $exam->id;
        $attempt->attempt_number = 1;
        $attempt->status = 'submitted';
        $attempt->started_at = now()->subMinutes(5);
        $attempt->expires_at = now()->addMinutes(5);
        $attempt->submitted_at = now();
        $attempt->delivery = [];
        $attempt->answers = ['confidential-answer'];
        $attempt->scoring_key = ['confidential-key'];
        $attempt->result_status = 'graded';
        $attempt->earned_points = 8;
        $attempt->total_points = 10;
        $attempt->percentage = 80;
        $attempt->passed = true;
        $attempt->save();

        $this->actingAs($candidate->user)->get('/portal/profile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('examinationResults.data.0.percentage', null)
                ->where('examinationResults.data.0.score', null)->where('examinationResults.data.0.passed', null)
                ->missing('examinationResults.data.0.scoring_key')->missing('examinationResults.data.0.answers'));
        $exam->release_results = true;
        $exam->save();
        $this->get('/portal/profile')->assertInertia(fn (Assert $page) => $page
            ->where('examinationResults.data.0.percentage', 80)->where('examinationResults.data.0.passed', true));
        $other = Candidate::factory()->create(['class_batch_id' => $batch->id]);
        $this->actingAs($other->user)->get('/portal/profile')->assertInertia(fn (Assert $page) => $page->has('examinationResults.data', 0));
    }

    public function test_admin_manages_identity_and_private_photo_and_candidate_can_only_read_own_profile(): void
    {
        Storage::fake('local');
        $admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $payload = [
            'candidate_number' => 'SYNTHETIC-001', 'first_name' => 'Candidate', 'middle_name' => 'Sample',
            'last_name' => 'Example', 'suffix' => 'Jr.', 'training_group' => 'Section A',
            'class_batch_id' => null, 'password' => 'tablet-password-1', 'password_confirmation' => 'tablet-password-1',
            'profile_photo' => UploadedFile::fake()->image('profile.png'),
        ];
        $this->actingAs($admin)->post('/candidates', $payload)->assertRedirect();
        $candidate = Candidate::where('candidate_number', 'SYNTHETIC-001')->sole();
        $this->assertSame('Candidate Sample Example Jr.', $candidate->user->name);
        Storage::disk('local')->assertExists($candidate->profile_photo_path);
        $this->get("/candidates/{$candidate->id}/photo")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $other = Candidate::factory()->create();

        $this->actingAs($candidate->user)->get('/portal/profile?candidate_id='.$other->id)
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('portal/profile')
            ->where('candidate.id', $candidate->id)->where('candidate.middleName', 'Sample')
            ->where('candidate.trainingGroup', 'Section A')->missing('candidate.profile_photo_path'));
        $this->get('/portal/profile/photo')->assertOk();
        $this->get("/candidates/{$other->id}/photo")->assertForbidden();
        $this->put("/candidates/{$candidate->id}", $payload)->assertForbidden();

        unset($payload['profile_photo']);
        $this->actingAs($admin)->post("/candidates/{$candidate->id}", [
            ...$payload, '_method' => 'put', 'middle_name' => '', 'suffix' => '', 'training_group' => '',
            'remove_photo' => true, 'status' => 'on_leave', 'account_active' => true,
        ])->assertRedirect();
        $candidate->refresh();
        $this->assertNull($candidate->profile_photo_path);
        $this->assertNull($candidate->middle_name);
        $this->assertSame('Candidate Example', $candidate->user->name);
        $this->get("/candidates/{$candidate->id}/photo")->assertNotFound();
    }

    public function test_photo_must_be_a_bounded_raster_image(): void
    {
        Storage::fake('local');
        $admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $candidate = Candidate::factory()->create();
        $payload = [
            'candidate_number' => $candidate->candidate_number, 'first_name' => $candidate->first_name,
            'last_name' => $candidate->last_name, 'class_batch_id' => null, 'status' => 'enrolled', 'account_active' => true,
        ];
        $this->actingAs($admin)->put("/candidates/{$candidate->id}", [
            ...$payload, 'profile_photo' => UploadedFile::fake()->create('profile.svg', 10, 'image/svg+xml'),
        ])->assertSessionHasErrors('profile_photo');
        $this->put("/candidates/{$candidate->id}", [
            ...$payload, 'profile_photo' => UploadedFile::fake()->image('profile.png')->size(2049),
        ])->assertSessionHasErrors('profile_photo');
    }
}
