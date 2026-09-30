<?php

namespace Tests\Feature\Examinations\Builder;

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\Examination;
use App\Models\ExaminationQuestion;
use App\Models\User;
use App\Services\InstructorAssignmentService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExaminationBuilderAccessTest extends TestCase
{
    use BuildsExaminationBuilderFixtures;

    private Examination $exam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildExaminationBuilderFixtures();
        $this->exam = $this->readyDraft(['access_code' => 'SYNTH-CODE-4821']);
    }

    /**
     * Every builder request for Alpha's draft, with a valid payload.
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function builderRequests(): array
    {
        $id = $this->exam->id;
        $question = $this->mcq();

        return [
            ['get', '/examinations', []],
            ['get', '/examinations/create', []],
            ['post', '/examinations', $this->examPayload(['title' => 'Intruder draft'])],
            ['get', "/examinations/{$id}", []],
            ['get', "/examinations/{$id}/edit", []],
            ['put', "/examinations/{$id}", $this->updatePayload(['title' => 'Intruder edit'])],
            ['get', "/examinations/{$id}/questions", []],
            ['put', "/examinations/{$id}/questions", ['questions' => [['question_id' => $question->id, 'points' => '1']]]],
            ['post', "/examinations/{$id}/publish", []],
            ['post', "/examinations/{$id}/archive", ['reason' => 'Intruder archive']],
            ['put', "/examinations/{$id}/results", ['release_results' => true, 'reason' => 'Intruder release']],
        ];
    }

    private function assertDraftUntouched(): void
    {
        $fresh = $this->exam->fresh();
        $this->assertSame('draft', $fresh->status->value);
        $this->assertSame('Synthetic Quiz 01', $fresh->title);
        $this->assertFalse($fresh->release_results);
        $this->assertSame(2, ExaminationQuestion::where('examination_id', $this->exam->id)->count());
        $this->assertSame(1, Examination::count());
    }

    public function test_assigned_instructor_can_open_every_builder_page(): void
    {
        $this->actingAs($this->alpha);
        $this->get('/examinations')->assertOk()->assertInertia(fn (Assert $page) => $page->component('staff/examinations/index'));
        $this->get('/examinations/create')->assertOk()->assertInertia(fn (Assert $page) => $page->component('staff/examinations/create'));
        $this->get("/examinations/{$this->exam->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('staff/examinations/show'));
        $this->get("/examinations/{$this->exam->id}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page->component('staff/examinations/create'));
        $this->get("/examinations/{$this->exam->id}/questions")->assertOk()->assertInertia(fn (Assert $page) => $page->component('staff/examinations/questions'));
    }

    public function test_guest_is_redirected_to_login_on_every_builder_route(): void
    {
        foreach ($this->builderRequests() as [$method, $uri, $payload]) {
            $this->{$method}($uri, $payload)->assertRedirect(route('login'));
        }
        $this->assertDraftUntouched();
    }

    public function test_instructor_of_another_offering_is_forbidden_on_every_examination_route(): void
    {
        $this->actingAs($this->bravo);
        foreach ($this->builderRequests() as [$method, $uri, $payload]) {
            if (in_array($uri, ['/examinations', '/examinations/create'], true)) {
                continue;
            }
            $this->{$method}($uri, $payload)->assertForbidden();
        }
        $this->assertDraftUntouched();
    }

    public function test_instructor_cannot_create_a_draft_for_an_offering_they_do_not_teach(): void
    {
        $this->actingAs($this->bravo)->post('/examinations', $this->examPayload())->assertForbidden();
        $this->actingAs($this->alpha)->post('/examinations', $this->examPayload(['class_subject_id' => $this->bravoOffering->id]))->assertForbidden();
        $this->post('/examinations', $this->examPayload(['class_subject_id' => $this->bravoS1Offering->id]))->assertForbidden();
        $this->assertSame(1, Examination::count());
    }

    public function test_administrators_without_examination_permission_are_forbidden(): void
    {
        foreach ([$this->academicAdmin, $this->superAdmin] as $admin) {
            $this->actingAs($admin);
            foreach ($this->builderRequests() as [$method, $uri, $payload]) {
                $this->{$method}($uri, $payload)->assertForbidden();
            }
        }
        $this->assertDraftUntouched();
    }

    public function test_candidate_is_forbidden_on_every_builder_route(): void
    {
        $this->actingAs($this->candidateInA->user);
        foreach ($this->builderRequests() as [$method, $uri, $payload]) {
            $this->{$method}($uri, $payload)->assertForbidden();
        }
        $this->assertDraftUntouched();
    }

    public function test_deactivated_instructor_is_signed_out_on_every_builder_route(): void
    {
        foreach ($this->builderRequests() as [$method, $uri, $payload]) {
            $this->alpha->forceFill(['is_active' => false])->save();
            $this->actingAs($this->alpha->fresh());
            $this->{$method}($uri, $payload)->assertRedirect(route('login'));
            $this->assertGuest();
        }
        $this->assertDraftUntouched();
    }

    public function test_instructor_without_classes_teach_loses_access_despite_assignment(): void
    {
        $this->alphaWithoutTeaching();
        $this->actingAs($this->alpha);
        foreach ($this->builderRequests() as [$method, $uri, $payload]) {
            $this->{$method}($uri, $payload)->assertForbidden();
        }
        $this->assertDraftUntouched();
    }

    public function test_instructor_without_examination_permission_is_forbidden(): void
    {
        $this->alpha = $this->withCustomRole($this->alpha, 'teacher_no_exams', [
            Permission::AccessStaffArea,
            Permission::TeachClasses,
            Permission::RecordGrades,
        ]);
        $this->actingAs($this->alpha);
        foreach ($this->builderRequests() as [$method, $uri, $payload]) {
            $this->{$method}($uri, $payload)->assertForbidden();
        }
        $this->assertDraftUntouched();
    }

    public function test_list_shows_only_examinations_of_taught_offerings(): void
    {
        $bravoExam = $this->draft(['title' => 'Bravo subject 2 quiz'], $this->bravoOffering);
        $bravoS1Exam = $this->draft(['title' => 'Bravo subject 1 quiz'], $this->bravoS1Offering);

        $this->actingAs($this->alpha)->get('/examinations')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('examinations.data', 1)
            ->where('examinations.data.0.id', $this->exam->id)
            ->where('examinations.data.0.lifecycle.value', 'draft')
            ->missing('examinations.data.0.access_code'));

        $this->actingAs($this->bravo)->get('/examinations')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('examinations.data', 2)
            ->where('examinations.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$bravoExam->id, $bravoS1Exam->id])->sort()->values()->all()));
    }

    public function test_list_is_paginated(): void
    {
        foreach (range(1, 21) as $number) {
            $this->draft(['title' => "Synthetic draft {$number}"]);
        }
        $this->actingAs($this->alpha)->get('/examinations')->assertInertia(fn (Assert $page) => $page
            ->has('examinations.data', 20)->where('examinations.total', 22));
        $this->get('/examinations?page=2')->assertInertia(fn (Assert $page) => $page->has('examinations.data', 2));
    }

    public function test_create_page_offers_only_taught_offerings(): void
    {
        $this->actingAs($this->alpha)->get('/examinations/create')->assertInertia(fn (Assert $page) => $page
            ->where('offerings', fn ($offerings) => ! collect($offerings)->pluck('id')->contains($this->bravoOffering->id)
                && ! collect($offerings)->pluck('id')->contains($this->bravoS1Offering->id)
                && collect($offerings)->pluck('id')->contains($this->alphaOffering->id)));
    }

    public function test_review_page_shows_correct_answers_to_assigned_staff_but_not_the_access_code(): void
    {
        $response = $this->actingAs($this->alpha)->get("/examinations/{$this->exam->id}");
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $page) => $page
            ->has('examination.examination_questions', 2)
            ->where('examination.examination_questions.0.question.choices.1.isCorrect', true)
            ->where('examination.examination_questions.0.question.choices.0.isCorrect', false)
            ->where('examination.has_access_code', true)
            ->missing('examination.access_code'));
        $this->assertStringNotContainsString('SYNTH-CODE-4821', $response->getContent());
    }

    public function test_access_code_is_only_revealed_to_the_assigned_instructor_on_the_settings_form(): void
    {
        $this->actingAs($this->alpha)->get("/examinations/{$this->exam->id}/edit")
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $page) => $page->where('examination.access_code', 'SYNTH-CODE-4821'));

        foreach ([$this->bravo, $this->academicAdmin, $this->candidateInA->user] as $actor) {
            foreach (["/examinations/{$this->exam->id}", "/examinations/{$this->exam->id}/edit", "/examinations/{$this->exam->id}/questions", '/examinations'] as $uri) {
                $response = $this->actingAs($actor)->get($uri);
                $this->assertStringNotContainsString('SYNTH-CODE-4821', $response->getContent());
            }
        }
    }

    public function test_question_picker_offers_only_active_questions_of_the_exam_subject(): void
    {
        $inactive = $this->mcq();
        $inactive->forceFill(['is_active' => false])->save();
        $otherSubject = $this->mcq($this->subject2);

        $this->actingAs($this->alpha)->get("/examinations/{$this->exam->id}/questions")
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $page) => $page
                ->where('questions', function ($questions) use ($inactive, $otherSubject) {
                    $ids = collect($questions)->pluck('id');

                    return ! $ids->contains($inactive->id) && ! $ids->contains($otherSubject->id) && $ids->count() === 2;
                })
                ->missing('examination.access_code'));
    }

    public function test_review_page_is_forbidden_for_unknown_users_even_with_the_url(): void
    {
        /** @var User $other */
        $other = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Charlie']);
        $this->actingAs($other)->get("/examinations/{$this->exam->id}")->assertForbidden();
        $this->actingAs($other)->get('/examinations')->assertOk()->assertInertia(fn (Assert $page) => $page->has('examinations.data', 0));
    }

    public function test_unassigned_instructor_loses_access_to_existing_examinations(): void
    {
        $assignment = $this->alpha->teachingAssignments()->where('class_subject_id', $this->alphaOffering->id)->sole();
        $this->app->make(InstructorAssignmentService::class)->unassign($assignment);

        $this->actingAs($this->alpha->fresh());
        foreach ($this->builderRequests() as [$method, $uri, $payload]) {
            if ($uri === '/examinations') {
                continue;
            }
            $response = $this->{$method}($uri, $payload);
            if ($uri === '/examinations/create') {
                $response->assertOk();

                continue;
            }
            $response->assertForbidden();
        }
        $this->get('/examinations')->assertOk()->assertInertia(fn (Assert $page) => $page->has('examinations.data', 0));
        $this->assertDraftUntouched();
    }

    public function test_co_instructor_of_the_same_offering_may_manage_the_draft(): void
    {
        $this->teach($this->bravo, $this->alphaOffering);
        $this->actingAs($this->bravo)->get("/examinations/{$this->exam->id}")->assertOk();
        $this->put("/examinations/{$this->exam->id}", $this->updatePayload(['title' => 'Co-instructor edit']))->assertSessionHasNoErrors();
        $this->assertSame('Co-instructor edit', $this->exam->fresh()->title);
    }
}
