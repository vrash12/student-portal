<?php

namespace Tests\Feature\Grading;

use App\Services\Grading\GradingThresholdService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Regression tests for findings of the Milestone 5 review.
 */
class StandingReviewRegressionTest extends TestCase
{
    use BuildsGradingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGradingFixtures();
    }

    /**
     * The instructor dashboard used to say gradebooks show standing even when
     * the active period had no passing and warning grades.
     */
    public function test_instructor_dashboard_says_whether_the_active_period_has_thresholds(): void
    {
        $this->actingAs($this->alpha)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('teaching.period', [
                'id' => $this->activePeriod->id,
                'name' => 'Period Current',
                'hasThresholds' => false,
            ]));

        $this->app->make(GradingThresholdService::class)->save($this->activePeriod, '75', '80', null);

        $this->actingAs($this->alpha)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('teaching.period.hasThresholds', true));
    }

    /**
     * Invalid UTF-8 in a crafted form request used to reach the utf8mb4
     * audit column and fail with a server error. Input is now cleaned.
     */
    public function test_invalid_utf8_in_a_reason_is_replaced_instead_of_failing(): void
    {
        $service = $this->app->make(GradingThresholdService::class);
        $service->save($this->activePeriod, '75', '80', null);
        $assessment = $this->createAssessment($this->quizzes, 'Quiz 1', '10');
        $this->recordScores($assessment, [$this->candidateInA->id => '8']);
        $this->finalize($assessment);

        $this->actingAs($this->academicAdmin)
            ->put("/academic-periods/{$this->activePeriod->id}/grading-thresholds", [
                'passing_grade' => '70',
                'warning_grade' => '80',
                'reason' => "Policy \xFF\xFE update",
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('70.00', $this->activePeriod->fresh()->passing_grade);
        $this->assertDatabaseHas('audit_logs', ['action' => 'grading_thresholds.updated', 'reason' => 'Policy ?? update']);
    }

    /**
     * Thresholds of another period do not count for the active one.
     */
    public function test_thresholds_of_a_past_period_do_not_make_the_active_period_ready(): void
    {
        $this->app->make(GradingThresholdService::class)->save($this->pastPeriod, '75', '80', null);

        $this->actingAs($this->alpha)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('teaching.period.hasThresholds', false));
    }
}
