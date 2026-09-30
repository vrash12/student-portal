<?php

namespace Tests\Feature\Reporting;

use App\Enums\AuditAction;
use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\AssessmentScore;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Examinations\ExaminationService;
use App\Services\Grading\ScoreRecordingService;
use App\Support\DecimalValue;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Milestone 16 audit history (/audit-history): read-only, administrator
 * permission only, filtered and paginated, and never showing secrets.
 */
class AuditHistoryTest extends TestCase
{
    use BuildsReportingFixtures;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-10 08:00:00'));
        $this->superAdmin = $this->userWithRole(SystemRole::SuperAdministrator, ['name' => 'Super Admin']);
    }

    // ------------------------------------------------------------------
    // Access
    // ------------------------------------------------------------------

    public function test_administrators_can_read_the_audit_history_with_private_caching(): void
    {
        $academicAdmin = $this->userWithRole(SystemRole::AcademicAdministrator);

        foreach ([$this->superAdmin, $academicAdmin] as $administrator) {
            $this->actingAs($administrator)->get('/audit-history')->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertInertia(fn (Assert $page) => $page->component('staff/audit-history/index'));
        }
    }

    public function test_instructors_candidates_guests_and_deactivated_administrators_are_denied(): void
    {
        $instructor = $this->userWithRole(SystemRole::Instructor);
        $candidate = Candidate::factory()->create();

        $this->actingAs($instructor)->get('/audit-history')->assertForbidden();
        $this->actingAs($candidate->user)->get('/audit-history')->assertForbidden();

        $inactive = User::factory()->withRole(SystemRole::SuperAdministrator)->create(['is_active' => false]);
        $this->actingAs($inactive)->get('/audit-history')->assertRedirect('/login');

        auth()->logout();
        $this->get('/audit-history')->assertRedirect('/login');
    }

    public function test_access_follows_the_permission_not_the_role_name(): void
    {
        $auditor = $this->staffWithPermissions('internal_auditor', [PermissionCode::ViewAuditHistory]);
        $reportsOnly = $this->staffWithPermissions('report_reader', [PermissionCode::ViewReports, PermissionCode::ViewAllCandidates]);

        $this->actingAs($auditor)->get('/audit-history')->assertOk();
        $this->actingAs($reportsOnly)->get('/audit-history')->assertForbidden();
    }

    public function test_the_history_is_read_only(): void
    {
        $entry = $this->log(AuditAction::SubjectCreated, ['name' => 'Subject 9']);
        $before = AuditLog::query()->count();

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->actingAs($this->superAdmin)->{$method}('/audit-history')->assertMethodNotAllowed();
            $status = $this->actingAs($this->superAdmin)->{$method}("/audit-history/{$entry->id}")->status();
            $this->assertContains($status, [404, 405], "{$method} /audit-history/{id}");
        }

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'audit-history')) {
                $this->assertSame(['GET', 'HEAD'], $route->methods(), $route->uri());
            }
        }
        $this->assertSame($before, AuditLog::query()->count());
        $this->assertSame(['name' => 'Subject 9'], $entry->fresh()->new_values);
    }

    // ------------------------------------------------------------------
    // Listing, pagination and filters
    // ------------------------------------------------------------------

    public function test_entries_are_newest_first_and_paginated_by_twenty_five(): void
    {
        for ($index = 1; $index <= 30; $index++) {
            $this->log(AuditAction::SubjectUpdated, ['name' => "Subject {$index}"]);
        }

        $first = $this->historyProps(['action' => AuditAction::SubjectUpdated->value])['entries'];
        $second = $this->historyProps(['action' => AuditAction::SubjectUpdated->value, 'page' => 2])['entries'];

        $this->assertSame(30, $first['total']);
        $this->assertSame(25, $first['per_page']);
        $this->assertCount(25, $first['data']);
        $this->assertCount(5, $second['data']);
        $this->assertSame(['name' => 'Subject 30'], $first['data'][0]['after']);
        $this->assertSame(['name' => 'Subject 1'], $second['data'][4]['after']);
        $this->assertStringContainsString('action=subject.updated', $first['next_page_url']);
        $this->assertSame([], $this->historyProps(['page' => 99])['entries']['data']);
    }

    public function test_entries_show_readable_labels_and_unavailable_actors(): void
    {
        $this->log(AuditAction::SubjectCreated, ['name' => 'Subject 7'], reason: 'Synthetic reason');
        $this->app->make(AuditLogger::class)->record(AuditAction::LoginFailed, newValues: ['username' => 'unknown.user']);
        $legacy = new AuditLog;
        $legacy->forceFill(['actor_id' => null, 'action' => 'legacy.retired_action', 'new_values' => ['note' => 'kept']])->save();

        $entries = collect($this->historyProps()['entries']['data'])->keyBy('id');

        $created = $entries->firstWhere('after', ['name' => 'Subject 7']);
        $this->assertSame('Super Admin', $created['actor']);
        $this->assertSame(AuditAction::SubjectCreated->label(), $created['action']);
        $this->assertSame('Synthetic reason', $created['reason']);
        $this->assertNull($created['before']);

        $this->assertSame('System / unavailable actor', $entries[$legacy->id]['actor']);
        $this->assertSame('legacy.retired_action', $entries[$legacy->id]['action']);
    }

    public function test_filters_by_actor_action_entity_and_entity_id(): void
    {
        $candidateOne = Candidate::factory()->create();
        $candidateTwo = Candidate::factory()->create();
        $other = $this->userWithRole(SystemRole::AcademicAdministrator, ['name' => 'Other Admin']);
        $logger = $this->app->make(AuditLogger::class);
        $logger->record(AuditAction::CandidateUpdated, $candidateOne, newValues: ['status' => 'on_leave'], actor: $this->superAdmin);
        $logger->record(AuditAction::CandidateUpdated, $candidateTwo, newValues: ['status' => 'enrolled'], actor: $other);
        $logger->record(AuditAction::CandidatePasswordReset, $candidateOne, actor: $other);

        $byActor = $this->historyProps(['actor' => $other->id])['entries']['data'];
        $this->assertSame(['Other Admin', 'Other Admin'], array_column($byActor, 'actor'));

        $byAction = $this->historyProps(['action' => AuditAction::CandidateUpdated->value])['entries']['data'];
        $this->assertCount(2, $byAction);

        $byEntity = $this->historyProps(['entity' => 'candidate', 'entity_id' => $candidateOne->id])['entries']['data'];
        $this->assertSame([$candidateOne->id, $candidateOne->id], array_column($byEntity, 'entityId'));
        $this->assertSame(['candidate', 'candidate'], array_column($byEntity, 'entity'));

        $combined = $this->historyProps(['actor' => $other->id, 'action' => AuditAction::CandidateUpdated->value, 'entity' => 'candidate'])['entries']['data'];
        $this->assertSame([['status' => 'enrolled']], array_column($combined, 'after'));

        $this->assertSame(0, $this->historyProps(['actor' => 999999])['entries']['total']);
    }

    public function test_date_filters_use_institution_day_boundaries(): void
    {
        config(['institution.timezone' => 'Asia/Manila']);
        // 23:30 on 14 September and 00:30 on 15 September in Manila.
        $this->travelTo(CarbonImmutable::parse('2026-09-14 15:30:00'));
        $late = $this->log(AuditAction::SubjectUpdated, ['name' => 'Late evening']);
        $this->travelTo(CarbonImmutable::parse('2026-09-14 16:30:00'));
        $early = $this->log(AuditAction::SubjectUpdated, ['name' => 'Early morning']);

        $filter = fn (array $dates): array => array_column($this->historyProps(['action' => AuditAction::SubjectUpdated->value, ...$dates])['entries']['data'], 'id');

        $this->assertSame([$late->id], $filter(['from' => '2026-09-14', 'to' => '2026-09-14']));
        $this->assertSame([$early->id], $filter(['from' => '2026-09-15', 'to' => '2026-09-15']));
        $this->assertSame([$early->id], $filter(['from' => '2026-09-15']));
        $this->assertSame([$late->id], $filter(['to' => '2026-09-14']));
        $this->assertSame([$early->id, $late->id], $filter(['from' => '2026-09-14', 'to' => '2026-09-15']));
    }

    public function test_filter_options_list_actors_actions_and_entities(): void
    {
        $this->log(AuditAction::SubjectCreated, ['name' => 'Subject 8']);
        $silent = $this->userWithRole(SystemRole::AcademicAdministrator, ['name' => 'Never Logged']);

        $props = $this->historyProps();

        $this->assertContains('Super Admin', array_column($props['actors'], 'name'));
        $this->assertNotContains($silent->name, array_column($props['actors'], 'name'));
        $this->assertSame(['id', 'name'], array_keys($props['actors'][0]));
        $this->assertContains(['value' => 'subject.created', 'label' => AuditAction::SubjectCreated->label()], $props['actions']);
        $this->assertContains('candidate', $props['entities']);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_validation_errors_not_server_errors(array $query, string $field): void
    {
        $this->actingAs($this->superAdmin)->from('/audit-history')->get('/audit-history?'.http_build_query($query))
            ->assertRedirect('/audit-history')->assertSessionHasErrors($field);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidFilters(): array
    {
        return [
            'text actor' => [['actor' => 'admin'], 'actor'],
            'actor array' => [['actor' => [1]], 'actor'],
            'unknown action' => [['action' => 'grades.deleted_everything'], 'action'],
            'class name entity' => [['entity' => 'App\\Models\\Candidate'], 'entity'],
            'unknown entity' => [['entity' => 'audit_log'], 'entity'],
            'negative entity id' => [['entity_id' => -4], 'entity_id'],
            'bad from date' => [['from' => '14-09-2026'], 'from'],
            'to before from' => [['from' => '2026-09-15', 'to' => '2026-09-14'], 'to'],
            'zero page' => [['page' => 0], 'page'],
        ];
    }

    // ------------------------------------------------------------------
    // Confidentiality
    // ------------------------------------------------------------------

    public function test_stored_secrets_are_never_shown_even_in_legacy_entries(): void
    {
        $legacy = new AuditLog;
        $legacy->forceFill([
            'actor_id' => $this->superAdmin->id,
            'action' => AuditAction::ExaminationUpdated->value,
            'old_values' => ['password' => 'legacy-secret-password', 'title' => 'Old title'],
            'new_values' => [
                'title' => 'New title',
                'accessCode' => 'legacy-secret-access',
                'access_code' => 'legacy-secret-access',
                'answers' => ['legacy-secret-answer'],
                'answer' => 'legacy-secret-answer',
                'scoring_key' => ['legacy-secret-key'],
                'scoringKey' => ['legacy-secret-key'],
                'comment' => 'legacy-secret-comment',
                'newComment' => 'legacy-secret-comment',
                'remember_token' => 'legacy-secret-token',
                'items' => [['question_id' => 1, 'correct_answer' => 'legacy-secret-answer', 'is_correct' => true, 'points' => 2]],
            ],
        ])->save();

        $props = $this->historyProps();
        $entry = collect($props['entries']['data'])->firstWhere('id', $legacy->id);

        $this->assertSame(['title' => 'Old title'], $entry['before']);
        $this->assertSame(['title' => 'New title', 'items' => [['question_id' => 1, 'points' => 2]]], $entry['after']);
        $this->assertStringNotContainsString('legacy-secret', json_encode($props));
    }

    public function test_real_workflows_never_expose_passwords_access_codes_or_comments(): void
    {
        Storage::fake('local');
        $this->buildReportingFixtures();

        // Candidate account creation with a password.
        $this->actingAs($this->academicAdmin)->post('/candidates', [
            'candidate_number' => 'SYNTHETIC-AUDIT-1', 'first_name' => 'Candidate', 'last_name' => 'Audit',
            'class_batch_id' => $this->batchA->id, 'password' => 'workflow-secret-pass1', 'password_confirmation' => 'workflow-secret-pass1',
            'profile_photo' => UploadedFile::fake()->image('profile.png'),
        ])->assertRedirect();

        // Examination with an access code.
        $this->app->make(ExaminationService::class)->create($this->alpha, [
            'class_subject_id' => $this->offeringA1->id, 'title' => 'Audited Exam', 'kind' => 'examination',
            'duration_minutes' => 30, 'attempt_limit' => 1, 'access_code' => 'workflow-secret-code',
        ]);

        // Finalized score correction with a private comment.
        $current = AssessmentScore::query()->where('assessment_id', $this->quiz1->id)->where('candidate_id', $this->secondInA->id)->sole();
        $this->app->make(ScoreRecordingService::class)->correctFinalizedScore(
            $this->quiz1, $this->secondInA->id, '65', 'workflow-secret-comment', 'Re-marked item 3',
            DecimalValue::normalize($current->score), $current->comment, $this->alpha,
        );

        $props = $this->historyProps([], $this->superAdmin);
        $json = json_encode($props);

        $actions = array_column($props['entries']['data'], 'action');
        foreach ([AuditAction::CandidateCreated, AuditAction::ExaminationCreated, AuditAction::AssessmentScoreCorrected] as $action) {
            $this->assertContains($action->label(), $actions);
        }
        $this->assertStringNotContainsString('workflow-secret', $json);
        $this->assertStringContainsString('Re-marked item 3', $json);
        $correction = collect($props['entries']['data'])->firstWhere('action', AuditAction::AssessmentScoreCorrected->label());
        $this->assertEquals(65, $correction['after']['score']);
        $this->assertArrayNotHasKey('comment', $correction['after']);
        $this->assertArrayNotHasKey('comment', $correction['before'] ?? []);
        $this->assertStringNotContainsString('ip_address', $json);
        $this->assertStringNotContainsString('user_agent', $json);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function log(AuditAction $action, array $newValues, ?string $reason = null): AuditLog
    {
        return $this->app->make(AuditLogger::class)->record($action, newValues: $newValues, reason: $reason, actor: $this->superAdmin);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function historyProps(array $query = [], ?User $viewer = null): array
    {
        return $this->actingAs($viewer ?? $this->superAdmin)
            ->get('/audit-history'.($query === [] ? '' : '?'.http_build_query($query)))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/audit-history/index'))
            ->inertiaProps();
    }
}
