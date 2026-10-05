<?php

namespace Tests\Feature\Auth;

use App\Enums\AuditAction;
use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\Totp;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TwoFactorSetupTest extends TestCase
{
    private function enable(User $user): string
    {
        $service = app(TwoFactorService::class);
        $secret = $service->beginSetup($user);
        $service->confirmSetup($user, Totp::codeAt($secret, Totp::stepAt(time())));

        return $secret;
    }

    public function test_setting_up_asks_for_the_password_then_shows_a_qr_code(): void
    {
        $user = $this->userWithRole(SystemRole::Instructor);

        $this->actingAs($user)->get(route('account.two-factor.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/account/two-factor')
                ->where('enabled', false)->where('required', false)->where('setup', null)->where('newRecoveryCodes', null));

        $this->post(route('account.two-factor.store'), ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->assertNull($user->fresh()->two_factor_secret);

        $this->post(route('account.two-factor.store'), ['current_password' => 'password'])->assertRedirect(route('account.two-factor.show'));
        $secret = (string) $user->fresh()->two_factor_secret;
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());

        $this->get(route('account.two-factor.show'))->assertInertia(fn (Assert $page) => $page
            ->where('enabled', false)
            ->where('setup.secret', Totp::formatSecret($secret))
            ->where('setup.account', $user->username)
            ->where('setup.qrCode', fn (string $qr) => str_starts_with($qr, 'data:image/svg+xml;base64,')));
    }

    public function test_a_wrong_code_does_not_turn_it_on(): void
    {
        $user = $this->userWithRole(SystemRole::Instructor);
        $secret = app(TwoFactorService::class)->beginSetup($user);
        $wrong = Totp::codeAt($secret, Totp::stepAt(time()) - 5);

        $this->actingAs($user)->post(route('account.two-factor.confirm'), ['code' => $wrong])->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_the_right_code_turns_it_on_and_shows_the_recovery_codes_once(): void
    {
        $user = $this->userWithRole(SystemRole::Instructor);
        $secret = app(TwoFactorService::class)->beginSetup($user);

        $this->actingAs($user)
            ->post(route('account.two-factor.confirm'), ['code' => Totp::codeAt($secret, Totp::stepAt(time()))])
            ->assertRedirect(route('account.two-factor.show'));

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        $this->get(route('account.two-factor.show'))->assertInertia(fn (Assert $page) => $page
            ->where('enabled', true)->where('setup', null)->where('recoveryCodesLeft', 8)->has('newRecoveryCodes', 8));
        $this->get(route('account.two-factor.show'))->assertInertia(fn (Assert $page) => $page->where('newRecoveryCodes', null));

        $entry = AuditLog::query()->where('action', AuditAction::TwoFactorEnabled->value)->sole();
        $this->assertSame(['recovery_codes_issued' => 8], $entry->new_values);
        $this->assertStringNotContainsString($secret, json_encode($entry->toArray()));
    }

    public function test_cancelling_a_setup_removes_its_secret(): void
    {
        $user = $this->userWithRole(SystemRole::Instructor);
        app(TwoFactorService::class)->beginSetup($user);

        $this->actingAs($user)->post(route('account.two-factor.cancel'))->assertRedirect(route('account.two-factor.show'));
        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_new_recovery_codes_replace_the_old_ones(): void
    {
        $user = $this->userWithRole(SystemRole::Instructor);
        $service = app(TwoFactorService::class);
        $secret = $service->beginSetup($user);
        $old = $service->confirmSetup($user, Totp::codeAt($secret, Totp::stepAt(time())));

        $this->actingAs($user)->post(route('account.two-factor.recovery-codes'), ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->post(route('account.two-factor.recovery-codes'), ['current_password' => 'password'])->assertRedirect(route('account.two-factor.show'));

        $this->assertFalse($service->useRecoveryCode($user->fresh(), $old[0]));
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::TwoFactorRecoveryCodesRegenerated->value)->count());
    }

    public function test_staff_may_turn_it_off_unless_it_is_required(): void
    {
        $instructor = $this->userWithRole(SystemRole::Instructor);
        $this->enable($instructor);

        $this->actingAs($instructor)->delete(route('account.two-factor.destroy'), ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->assertTrue($instructor->fresh()->hasTwoFactorEnabled());
        $this->delete(route('account.two-factor.destroy'), ['current_password' => 'password'])->assertRedirect(route('account.two-factor.show'));
        $this->assertFalse($instructor->fresh()->hasTwoFactorEnabled());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::TwoFactorDisabled->value)->count());

        config(['two_factor.required_for' => 'administrators']);
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);
        $this->enable($admin);
        $this->actingAs($admin)->delete(route('account.two-factor.destroy'), ['current_password' => 'password'])->assertSessionHasErrors('current_password');
        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
    }

    public function test_administrators_must_set_it_up_before_using_the_staff_area(): void
    {
        config(['two_factor.required_for' => 'administrators']);
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);
        $academicAdmin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $instructor = $this->userWithRole(SystemRole::Instructor);

        $this->actingAs($admin)->get(route('dashboard'))->assertRedirect(route('account.two-factor.show'));
        $this->actingAs($academicAdmin)->get(route('users.index'))->assertRedirect(route('account.two-factor.show'));
        $this->actingAs($admin)->getJson(route('dashboard'))->assertForbidden();
        $this->actingAs($admin)->get(route('account.two-factor.show'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('required', true));
        $this->actingAs($admin)->get(route('account.password.edit'))->assertOk();
        $this->actingAs($instructor)->get(route('dashboard'))->assertOk();

        $this->enable($admin);
        $this->actingAs($admin->fresh())->get(route('dashboard'))->assertOk();
    }

    public function test_it_can_be_required_of_every_staff_member_or_of_nobody(): void
    {
        $instructor = $this->userWithRole(SystemRole::Instructor);

        config(['two_factor.required_for' => 'staff']);
        $this->actingAs($instructor)->get(route('dashboard'))->assertRedirect(route('account.two-factor.show'));

        config(['two_factor.required_for' => 'none']);
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);
        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
    }

    public function test_candidates_cannot_use_it(): void
    {
        config(['two_factor.required_for' => 'staff']);
        $candidate = $this->userWithRole(SystemRole::Candidate);

        $this->actingAs($candidate)->get(route('account.two-factor.show'))->assertForbidden();
        $this->actingAs($candidate)->post(route('account.two-factor.store'), ['current_password' => 'password'])->assertForbidden();
        $this->assertNull($candidate->fresh()->two_factor_secret);
    }

    public function test_an_administrator_resets_it_for_a_lost_phone_with_a_reason(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);
        $instructor = $this->userWithRole(SystemRole::Instructor);
        $this->enable($instructor);

        $this->actingAs($admin)->get(route('users.edit', $instructor))->assertInertia(fn (Assert $page) => $page
            ->where('account.twoFactor.enabled', true)->where('canResetTwoFactor', true));

        $this->delete(route('users.two-factor.destroy', $instructor), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertTrue($instructor->fresh()->hasTwoFactorEnabled());

        $this->delete(route('users.two-factor.destroy', $instructor), ['reason' => 'Lost phone, confirmed in person'])->assertRedirect(route('users.edit', $instructor));
        $this->assertFalse($instructor->fresh()->hasTwoFactorEnabled());
        $entry = AuditLog::query()->where('action', AuditAction::TwoFactorReset->value)->sole();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame($instructor->id, $entry->auditable_id);
        $this->assertSame('Lost phone, confirmed in person', $entry->reason);
    }

    public function test_nobody_resets_their_own_or_a_higher_ranked_account(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);
        $academicAdmin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $instructor = $this->userWithRole(SystemRole::Instructor);
        $this->enable($admin);
        $this->enable($academicAdmin);
        $other = $this->userWithRole(SystemRole::Instructor);
        $this->enable($other);

        $this->actingAs($admin)->delete(route('users.two-factor.destroy', $admin), ['reason' => 'Lost my phone'])->assertForbidden();
        $this->actingAs($academicAdmin)->delete(route('users.two-factor.destroy', $admin), ['reason' => 'Lost phone'])->assertForbidden();
        $this->actingAs($instructor)->delete(route('users.two-factor.destroy', $other), ['reason' => 'Lost phone'])->assertForbidden();

        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
        $this->assertTrue($other->fresh()->hasTwoFactorEnabled());
    }

    public function test_it_resets_from_the_server_command(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator, ['username' => 'only.admin']);
        $this->enable($admin);

        $this->artisan('two-factor:reset', ['username' => 'only.admin', '--reason' => 'Lost phone and codes'])->assertSuccessful();
        $this->artisan('two-factor:reset', ['username' => 'nobody'])->assertFailed();

        $this->assertFalse($admin->fresh()->hasTwoFactorEnabled());
        $entry = AuditLog::query()->where('action', AuditAction::TwoFactorReset->value)->sole();
        $this->assertNull($entry->actor_id);
        $this->assertSame('Server command: Lost phone and codes', $entry->reason);
    }
}
