<?php

namespace Tests\Feature\Examinations\Delivery;

use Tests\TestCase;

/**
 * Milestone 18: what the candidate start and result pages are told, so they
 * can explain the rules and what happened (UI_UX_DESIGN.md §28–29, §88).
 */
class CandidateExamPagesTest extends TestCase
{
    use BuildsDeliveryFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDeliveryFixtures();
    }

    public function test_the_start_page_states_the_time_out_rule_and_the_schedule(): void
    {
        $exam = $this->makeExamination(['auto_submit' => false]);
        $this->addItem($exam, $this->mcq());

        $props = $this->inertiaProps($this->actingAs($this->candidateInA->user)->get('/portal/examinations/'.$exam->id)->assertOk())['examination'];

        $this->assertFalse($props['autoSubmit']);
        $this->assertSame($exam->opens_at->toIso8601String(), $props['opensAt']);
        $this->assertSame($exam->closes_at->toIso8601String(), $props['closesAt']);
        $this->assertSame([], $props['attempts']);
    }

    public function test_the_result_page_states_how_and_when_the_attempt_was_submitted(): void
    {
        $exam = $this->makeExamination(['auto_submit' => true]);
        $this->addItem($exam, $this->mcq());
        $attempt = $this->startFor($this->candidateInA, $exam);
        $this->actingAs($this->candidateInA->user);

        $this->post('/portal/attempts/'.$attempt->id.'/submit', ['revision' => 0, 'position' => 0, 'answer' => null])
            ->assertRedirect('/portal/attempts/'.$attempt->id.'/success');
        $props = $this->inertiaProps($this->get('/portal/attempts/'.$attempt->id.'/success')->assertOk());

        $this->assertSame('submitted', $props['status']);
        $this->assertSame('manual', $props['submissionKind']);
        $this->assertSame($attempt->fresh()->submitted_at->toIso8601String(), $props['submittedAt']);
    }

    public function test_an_attempt_submitted_when_time_ran_out_is_reported_as_automatic(): void
    {
        $exam = $this->makeExamination(['auto_submit' => true]);
        $this->addItem($exam, $this->mcq());
        $attempt = $this->startFor($this->candidateInA, $exam);
        $this->travel(31)->minutes();

        $props = $this->inertiaProps($this->actingAs($this->candidateInA->user)->get('/portal/attempts/'.$attempt->id.'/success')->assertOk());

        $this->assertSame('submitted', $props['status']);
        $this->assertSame('automatic', $props['submissionKind']);
    }

    public function test_the_sign_in_page_has_no_image_unless_one_is_configured(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->where('app.loginImageUrl', null));

        config(['institution.login_image_url' => '/branding/login.jpg']);
        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->where('app.loginImageUrl', '/branding/login.jpg'));
    }
}
