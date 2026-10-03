<?php

namespace Tests\Feature\Candidates;

use App\Enums\SystemRole;
use App\Models\AccountCategory;
use App\Models\AccountEntry;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Services\Accounts\AccountService;
use App\Services\CandidateBackgroundService;
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

    public function test_the_registration_form_carries_the_background_record(): void
    {
        $this->buildGradingFixtures();
        app(CandidateBackgroundService::class)->save($this->candidateInA, [
            'date_of_birth' => '2001-03-09', 'sex' => 'female', 'civil_status' => 'single', 'mobile_number' => '0917 555 0111',
            'emergency_contact_name' => 'Ana Example', 'emergency_contact_relationship' => 'Mother', 'eligibility' => 'Civil Service Professional',
        ], [['level' => 'bachelor', 'degree' => 'BS Criminology', 'school' => 'State University of the North', 'year_graduated' => 2022, 'honors' => 'Cum Laude']]);
        $service = app(CandidatePdfService::class);

        $html = view('pdf.candidate-record', $service->data($this->candidateInA->user, $this->candidateInA, 'registration'))->render();
        foreach (['Personal Background', '09 Mar 2001', 'Female / Single', '0917 555 0111', 'Ana Example', 'Civil Service Professional', 'Educational Background', 'BS Criminology', 'Cum Laude'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringStartsWith('%PDF-', $service->render($service->data($this->academicAdmin, $this->candidateInA, 'registration')));

        // The academic record does not carry it.
        $this->assertNull($service->data($this->academicAdmin, $this->candidateInA, 'academic')['background']);
    }

    public function test_the_registration_form_lists_the_expenses_the_institution_provides(): void
    {
        $this->buildGradingFixtures();
        $meals = $this->charge($this->candidateInA, 'Meals', '4500.00', '2026-09-01', 'Meals, first month');
        $this->charge($this->candidateInA, 'Uniforms', '3500.25', '2026-08-10', 'Uniform set');
        $mistake = $this->charge($this->candidateInA, 'Billing', '999.99', '2026-08-03', 'Mistaken charge');
        app(AccountService::class)->void($mistake, 'Entered twice.', $this->academicAdmin);
        $this->charge($this->candidateInB, 'Billing', '15000.00', '2026-08-03', 'Training fees');
        $service = app(CandidatePdfService::class);

        $data = $service->data($this->candidateInA->user, $this->candidateInA, 'registration');

        // Only the candidate's own charges that stand, oldest first, with an exact total.
        $this->assertSame('PHP', $data['expenses']['currency']);
        $this->assertSame(
            [['2026-08-10', 'Uniform set', 'Uniforms', '3500.25'], ['2026-09-01', 'Meals, first month', 'Meals', '4500.00']],
            array_map(fn (array $row): array => [$row['postedOn'], $row['name'], $row['category'], $row['amount']], $data['expenses']['rows']),
        );
        $this->assertSame('8000.25', $data['expenses']['total']);
        $this->assertTrue($data['expenses']['itemizedInBox']);

        $html = view('pdf.candidate-record', $data)->render();
        foreach (['Expenses Provided by the Institution', 'Uniform set', 'Meals, first month', '3,500.25', 'PHP 8,000.25', 'a scholar owes nothing'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('Mistaken charge', $html);
        $this->assertStringNotContainsString('Training fees', $html);
        // Still a one-page form.
        $this->assertSame(1, $this->pageCount($service->render($data)));
        $this->assertSame((string) $meals->amount, $data['expenses']['rows'][1]['amount']);

        // The academic record does not carry expenses.
        $this->assertArrayNotHasKey('expenses', $service->data($this->academicAdmin, $this->candidateInA, 'academic'));
    }

    public function test_many_expenses_are_summed_by_category_and_listed_on_their_own_page(): void
    {
        $this->buildGradingFixtures();
        foreach (['Billing' => '15000.00', 'Uniforms' => '3500.00', 'Meals' => '4500.00', 'Military Fitness' => '1500.00', 'Chargeable Items' => '450.00'] as $category => $amount) {
            $this->charge($this->candidateInA, $category, $amount, '2026-08-03', "{$category} expense");
        }
        $this->charge($this->candidateInA, 'Meals', '4500.00', '2026-09-01', 'Meals, second month');
        $service = app(CandidatePdfService::class);

        $data = $service->data($this->academicAdmin, $this->candidateInA, 'registration');

        $this->assertFalse($data['expenses']['itemizedInBox']);
        $this->assertCount(6, $data['expenses']['rows']);
        $this->assertSame('29450.00', $data['expenses']['total']);
        // The three largest categories, then the rest as "Other", so the form keeps one page.
        $this->assertSame(
            [['Billing', 1, '15000.00'], ['Meals', 2, '9000.00'], ['Uniforms', 1, '3500.00'], ['Other', 2, '1950.00']],
            array_map(fn (array $group): array => [$group['category'], $group['items'], $group['amount']], $data['expenses']['byCategory']),
        );

        $html = view('pdf.candidate-record', $data)->render();
        $this->assertStringContainsString('every expense is listed on the next page', $html);
        foreach (['Military Fitness expense', 'Chargeable Items expense', 'Meals, second month'] as $line) {
            $this->assertStringContainsString($line, $html);
        }
        $this->assertSame(2, $this->pageCount($service->render($data)));
    }

    private function charge(Candidate $candidate, string $category, string $amount, string $postedOn, string $description): AccountEntry
    {
        return app(AccountService::class)->record($candidate, [
            'account_category_id' => AccountCategory::query()->where('name', $category)->sole()->id,
            'entry_type' => 'charge',
            'amount' => $amount,
            'posted_on' => $postedOn,
            'description' => $description,
            'reference' => null,
        ], $this->academicAdmin);
    }

    private function pageCount(string $pdf): int
    {
        return preg_match_all('/\/Type\s*\/Page[^s]/', $pdf);
    }
}
