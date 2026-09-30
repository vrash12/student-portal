<?php

namespace Tests\Feature\Candidates;

use App\Enums\SystemRole;
use App\Models\Assessment;
use App\Models\AssessmentCategory;
use App\Models\AssessmentScore;
use App\Models\ClassSubject;
use App\Models\User;
use App\Services\CandidatePdfService;
use Tests\Feature\Grading\BuildsGradingFixtures;
use Tests\TestCase;

/**
 * Candidate PDFs follow the landscape registration-form layout: the
 * Certificate of Registration is one page, and the Academic Record prints
 * one compact page per academic period (semester), oldest first.
 */
class CandidatePdfLayoutTest extends TestCase
{
    use BuildsGradingFixtures;

    private CandidatePdfService $pdfs;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGradingFixtures();
        $this->pdfs = app(CandidatePdfService::class);
        $this->admin = $this->userWithRole(SystemRole::AcademicAdministrator);
    }

    /**
     * A finalized assessment with a score for Candidate A in the past period's
     * class (a result recorded before the candidate moved to Batch A).
     */
    private function pastResult(string $title, string $score = '8'): void
    {
        $offering = ClassSubject::query()->where('class_batch_id', $this->batchOld->id)->firstOrFail();
        $category = AssessmentCategory::query()->where('class_subject_id', $offering->id)->first()
            ?? AssessmentCategory::query()->forceCreate(['class_subject_id' => $offering->id, 'name' => 'Quizzes', 'weight' => 100, 'position' => 1]);
        $assessment = new Assessment(['title' => $title, 'max_score' => '10', 'assessed_on' => '2026-03-02']);
        $assessment->class_subject_id = $offering->id;
        $assessment->assessment_category_id = $category->id;
        $assessment->status = 'finalized';
        $assessment->finalized_at = now();
        $assessment->finalized_by = $this->alpha->id;
        $assessment->created_by = $this->alpha->id;
        $assessment->save();

        $row = new AssessmentScore;
        $row->assessment_id = $assessment->id;
        $row->candidate_id = $this->candidateInA->id;
        $row->score = $score;
        $row->recorded_by = $this->alpha->id;
        $row->save();
    }

    private function pageCount(string $pdf): int
    {
        return preg_match_all('~/Type\s*/Page[^s]~', $pdf);
    }

    public function test_the_registration_certificate_is_one_landscape_page(): void
    {
        $data = $this->pdfs->data($this->admin, $this->candidateInA, 'registration');
        $this->assertSame('Certificate of Registration', $data['title']);
        $this->assertSame($this->candidateInA->classBatch->academicPeriod->name, $data['period']['name']);

        $pdf = $this->pdfs->render($data);
        $this->assertSame(1, $this->pageCount($pdf));
        $this->assertStringContainsString('/MediaBox [0.000 0.000 792.000 612.000]', $pdf);

        $html = view('pdf.candidate-record', [...$data, 'logo' => null])->render();
        foreach (['Student General Information', 'Enrolled Subjects', "Instructor's Signature", "Candidate's Signature", 'Registration No.', 'Subject 1'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    public function test_the_academic_record_prints_one_page_per_semester_oldest_first(): void
    {
        $this->pastResult('Past quiz 1');
        $this->pastResult('Past quiz 2', '6');
        $current = $this->createAssessment($this->quizzes, 'Current quiz');
        $this->recordScores($current, [$this->candidateInA->id => '40']);
        $this->finalize($current);

        $data = $this->pdfs->data($this->admin, $this->candidateInA, 'academic');

        $this->assertSame(['Period Past', $this->candidateInA->classBatch->academicPeriod->name], array_column(array_column($data['periods'], 'period'), 'name'));
        $this->assertSame([false, true], array_column($data['periods'], 'isCurrent'));
        $this->assertSame(['Past quiz 1', 'Past quiz 2'], array_column($data['periods'][0]['assessments'], 'title'));
        $this->assertSame(['Current quiz'], array_column($data['periods'][1]['assessments'], 'title'));
        $this->assertTrue($data['periods'][0]['twoColumns']);

        $pdf = $this->pdfs->render($data);
        $this->assertSame(2, $this->pageCount($pdf));
        $this->assertStringContainsString('/MediaBox [0.000 0.000 792.000 612.000]', $pdf);

        $html = view('pdf.candidate-record', [...$data, 'logo' => null])->render();
        // The second semester starts on a new page.
        $this->assertSame(1, substr_count($html, 'class="page-break"'));
        $this->assertStringContainsString('Subject grades and standing are calculated for the current class only', $html);
    }

    public function test_a_candidate_without_a_class_still_gets_a_one_page_record(): void
    {
        $this->candidateInA->forceFill(['class_batch_id' => null])->save();

        $data = $this->pdfs->data($this->admin, $this->candidateInA->fresh(), 'academic');
        $this->assertNull($data['period']);

        $this->assertSame(1, $this->pageCount($this->pdfs->render($data)));
    }

    public function test_a_long_semester_is_printed_stacked_instead_of_side_by_side(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->pastResult("Past quiz {$i}");
        }

        $data = $this->pdfs->data($this->admin, $this->candidateInA, 'academic');
        $past = collect($data['periods'])->firstWhere('period.name', 'Period Past');

        $this->assertCount(30, $past['assessments']);
        $this->assertFalse($past['twoColumns']);
        $this->assertStringStartsWith('%PDF-', $this->pdfs->render($data));
    }
}
