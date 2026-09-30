<?php

namespace Tests\Feature\QuestionBank;

use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\Permission;
use App\Models\Question;
use App\Models\QuestionTopic;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use App\Services\QuestionBank\QuestionBankService;
use App\Services\QuestionBank\QuestionContent;
use App\Services\QuestionBank\QuestionData;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Teaching\BuildsTeachingFixtures;

/**
 * Question bank setup shared by the Milestone 7 tests, on top of the teaching
 * fixtures (see BuildsTeachingFixtures):
 *
 *   Subject 1  taught by Alpha (Batch A, and Batch Old of the past period)
 *              and by Bravo (Batch B)
 *   Subject 2  taught by Bravo (Batch A)
 *   Subject 3  taught by nobody
 *
 * Administrators: an academic administrator and a super administrator
 * (neither holds question_bank.manage).
 */
trait BuildsQuestionBankFixtures
{
    use BuildsTeachingFixtures;

    protected Subject $subject1;

    protected Subject $subject2;

    protected Subject $subject3;

    protected User $academicAdmin;

    protected User $superAdmin;

    protected function buildQuestionBankFixtures(): void
    {
        $this->buildTeachingFixtures();

        $this->subject1 = Subject::query()->where('code', 'SUBJ-1')->sole();
        $this->subject2 = Subject::query()->where('code', 'SUBJ-2')->sole();
        $this->subject3 = Subject::factory()->create(['code' => 'SUBJ-3', 'name' => 'Subject 3']);

        $this->academicAdmin = $this->userWithRole(SystemRole::AcademicAdministrator, ['name' => 'Academic Admin']);
        $this->superAdmin = $this->userWithRole(SystemRole::SuperAdministrator, ['name' => 'Super Admin']);
    }

    protected function service(): QuestionBankService
    {
        return $this->app->make(QuestionBankService::class);
    }

    /**
     * Creates a question through the service (audited like a real one).
     */
    protected function createQuestion(
        Subject $subject,
        ?QuestionContent $content = null,
        ?string $topic = null,
        string $points = '1',
        ?string $explanation = null,
        ?User $author = null,
    ): Question {
        return $this->service()->create(
            $subject,
            new QuestionData($topic, $points, $explanation, $content ?? $this->multipleChoice()),
            $author ?? $this->alpha,
        );
    }

    /**
     * "Option A", "Option B", ...; $correct is 1-based.
     */
    protected function multipleChoice(string $prompt = 'Which option is correct?', int $count = 4, int $correct = 2): QuestionContent
    {
        return QuestionContent::multipleChoice($prompt, array_map(
            fn (int $position): array => ['text' => 'Option '.chr(ord('A') + $position - 1), 'is_correct' => $position === $correct],
            range(1, $count),
        ));
    }

    protected function topic(Subject $subject, string $name): QuestionTopic
    {
        $topic = new QuestionTopic(['name' => $name]);
        $topic->subject()->associate($subject);
        $topic->save();

        return $topic;
    }

    /**
     * A multiple choice payload of the create form for Subject 1 (choice B correct).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function multipleChoicePayload(array $overrides = []): array
    {
        return [
            'subject_id' => $this->subject1->id,
            'topic' => 'Topic 1',
            'type' => 'multiple_choice',
            'prompt' => 'Which option is correct?',
            'points' => '1',
            'explanation' => 'Option B is correct because of the sample rule.',
            'choices' => [
                ['text' => 'Option A', 'is_correct' => false],
                ['text' => 'Option B', 'is_correct' => true],
                ['text' => 'Option C', 'is_correct' => false],
                ['text' => 'Option D', 'is_correct' => false],
            ],
            ...$overrides,
        ];
    }

    /**
     * @param  list<array{0: string, 1: bool}>  $choices  [text, is correct]
     * @return list<array{text: string, is_correct: bool}>
     */
    protected function choices(array $choices): array
    {
        return array_map(fn (array $choice): array => ['text' => $choice[0], 'is_correct' => $choice[1]], $choices);
    }

    /**
     * Gives the user a custom role with exactly these permissions. Teaching
     * assignments are kept (leftovers when classes.teach is not included).
     *
     * @param  list<PermissionCode>  $permissions
     */
    protected function withCustomRole(User $user, string $code, array $permissions): User
    {
        $role = Role::query()->create(['code' => $code, 'name' => ucwords(str_replace('_', ' ', $code))]);
        $role->permissions()->sync(Permission::query()->whereIn(
            'code',
            array_map(fn (PermissionCode $permission): string => $permission->value, $permissions),
        )->pluck('id'));
        $user->role()->associate($role)->save();

        return $user->fresh();
    }

    /**
     * Alpha as a former instructor: keeps the question bank permission and the
     * Subject 1 assignments, but no longer holds classes.teach.
     */
    protected function formerInstructor(): User
    {
        return $this->withCustomRole($this->alpha, 'former_instructor', [
            PermissionCode::AccessStaffArea,
            PermissionCode::ManageQuestionBank,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function propsOf(TestResponse $response): array
    {
        $props = $response->inertiaProps();
        $this->assertIsArray($props);

        return $props;
    }

    /**
     * @param  array<string, mixed>|string  $haystack  page props or response content
     * @param  list<string>  $needles
     */
    protected function assertDoesNotReveal(array|string $haystack, array $needles): void
    {
        $text = is_string($haystack) ? $haystack : (string) json_encode($haystack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($needles as $needle) {
            $this->assertStringNotContainsString($needle, $text, "The response must not reveal [{$needle}].");
        }
    }
}
