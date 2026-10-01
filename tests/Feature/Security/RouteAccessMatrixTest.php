<?php

namespace Tests\Feature\Security;

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\AccountCategory;
use App\Models\Assessment;
use App\Models\ConductType;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\ExaminationQuestion;
use App\Models\InstructorAssignment;
use App\Models\Question;
use App\Models\QuestionMedia;
use App\Models\Subject;
use App\Services\Accounts\AccountService;
use App\Services\Attendance\AttendanceService;
use App\Services\Conduct\ConductService;
use App\Services\Examinations\CandidateAttemptService;
use App\Services\Fitness\FitnessStandardService;
use App\Services\Fitness\FitnessTestService;
use App\Services\Performance\PerformanceAreaService;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Grading\BuildsGradingFixtures;
use Tests\TestCase;

/**
 * Milestone 17: every registered route, checked for every kind of user.
 *
 * Routes are read from the router, so a route added later is covered
 * automatically. Route parameters point to records that belong to
 * Instructor Bravo (Batch B / Subject 1, Subject 2) and to candidate B1, so
 * Instructor Alpha and candidate A1 must be refused everywhere.
 */
class RouteAccessMatrixTest extends TestCase
{
    use BuildsGradingFixtures;

    /** Public or guest-only routes. */
    private const UNPROTECTED = ['login', 'up', '{fallbackPlaceholder}'];

    /**
     * Records with an id that are not owned by a class: fitness events apply
     * to every class, and instructors set them (owner request 2026-10-02).
     * Fitness tests of another class are still refused.
     */
    private const INSTITUTION_WIDE_FOR_INSTRUCTORS = ['fitness/standards/{fitnessEvent}/edit', 'fitness/standards/{fitnessEvent}'];

    /** @var array<string, string> route parameter => value */
    private array $parameters = [];

    private ExaminationAttempt $attemptOfB1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGradingFixtures();
        $this->travelTo(now()->setTime(10, 0));

        $subject1 = Subject::query()->where('code', 'SUBJ-1')->sole();
        $subject2 = Subject::query()->where('code', 'SUBJ-2')->sole();

        // Bravo's grading setup and an assessment in Batch B / Subject 1.
        $this->setScheme($this->offeringB1, ['Quizzes' => '100']);
        $assessment = $this->createAssessment($this->offeringB1->assessmentCategories()->sole(), 'Quiz B', '50', actor: $this->bravo);

        // Bravo's published examination in Batch B, and B1's attempt.
        $exam = new Examination;
        $exam->class_subject_id = $this->offeringB1->id;
        $exam->created_by = $this->bravo->id;
        $exam->title = 'Batch B quiz';
        $exam->status = 'published';
        $exam->duration_minutes = 30;
        $exam->attempt_limit = 1;
        $exam->opens_at = now()->subMinute();
        $exam->closes_at = now()->addHour();
        $exam->save();
        $item = new ExaminationQuestion;
        $item->examination_id = $exam->id;
        $item->question_id = Question::factory()->multipleChoice()->create(['subject_id' => $subject1->id])->id;
        $item->position = 1;
        $item->points = '1.00';
        $item->save();
        $this->attemptOfB1 = app(CandidateAttemptService::class)->start($this->candidateInB->user, $exam->fresh(), null);

        // A Subject 2 question (taught only by Bravo) with a media row.
        $question = Question::factory()->multipleChoice()->create(['subject_id' => $subject2->id]);
        $media = new QuestionMedia;
        $media->question_id = $question->id;
        $media->position = 1;
        $media->kind = QuestionMedia::KIND_IMAGE;
        $media->path = 'question-media/'.$question->id.'/sample.png';
        $media->original_name = 'sample.png';
        $media->mime_type = 'image/png';
        $media->size_bytes = 100;
        $media->description = 'Sample figure.';
        $media->created_by = $this->bravo->id;
        $media->save();

        // A fitness event (institution-wide) and a fitness test of Batch B (Bravo's class).
        $fitnessEvent = app(FitnessStandardService::class)->create([
            'name' => 'Push-ups', 'description' => null, 'unit' => 'repetitions', 'higher_is_better' => true,
            'passing_value' => 40.0, 'maximum_value' => 60.0, 'sort_order' => 1,
        ]);
        $fitnessTest = app(FitnessTestService::class)->create($this->batchB, ['title' => 'Fitness Test B', 'tested_on' => '2026-09-01', 'notes' => null], [$fitnessEvent->id], $this->userWithRole(SystemRole::SuperAdministrator));

