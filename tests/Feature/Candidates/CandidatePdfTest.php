<?php

namespace Tests\Feature\Candidates;

use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Services\CandidatePdfService;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Grading\BuildsGradingFixtures;
use Tests\TestCase;

class CandidatePdfTest extends TestCase
{
    use BuildsGradingFixtures;

    public function test_administrator_and_owner_download_both_records_with_private_headers_and_minimal_audit(): void
    {
        $candidate = Candidate::factory()->create();
        $admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        foreach (['registration', 'academic'] as $type) {
            foreach ([[$admin, "/candidates/{$candidate->id}/documents/{$type}"], [$candidate->user, "/portal/profile/documents/{$type}"]] as [$actor, $url]) {
                $response = $this->actingAs($actor)->get($url)->assertOk()
                    ->assertHeader('Content-Type', 'application/pdf')
                    ->assertHeader('Cache-Control', 'no-store, private');
                $this->assertStringStartsWith('%PDF-', $response->getContent());
                $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
            }
        }
        $logs = AuditLog::where('action', 'candidate.record_downloaded')->get();
        $this->assertCount(4, $logs);
        foreach ($logs as $log) {
            $this->assertSame(['document_type'], array_keys($log->new_values));
            $this->assertNull($log->old_values);
        }
        $this->get('/portal/profile/documents/unknown')->assertNotFound();
    }

    public function test_full_record_exports_deny_instructors_and_other_candidates(): void
    {
        $this->buildGradingFixtures();
        foreach (['registration', 'academic'] as $type) {
            $this->actingAs($this->alpha)->get("/candidates/{$this->candidateInA->id}/documents/{$type}")->assertForbidden();
            $this->actingAs($this->candidateInB->user)->get("/candidates/{$this->candidateInA->id}/documents/{$type}")->assertForbidden();
        }
        $this->assertFalse(Gate::forUser($this->candidateInB->user)->allows('downloadRecord', $this->candidateInA));
        // A query parameter cannot choose the portal's record owner.
        $response = $this->actingAs($this->candidateInB->user)
            ->get('/portal/profile/documents/registration?candidate_id='.$this->candidateInA->id)->assertOk();
        $this->assertStringContainsString(strtolower($this->candidateInB->candidate_number), $response->headers->get('Content-Disposition'));
    }

    public function test_export_includes_more_than_one_screen_page_and_obeys_result_release_rules(): void
    {
        $this->buildGradingFixtures();
        for ($index = 1; $index <= 17; $index++) {
            $assessment = $this->createAssessment($this->quizzes, 'Synthetic assessment '.$index);
            $this->recordScores($assessment, [$this->candidateInA->id => '40']);
            $this->finalize($assessment);
        }
        $exam = new Examination;
        $exam->class_subject_id = $this->offeringA1->id;
        $exam->created_by = $this->alpha->id;
        $exam->title = 'Synthetic examination';
        $exam->duration_minutes = 10;
        $exam->status = 'published';
        $exam->release_results = false;
        $exam->save();
        $attempt = new ExaminationAttempt;
        $attempt->candidate_id = $this->candidateInA->id;
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
        $service = app(CandidatePdfService::class);
        request()->merge(['assessments_page' => 2, 'exams_page' => 2]);
        $data = $service->data($this->academicAdmin, $this->candidateInA, 'academic');
        $this->assertCount(17, $data['assessments']);
        $this->assertCount(1, $data['examinations']);
        $this->assertNull($data['examinations'][0]['score']);
        $this->assertSame('Not released', $data['examinations'][0]['resultLabel']);
        $this->assertStringNotContainsString('confidential-', json_encode($data));
        $this->assertStringStartsWith('%PDF-', $service->render($data));
        $exam->release_results = true;
        $exam->save();
        $released = $service->data($this->candidateInA->user, $this->candidateInA, 'academic');
        $this->assertEquals(8, $released['examinations'][0]['score']);
        $this->assertEquals(80, $released['examinations'][0]['percentage']);
    }
}
