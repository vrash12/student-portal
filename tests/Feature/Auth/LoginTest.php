<?php

namespace Tests\Feature\Auth;

use App\Enums\AuditAction;
use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LoginTest extends TestCase
{
    private const GENERIC_FAILURE = 'The username or password is incorrect.';

    public function test_login_page_renders_for_guests(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/login'));
    }

    public function test_login_page_shows_the_configurable_powered_by_credit(): void
    {
        config(['institution.powered_by_name' => 'ServLife Solutions', 'institution.powered_by_logo_url' => '/branding/powered-by-logo.png']);

        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('app.poweredBy', ['name' => 'ServLife Solutions', 'logoUrl' => '/branding/powered-by-logo.png']));
        $this->assertFileExists(public_path('branding/powered-by-logo.png'));

        config(['institution.powered_by_name' => '']);
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('app.poweredBy', null));
    }

    public function test_login_page_texts_come_from_configuration_with_fallbacks(): void
    {
        config([
            'institution.organization_name' => 'Sample School',
            'institution.short_name' => 'Sample System',
            'institution.system_name' => 'Sample Monitoring',
            'institution.login' => ['header_title' => null, 'header_subtitle' => null, 'card_title' => null, 'core_values' => [], 'motto' => null, 'tagline' => null, 'help_desk' => null],
        ]);
        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('app.login', ['headerTitle' => 'Sample School', 'headerSubtitle' => 'Sample System', 'cardTitle' => 'Sample Monitoring', 'coreValues' => [], 'motto' => null, 'tagline' => null, 'helpDesk' => null]));

        config(['institution.login' => [
            'header_title' => 'Officer Candidate School', 'header_subtitle' => 'Philippine Army', 'card_title' => 'Officer Candidate Training Management System', 'core_values' => ['Discipline', 'Integrity'],
            'motto' => 'Leaders for a Stronger Philippines', 'tagline' => 'Secure platform.', 'help_desk' => 'Local 123',
        ]]);
        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('app.login.headerTitle', 'Officer Candidate School')
            ->where('app.login.cardTitle', 'Officer Candidate Training Management System')
            ->where('app.login.coreValues', ['Discipline', 'Integrity'])
            ->where('app.login.helpDesk', 'Local 123'));
    }

    public function test_staff_member_signs_in_and_lands_on_the_dashboard(): void
    {
        $user = $this->userWithRole(SystemRole::Instructor, ['username' => 'instructor.test']);

        $this->post('/login', ['username' => 'instructor.test', 'password' => 'password'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->get(route('home'))->assertRedirect(route('dashboard'));
    }

    public function test_candidate_signs_in_and_lands_on_the_examination_portal(): void
    {
        $user = $this->userWithRole(SystemRole::Candidate, ['username' => 'candidate.test']);

        $this->post('/login', ['username' => 'candidate.test', 'password' => 'password'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->get(route('home'))->assertRedirect(route('portal.home'));
    }

    public function test_a_page_remembered_before_sign_in_is_opened_only_within_the_users_own_area(): void
    {
        $this->userWithRole(SystemRole::Candidate, ['username' => 'candidate.test']);
        $this->userWithRole(SystemRole::AcademicAdministrator, ['username' => 'admin.test']);

        // A staff page remembered on a shared tablet does not send a candidate to an access-denied page.
        $this->get('/candidates')->assertRedirect(route('login'));
        $this->post('/login', ['username' => 'candidate.test', 'password' => 'password'])->assertRedirect(route('home'));
        $this->post('/logout');

        // Staff still return to the page they asked for.
        $this->get('/candidates')->assertRedirect(route('login'));
        $this->post('/login', ['username' => 'admin.test', 'password' => 'password'])->assertRedirect('/candidates');
    }

    public function test_username_is_trimmed_and_not_case_sensitive(): void
    {
        $user = $this->userWithRole(SystemRole::Instructor, ['username' => 'instructor.test']);

        $this->post('/login', ['username' => '  Instructor.TEST ', 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_and_unknown_username_receive_the_same_message(): void
    {
        $this->userWithRole(SystemRole::Instructor, ['username' => 'instructor.test']);

        $this->from('/login')
            ->post('/login', ['username' => 'instructor.test', 'password' => 'not-the-password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['username' => self::GENERIC_FAILURE]);

        $this->from('/login')
            ->post('/login', ['username' => 'nobody.here', 'password' => 'not-the-password'])
            ->assertSessionHasErrors(['username' => self::GENERIC_FAILURE]);

        $this->assertGuest();
    }

    public function test_missing_credentials_are_rejected_with_field_errors(): void
    {
        $this->post('/login', ['username' => '', 'password' => ''])
            ->assertSessionHasErrors([
                'username' => 'Enter your username.',
                'password' => 'Enter your password.',
            ]);
    }

    public function test_failed_sign_in_is_audited_without_the_password(): void
    {
        $user = $this->userWithRole(SystemRole::Instructor, ['username' => 'instructor.test']);

        $this->post('/login', ['username' => 'instructor.test', 'password' => 'secret-attempt-123']);

        $entry = AuditLog::query()->where('action', AuditAction::LoginFailed->value)->sole();
        $this->assertNull($entry->actor_id);
        $this->assertSame('user', $entry->auditable_type);
        $this->assertSame($user->id, $entry->auditable_id);
        $this->assertSame(['username' => 'instructor.test'], $entry->new_values);
        $this->assertStringNotContainsString('secret-attempt-123', (string) json_encode($entry->getAttributes()));
    }

    public function test_deactivated_account_cannot_sign_in(): void
    {
        User::factory()->withRole(SystemRole::Instructor)->inactive()->create(['username' => 'former.instructor']);

        $this->post('/login', ['username' => 'former.instructor', 'password' => 'password'])
            ->assertSessionHasErrors(['username' => 'This account has been deactivated. Contact an administrator.']);

        $this->assertGuest();
    }

    public function test_deactivation_is_only_disclosed_after_a_correct_password(): void
    {
        User::factory()->withRole(SystemRole::Instructor)->inactive()->create(['username' => 'former.instructor']);

        $this->post('/login', ['username' => 'former.instructor', 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['username' => self::GENERIC_FAILURE]);
    }

    public function test_sign_in_is_locked_after_five_failed_attempts(): void
    {
        $this->userWithRole(SystemRole::Instructor, ['username' => 'instructor.test']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', ['username' => 'instructor.test', 'password' => 'wrong-password']);
        }

        // Even the correct password is refused while the lockout lasts.
        $response = $this->post('/login', ['username' => 'instructor.test', 'password' => 'password']);

        $response->assertSessionHasErrors('username');
        $this->assertStringStartsWith('Too many sign-in attempts.', session('errors')->first('username'));
        $this->assertGuest();
    }

    public function test_successful_sign_in_is_audited_and_records_the_time(): void
    {
        $user = $this->userWithRole(SystemRole::Instructor, ['username' => 'instructor.test']);

        $this->post('/login', ['username' => 'instructor.test', 'password' => 'password']);

        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::Login->value,
            'actor_id' => $user->id,
            'auditable_type' => 'user',
            'auditable_id' => $user->id,
        ]);
    }

    public function test_recording_the_sign_in_time_does_not_touch_updated_at(): void
    {
        $this->travelTo(now()->subDay());
        $user = $this->userWithRole(SystemRole::Instructor, ['username' => 'instructor.test']);
        $originalUpdatedAt = $user->updated_at->toIso8601String();
        $this->travelBack();

        $this->post('/login', ['username' => 'instructor.test', 'password' => 'password']);

        $this->assertSame($originalUpdatedAt, $user->fresh()->updated_at->toIso8601String());
    }

    public function test_user_can_sign_out(): void
    {
        $user = $this->userWithRole(SystemRole::Instructor);

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::Logout->value, 'actor_id' => $user->id]);
    }

    public function test_signed_in_user_visiting_the_login_page_is_sent_home(): void
    {
        $this->actingAs($this->userWithRole(SystemRole::Instructor))
            ->get('/login')
            ->assertRedirect(route('home'));
    }
}