        // A statement of account entry of candidate B1 (staff with account permissions only).
        $accountEntry = app(AccountService::class)->record($this->candidateInB, [
            'account_category_id' => (int) AccountCategory::query()->orderBy('id')->value('id'), 'entry_type' => 'charge',
            'amount' => '100.00', 'posted_on' => '2026-09-01', 'description' => 'Uniform set', 'reference' => null,
        ], $this->userWithRole(SystemRole::SuperAdministrator));
        $accountExpense = app(AccountService::class)->createExpense([
            'name' => 'Uniform set', 'account_category_id' => (int) $accountEntry->account_category_id,
            'amount' => '100.00', 'due_on' => null, 'description' => null,
        ], $this->userWithRole(SystemRole::SuperAdministrator));

        // A merit of candidate B1 and an attendance session of Batch B (in scope for
        // Bravo and administrators only), a merit/demerit type and a performance
        // area (administrators only).
        $administrator = $this->userWithRole(SystemRole::SuperAdministrator);
        $conductType = ConductType::query()->where('kind', 'merit')->orderBy('id')->firstOrFail();
        $conductEntry = app(ConductService::class)->record($this->candidateInB, [
            'conduct_type_id' => $conductType->id, 'points' => 2, 'occurred_on' => '2026-09-01', 'reason' => 'Assisted a classmate.',
        ], $administrator);
        $attendanceSession = app(AttendanceService::class)->create($this->batchB, ['held_on' => '2026-09-01', 'title' => 'Session B', 'hours' => '1', 'notes' => null], $administrator);
        $performanceArea = app(PerformanceAreaService::class)->create([
            'name' => 'Attendance', 'description' => null, 'source' => 'attendance', 'weight' => '10', 'passing_grade' => '90', 'must_pass' => true,
            'base_rating' => null, 'merit_value' => null, 'demerit_value' => null, 'sort_order' => 1, 'is_active' => true,
        ]);

