<?php

namespace Tests\Feature\QuestionBank;

use App\Enums\Permission as PermissionCode;
use App\Models\AuditLog;
use App\Models\ClassSubject;
use App\Models\InstructorAssignment;
use App\Models\Question;
use App\Models\User;
use App\Services\InstructorAssignmentService;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Question bank authorization on every route (Milestone 7): the
 * question_bank.manage permission and, for a question, teaching its subject
 * (User::teachesSubject). Knowing a question id never grants access, and
 * administrators and candidates have no access by default.
 */
class QuestionBankAccessTest extends TestCase
{
    use BuildsQuestionBankFixtures;

    /** Subject 1: taught by Alpha and Bravo. */
    private Question $question;

    /** Subject 2: taught by Bravo only. */
    private Question $subject2Question;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildQuestionBankFixtures();

        $this->question = $this->createQuestion($this->subject1, topic: 'Topic 1', explanation: 'Staff-only explanation text.');
        $this->subject2Question = $this->createQuestion($this->subject2, $this->multipleChoice('Question of Subject 2'), author: $this->bravo);
    }

    /**
     * @return array<string, array{string, string}> [method, path]; {id} is replaced by the question id
     */
    public static function questionRoutes(): array
    {
        return [
            'preview' => ['get', '/question-bank/{id}'],
            'edit page' => ['get', '/question-bank/{id}/edit'],
            'update' => ['put', '/question-bank/{id}'],
            'activate' => ['post', '/question-bank/{id}/activate'],
            'deactivate' => ['post', '/question-bank/{id}/deactivate'],
            'duplicate' => ['post', '/question-bank/{id}/duplicate'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function allRoutes(): array
    {
        return [
            'list' => ['get', '/question-bank'],
            'create page' => ['get', '/question-bank/create'],
            'store' => ['post', '/question-bank'],
            ...self::questionRoutes(),
        ];
    }

    #[DataProvider('allRoutes')]
    public function test_guests_are_redirected_to_sign_in(string $method, string $path): void
    {
        $before = $this->state();

        $this->send(null, $method, $path, $this->question)->assertRedirect('/login');

        $this->assertSame($before, $this->state());
    }

    #[DataProvider('allRoutes')]
    public function test_candidates_are_forbidden(string $method, string $path): void
    {
        $before = $this->state();

        $this->send($this->candidateInA->user, $method, $path, $this->question)
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('errors/error')->where('status', 403));

        $this->assertSame($before, $this->state());
    }

    #[DataProvider('allRoutes')]
    public function test_administrators_are_forbidden(string $method, string $path): void
    {
        $before = $this->state();

        foreach ([$this->academicAdmin, $this->superAdmin] as $administrator) {
            $this->send($administrator, $method, $path, $this->question)->assertForbidden();
        }

        $this->assertSame($before, $this->state());
    }

    #[DataProvider('allRoutes')]
    public function test_teaching_staff_without_the_question_bank_permission_are_forbidden(string $method, string $path): void
    {
        // Assigned to Subject 1 and allowed to teach, but without question_bank.manage.
        $teacher = $this->withCustomRole($this->alpha, 'teacher_without_bank', [
            PermissionCode::AccessStaffArea,
            PermissionCode::TeachClasses,
            PermissionCode::RecordGrades,
        ]);
        $before = $this->state();

        $this->send($teacher, $method, $path, $this->question)->assertForbidden();

        $this->assertSame($before, $this->state());
    }

    #[DataProvider('allRoutes')]
    public function test_deactivated_instructors_are_signed_out(string $method, string $path): void
    {
        $this->alpha->forceFill(['is_active' => false])->save();
        $before = $this->state();

        $this->send($this->alpha, $method, $path, $this->question)->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame($before, $this->state());
    }

    #[DataProvider('questionRoutes')]
    public function test_instructors_of_another_subject_cannot_open_or_change_the_question(string $method, string $path): void
    {
        // Alpha teaches Subject 1 only; the question belongs to Subject 2.
        $before = $this->state();

        $this->send($this->alpha, $method, $path, $this->subject2Question)
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('errors/error')->where('status', 403));

        $this->assertSame($before, $this->state());
    }

    #[DataProvider('questionRoutes')]
    public function test_questions_of_a_subject_nobody_teaches_are_closed_to_every_instructor(string $method, string $path): void
    {
        $orphan = Question::factory()->forSubject($this->subject3)->multipleChoice()->create();
        $before = $this->state();

        foreach ([$this->alpha, $this->bravo] as $instructor) {
            $this->send($instructor, $method, $path, $orphan)->assertForbidden();
        }

        $this->assertSame($before, $this->state());
    }

    #[DataProvider('questionRoutes')]
    public function test_former_instructor_with_leftover_assignments_cannot_open_the_question(string $method, string $path): void
    {
        $former = $this->formerInstructor();
        $this->assertTrue(InstructorAssignment::query()->where('instructor_id', $former->id)->exists());
        $before = $this->state();

        $this->send($former, $method, $path, $this->question)->assertForbidden();

        $this->assertSame($before, $this->state());
    }

    public function test_former_instructor_sees_an_empty_question_bank_and_cannot_add_questions(): void
    {
        $former = $this->formerInstructor();

        $this->actingAs($former)
            ->get('/question-bank?subject='.$this->subject1->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/question-bank/index')
                ->where('subjects', [])
                ->where('topics', [])
                ->where('filters.subject', '')
                ->where('questions.total', 0)
                ->where('questions.data', []));

        $this->actingAs($former)
            ->get('/question-bank/create?subject='.$this->subject1->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/question-bank/create')
                ->where('subjects', [])
                ->where('selectedSubjectId', null));

        $count = Question::query()->count();
        $this->actingAs($former)
            ->post('/question-bank', $this->multipleChoicePayload())
            ->assertSessionHasErrors(['subject_id' => 'Select one of the subjects you teach.']);
        $this->assertSame($count, Question::query()->count());
    }

    public function test_assigned_instructor_can_use_every_route(): void
    {
        $id = $this->question->id;

        $this->actingAs($this->alpha)->get('/question-bank')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/question-bank/index'));
        $this->actingAs($this->alpha)->get('/question-bank/create')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/question-bank/create'));
        $this->actingAs($this->alpha)->get("/question-bank/{$id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/question-bank/show')->where('question.id', $id));
        $this->actingAs($this->alpha)->get("/question-bank/{$id}/edit")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/question-bank/edit')->where('question.id', $id));

        $this->actingAs($this->alpha)->post('/question-bank', $this->multipleChoicePayload(['prompt' => 'A new question?']))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/question-bank/'.Question::query()->where('prompt', 'A new question?')->sole()->id);

        $this->actingAs($this->alpha)->put("/question-bank/{$id}", $this->multipleChoicePayload(['points' => '3']))
            ->assertSessionHasNoErrors()
            ->assertRedirect("/question-bank/{$id}");
        $this->assertSame('3.00', $this->question->fresh()->points);

        $this->actingAs($this->alpha)->post("/question-bank/{$id}/deactivate")->assertRedirect("/question-bank/{$id}");
        $this->assertFalse($this->question->fresh()->is_active);
        $this->actingAs($this->alpha)->post("/question-bank/{$id}/activate")->assertRedirect("/question-bank/{$id}");
        $this->assertTrue($this->question->fresh()->is_active);

        $response = $this->actingAs($this->alpha)->post("/question-bank/{$id}/duplicate");
        $copy = Question::query()->latest('id')->firstOrFail();
        $response->assertRedirect("/question-bank/{$copy->id}/edit");
    }

    public function test_every_instructor_of_the_subject_shares_its_questions(): void
    {
        // Bravo teaches Subject 1 in Batch B; the question was written by Alpha.
        $id = $this->question->id;

        $this->actingAs($this->bravo)->get("/question-bank/{$id}")->assertOk();
        $this->actingAs($this->bravo)->get("/question-bank/{$id}/edit")->assertOk();
        $this->actingAs($this->bravo)
            ->put("/question-bank/{$id}", $this->multipleChoicePayload(['points' => '2']))
            ->assertSessionHasNoErrors();

        $question = $this->question->fresh();
        $this->assertSame('2.00', $question->points);
        $this->assertSame($this->alpha->id, $question->created_by);
        $this->assertSame($this->bravo->id, $question->updated_by);
    }

    public function test_teaching_the_subject_in_any_academic_period_grants_access_until_the_last_assignment_is_removed(): void
    {
        $id = $this->question->id;
        $assignments = $this->app->make(InstructorAssignmentService::class);

        // Remove the current-period assignment: Batch Old (past period) remains.
        $assignments->unassign($this->assignmentOf($this->alpha, $this->offering($this->batchA, $this->subject1)));
        $this->actingAs($this->alpha)->get("/question-bank/{$id}")->assertOk();

        $assignments->unassign($this->assignmentOf($this->alpha, $this->offering($this->batchOld, $this->subject1)));
        $this->actingAs($this->alpha)->get("/question-bank/{$id}")->assertForbidden();
        $this->actingAs($this->alpha)->get("/question-bank/{$id}/edit")->assertForbidden();
        $this->actingAs($this->alpha)
            ->get('/question-bank')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('subjects', [])->where('questions.total', 0));
    }

    public function test_unknown_and_malformed_question_ids_are_not_found(): void
    {
        $this->actingAs($this->alpha)->get('/question-bank/999999')->assertNotFound();
        $this->actingAs($this->alpha)->get('/question-bank/999999/edit')->assertNotFound();
        $this->actingAs($this->alpha)->put('/question-bank/999999', $this->multipleChoicePayload())->assertNotFound();
        $this->actingAs($this->alpha)->post('/question-bank/999999/deactivate')->assertNotFound();
        $this->actingAs($this->alpha)->get('/question-bank/abc')->assertNotFound();
        $this->actingAs($this->alpha)->get('/question-bank/1abc/edit')->assertNotFound();
    }

    /**
     * Sends the request an authorized instructor would send on this route.
     */
    private function send(?User $user, string $method, string $path, Question $question): TestResponse
    {
        if ($user !== null) {
            $this->actingAs($user);
        }

        $url = str_replace('{id}', (string) $question->id, $path);

        return match ($method) {
            'get' => $this->get($url),
            'put' => $this->put($url, $this->multipleChoicePayload(['subject_id' => $question->subject_id, 'points' => '5'])),
            'post' => $this->post($url, $url === '/question-bank' ? $this->multipleChoicePayload(['prompt' => 'Unauthorized question?']) : []),
        };
    }

    /**
     * What any of the routes could change.
     *
     * @return array<string, mixed>
     */
    private function state(): array
    {
        return [
            'questions' => Question::query()->orderBy('id')->get(['id', 'subject_id', 'prompt', 'points', 'is_active', 'updated_by'])->toArray(),
            'choices' => $this->question->choices()->count() + $this->subject2Question->choices()->count(),
            'audits' => AuditLog::query()->where('action', 'like', 'question.%')->count(),
        ];
    }

    private function assignmentOf(User $instructor, ClassSubject $offering): InstructorAssignment
    {
        return InstructorAssignment::query()
            ->where('instructor_id', $instructor->id)
            ->where('class_subject_id', $offering->id)
            ->sole();
    }
}
