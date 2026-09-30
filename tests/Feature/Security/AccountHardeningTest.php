<?php

namespace Tests\Feature\Security;

use App\Enums\AuditAction;
use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\Role;
use App\Services\UserAccountService;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Milestone 17: sign-in limits, failed sign-in records, administrator-set
 * passwords, and security headers.
 */
class AccountHardeningTest extends TestCase
{
    private const NEW_PASSWORD = 'Own-chosen-password-2026';

    public function test_many_failed_sign_ins_from_one_address_are_slowed_across_usernames(): void
    {
        $this->post('/login', ['username' => 'nobody.one', 'password' => 'wrong-password'])->assertSessionHasErrors('username');
        $this->assertSame(1, RateLimiter::attempts('login-address|127.0.0.1'));

        for ($i = 0; $i < 59; $i++) {
            RateLimiter::hit('login-address|127.0.0.1');
        }
        $user = $this->userWithRole(SystemRole::Instructor, ['username' => 'fresh.user', 'password' => 'Correct-password-1']);

        // Even a correct password for an untried username waits.
        $this->post('/login', ['username' => $user->username, 'password' => 'Correct-password-1'])
            ->assertSessionHasErrors(['username' => 'Too many sign-in attempts. Try again in 60 seconds.']);
        $this->assertGuest();
    }

    public function test_a_failed_sign_in_records_the_username_only_for_real_accounts(): void
    {
        $user = $this->userWithRole(SystemRole::Instructor, ['username' => 'real.user']);

        $this->post('/login', ['username' => 'Typed-Password-By-Mistake!', 'password' => 'x']);
        $this->post('/login', ['username' => 'real.user', 'password' => 'wrong-password']);

        $entries = AuditLog::query()->where('action', AuditAction::LoginFailed->value)->orderBy('id')->get();
        $this->assertSame(['username' => null, 'known_account' => false], $entries[0]->new_values);
        $this->assertSame(['username' => 'real.user'], $entries[1]->new_values);
        $this->assertSame($user->id, $entries[1]->auditable_id);
        $this->assertStringNotContainsString('Typed-Password', AuditLog::query()->get()->toJson());
    }

    public function test_a_password_set_by_an_administrator_must_be_replaced_at_sign_in(): void
    {
        $account = app(UserAccountService::class)->create([
            'name' => 'Instructor Charlie', 'username' => 'instructor.charlie', 'email' => null,
            'password' => 'Issued-password-2026', 'role_id' => Role::query()->where('code', SystemRole::Instructor->value)->value('id'), 'is_active' => true,
        ]);
        $this->assertTrue($account->fresh()->password_change_required);

        $this->actingAs($account->fresh());
        $this->get('/dashboard')->assertRedirect('/account/password');
        $this->get('/examinations')->assertRedirect('/account/password');
        $this->getJson('/dashboard')->assertForbidden();
        $this->get('/account/password')->assertOk()->assertInertia(fn ($page) => $page->where('changeRequired', true));

        $this->put('/account/password', ['current_password' => 'Issued-password-2026', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertSessionHasNoErrors();
        $this->assertFalse($account->fresh()->password_change_required);
        $this->get('/dashboard')->assertOk();

        // A later reset by an administrator requires a new choice again.
        app(UserAccountService::class)->update($account->fresh(), [
            'name' => 'Instructor Charlie', 'username' => 'instructor.charlie', 'email' => null,
            'password' => 'Reset-password-2026', 'role_id' => $account->role_id, 'is_active' => true,
        ]);
        $this->assertTrue($account->fresh()->password_change_required);
    }

    public function test_changing_other_details_does_not_require_a_new_password(): void
    {
        $account = $this->userWithRole(SystemRole::Instructor, ['username' => 'steady.user']);
        app(UserAccountService::class)->update($account, [
            'name' => 'Renamed Instructor', 'username' => 'steady.user', 'email' => null,
            'password' => null, 'role_id' => $account->role_id, 'is_active' => true,
        ]);

        $this->assertFalse($account->fresh()->password_change_required);
        $this->actingAs($account->fresh())->get('/dashboard')->assertOk();
    }

    public function test_pages_send_a_content_security_policy_and_signed_in_pages_are_not_stored(): void
    {
        $login = $this->get('/login')->assertOk();
        $policy = (string) $login->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]+'/", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertNull($login->headers->get('Strict-Transport-Security'));

        $admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        foreach (['/monitoring', '/users', '/classes', '/academic-periods'] as $url) {
            $this->assertStringContainsString('no-store', (string) $this->actingAs($admin)->get($url)->assertOk()->headers->get('Cache-Control'), $url);
        }
    }

    public function test_https_requests_receive_strict_transport_security(): void
    {
        $response = $this->get('https://localhost/login');

        $this->assertSame('max-age=31536000', $response->headers->get('Strict-Transport-Security'));
    }

    public function test_password_changes_are_rate_limited_separately(): void
    {
        $user = $this->userWithRole(SystemRole::Instructor);
        $this->actingAs($user);
        for ($i = 0; $i < 6; $i++) {
            $this->put('/account/password', ['current_password' => 'wrong-password', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);
        }
        $this->put('/account/password', ['current_password' => 'wrong-password', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])->assertTooManyRequests();

        // Other limits are unaffected.
        $this->get('/dashboard')->assertOk();
    }
}