        $this->parameters = [
            'academicPeriod' => (string) $this->activePeriod->id,
            'accountCategory' => (string) $accountEntry->account_category_id,
            'accountEntry' => (string) $accountEntry->id,
            'accountExpense' => (string) $accountExpense->id,
            'assessment' => (string) $assessment->id,
            'attempt' => (string) $this->attemptOfB1->id,
            'attendanceSession' => (string) $attendanceSession->id,
            'candidate' => (string) $this->candidateInB->id,
            'classBatch' => (string) $this->batchB->id,
            'classSubject' => (string) $this->offeringB1->id,
            'conductEntry' => (string) $conductEntry->id,
            'conductType' => (string) $conductType->id,
            'examination' => (string) $exam->id,
            'fitnessEvent' => (string) $fitnessEvent->id,
            'fitnessTest' => (string) $fitnessTest->id,
            'instructor' => (string) $this->bravo->id,
            'instructorAssignment' => (string) InstructorAssignment::query()->where('instructor_id', $this->bravo->id)->value('id'),
            'medium' => (string) $media->id,
            'performanceArea' => (string) $performanceArea->id,
            'question' => (string) $question->id,
            'subject' => (string) $subject2->id,
            'type' => 'registration',
            'user' => (string) $this->bravo->id,
        ];
    }

    /**
     * @return list<Route>
     */
    private function protectedRoutes(): array
    {
        return array_values(array_filter(
            Router::getRoutes()->getRoutes(),
            fn (Route $route): bool => ! in_array($route->uri(), self::UNPROTECTED, true) && ! Str::startsWith($route->uri(), '_'),
        ));
    }

    /**
     * Permission codes the route's middleware requires (can:<permission>).
     *
     * @return list<string>
     */
    private function requiredPermissions(Route $route): array
    {
        $codes = array_map(fn (Permission $permission): string => $permission->value, Permission::cases());

        return array_values(array_filter(
            array_map(fn (string $middleware): string => Str::after($middleware, 'can:'), array_filter($route->gatherMiddleware(), fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'can:'))),
            fn (string $ability): bool => in_array($ability, $codes, true),
        ));
    }

    private function requestRoute(Route $route): TestResponse
    {
        $uri = '/'.ltrim(preg_replace_callback('/\{(\w+)\??\}/', function (array $match) use ($route): string {
            $this->assertArrayHasKey($match[1], $this->parameters, "No test value for parameter {$match[1]} of {$route->uri()}. Add one to the matrix.");

            return $this->parameters[$match[1]];
        }, $route->uri()), '/');
        $method = collect($route->methods())->first(fn (string $method): bool => $method !== 'HEAD');

        return $this->call($method, $uri);
    }

    private function describe(Route $route): string
    {
        return implode('|', $route->methods()).' /'.$route->uri();
    }

    private function assertDenied(TestResponse $response, Route $route, string $who): void
    {
        $this->assertContains($response->getStatusCode(), [403, 404], "{$who} was not refused on {$this->describe($route)} (status {$response->getStatusCode()}).");
    }

    public function test_every_protected_route_requires_sign_in(): void
    {
        foreach ($this->protectedRoutes() as $route) {
            $response = $this->requestRoute($route);
            $this->assertTrue($response->isRedirect(route('login')), "Guest reached {$this->describe($route)} (status {$response->getStatusCode()}).");
        }
    }

    public function test_every_route_refuses_users_without_its_permissions(): void
    {
        $users = [
            'candidate' => $this->candidateInA->user,
            'instructor' => $this->alpha,
            'academic administrator' => $this->userWithRole(SystemRole::AcademicAdministrator),
            'super administrator' => $this->userWithRole(SystemRole::SuperAdministrator),
        ];
        $checked = 0;

        foreach ($users as $who => $user) {
            foreach ($this->protectedRoutes() as $route) {
                $missing = array_filter($this->requiredPermissions($route), fn (string $code): bool => ! $user->hasPermission(Permission::from($code)));
                if ($missing === []) {
                    continue;
                }
                $this->actingAs($user);
                $this->assertDenied($this->requestRoute($route), $route, $who);
                $checked++;
            }
        }

        $this->assertGreaterThan(150, $checked);
    }

    public function test_candidates_cannot_reach_any_staff_route_or_another_candidates_records(): void
    {
        $candidate = $this->candidateInA->user;
        foreach ($this->protectedRoutes() as $route) {
            $isStaff = in_array(Permission::AccessStaffArea->value, $this->requiredPermissions($route), true);
            // {type} is a document kind of the candidate's own record, not another record.
            $usesOtherRecords = preg_match('/\{(?!type\})\w+\}/', $route->uri()) === 1;
            if (! $isStaff && ! $usesOtherRecords) {
                continue;
            }
            $this->actingAs($candidate);
            $this->assertDenied($this->requestRoute($route), $route, 'Candidate A1');
        }

        $this->assertSame('in_progress', $this->attemptOfB1->fresh()->status);
        $this->assertSame([], $this->attemptOfB1->fresh()->answers);
    }

    public function test_an_instructor_cannot_use_another_instructors_classes_subjects_or_examinations(): void
    {
        foreach ($this->protectedRoutes() as $route) {
            if (! str_contains($route->uri(), '{') || in_array($route->uri(), self::INSTITUTION_WIDE_FOR_INSTRUCTORS, true)) {
                continue;
            }
            $this->actingAs($this->alpha);
            $this->assertDenied($this->requestRoute($route), $route, 'Instructor Alpha');
        }

        $this->assertSame('Quiz B', Assessment::query()->where('class_subject_id', $this->offeringB1->id)->value('title'));
        $this->assertSame('published', Examination::query()->where('class_subject_id', $this->offeringB1->id)->value('status')->value);
    }

    public function test_the_owning_instructor_can_open_the_same_records(): void
    {
        // Control: the matrix values are real, reachable records.
        $this->actingAs($this->bravo);
        foreach ([
            '/examinations/'.$this->parameters['examination'],
            '/assessments/'.$this->parameters['assessment'],
            '/candidates/'.$this->parameters['candidate'],
            '/my-classes/'.$this->parameters['classBatch'],
            '/my-classes/'.$this->parameters['classBatch'].'/subjects/'.$this->parameters['classSubject'],
            '/question-bank/'.$this->parameters['question'],
            '/conduct/candidates/'.$this->parameters['candidate'],
            '/attendance/sessions/'.$this->parameters['attendanceSession'],
            '/fitness/tests/'.$this->parameters['fitnessTest'],
        ] as $url) {
            $this->get($url)->assertOk();
        }

        $this->actingAs($this->candidateInB->user)->get('/portal/attempts/'.$this->parameters['attempt'])->assertOk();
    }

    public function test_the_private_storage_route_is_not_registered(): void
    {
        $this->assertNull(collect(Router::getRoutes()->getRoutes())->first(fn (Route $route): bool => str_starts_with($route->uri(), 'storage/')));
        $this->actingAs($this->userWithRole(SystemRole::SuperAdministrator))
            ->get('/storage/question-media/1/sample.png')
            ->assertNotFound();
    }
}
