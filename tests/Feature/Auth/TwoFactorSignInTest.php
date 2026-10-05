<?php

namespace Tests\Feature\Auth;

use App\Enums\AuditAction;
use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\Totp;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TwoFactorSignInTest extends TestCase
{
    /**
     * @return array{User, string, list<string>} the user, the secret and the recovery codes
     */
    private function userWithTwoFactor(SystemRole $role = SystemRole::SuperAdministrator, string $username = 'admin.test'): array
    {
        $user = $this->userWithRole($role, ['username' => $username]);
        $service = app(TwoFactorService::class);
        $secret = $service->beginSetup($user);
        // Confirmed with the previous step's code, so the current one is still unused.
        $codes = $service->confirmSetup($user, Totp::codeAt($secret, Totp::stepAt(time()) - 1));
        $this->assertNotNull($codes);

        return [$user->fresh(), $secret, $codes];
    }

    private function currentCode(string $secret): string
    {
        return Totp::codeAt($secret, Totp::stepAt(time()));
    }

    private function enterPassword(string $username = 'admin.test'): void
    {
        $this->post('/login', ['username' => $username, 'password' => 'password'])
            ->assertRedirect(route('two-factor.challenge'));
    }

    public function test_the_password_alone_does_not_sign_in_an_account_with_two_step_sign_in(): void
    {
        $this->userWithTwoFactor();

        $this->enterPassword();

        $this->assertGuest();
        $this->get(route('two-factor.challenge'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/two-factor-challenge')->where('username', 'admin.test'));
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_the_code_from_the_app_completes_the_sign_in(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        $this->enterPassword();

        $this->post(route('two-factor.verify'), ['code' => substr_replace($this->currentCode($secret), ' ', 3, 0)])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $login = AuditLog::query()->where('action', AuditAction::Login->value)->latest('id')->firstOrFail();
        $this->assertSame(['two_factor' => 'authenticator'], $login->new_values);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_a_wrong_code_is_refused_and_recorded_without_the_code(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        $this->enterPassword();
        $wrong = $this->currentCode($secret) === '135792' ? '246813' : '135792';

        $this->from(route('two-factor.challenge'))
            ->post(route('two-factor.verify'), ['code' => $wrong])
            ->assertRedirect(route('two-factor.challenge'))
            ->assertSessionHasErrors('code');

        $this->assertGuest();
        $failure = AuditLog::query()->where('action', AuditAction::TwoFactorFailed->value)->sole();
        $this->assertSame($user->id, $failure->auditable_id);
        $this->assertSame(['method' => 'authenticator'], $failure->new_values);
        $this->assertStringNotContainsString($wrong, json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_a_code_signs_in_only_once(): void
    {
        [, $secret] = $this->userWithTwoFactor();
        $code = $this->currentCode($secret);

        $this->enterPassword();
        $this->post(route('two-factor.verify'), ['code' => $code])->assertRedirect(route('home'));
        $this->post('/logout');

        $this->enterPassword();
        $this->post(route('two-factor.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_an_older_code_is_refused_after_a_newer_one_was_used(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        $service = app(TwoFactorService::class);
        $now = time();

        $this->assertTrue($service->verifyCode($user, Totp::codeAt($secret, Totp::stepAt($now) + 1), $now));
        $this->assertFalse($service->verifyCode($user->fresh(), $this->currentCode($secret), $now));
    }

    public function test_a_recovery_code_signs_in_once_and_is_crossed_off(): void
    {
        [$user, , $codes] = $this->userWithTwoFactor();
        $this->assertCount(8, $codes);
        $this->assertMatchesRegularExpression('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $codes[0]);

        $this->enterPassword();
        $this->post(route('two-factor.verify'), ['recovery_code' => strtoupper($codes[0])])->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(7, app(TwoFactorService::class)->recoveryCodesLeft($user->fresh()));
        $used = AuditLog::query()->where('action', AuditAction::TwoFactorRecoveryCodeUsed->value)->sole();
        $this->assertSame(['recovery_codes_left' => 7], $used->new_values);
        $this->post('/logout');

        $this->enterPassword();
        $this->post(route('two-factor.verify'), ['recovery_code' => $codes[0]])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    public function test_too_many_wrong_codes_need_the_password_again(): void
    {
        [, $secret] = $this->userWithTwoFactor();
        $this->enterPassword();
        $wrong = $this->currentCode($secret) === '135792' ? '246813' : '135792';

        for ($attempt = 1; $attempt < 5; $attempt++) {
            $this->post(route('two-factor.verify'), ['code' => $wrong])->assertSessionHasErrors('code');
        }
        $this->post(route('two-factor.verify'), ['code' => $wrong])->assertRedirect(route('login'));

        // Even the right code no longer works without the password.
        $this->post(route('two-factor.verify'), ['code' => $this->currentCode($secret)])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_second_step_times_out(): void
    {
        [, $secret] = $this->userWithTwoFactor();
        $this->enterPassword();

        $this->travel(6)->minutes();

        $this->post(route('two-factor.verify'), ['code' => $this->currentCode($secret)])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_second_step_needs_a_correct_password_first(): void
    {
        $this->userWithTwoFactor();

        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
        $this->post(route('two-factor.verify'), ['code' => '123456'])->assertRedirect(route('login'));

        $this->post('/login', ['username' => 'admin.test', 'password' => 'wrong-password'])->assertSessionHasErrors('username');
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    }

    public function test_an_account_deactivated_during_the_second_step_cannot_finish(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        $this->enterPassword();

        $user->forceFill(['is_active' => false])->save();

        $this->post(route('two-factor.verify'), ['code' => $this->currentCode($secret)])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_setup_not_yet_confirmed_does_not_ask_for_a_code(): void
    {
        $user = $this->userWithRole(SystemRole::Instructor, ['username' => 'instructor.test']);
        app(TwoFactorService::class)->beginSetup($user);

        $this->post('/login', ['username' => 'instructor.test', 'password' => 'password'])->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_secret_and_recovery_codes_are_stored_encrypted_and_never_serialized(): void
    {
        [$user, $secret, $codes] = $this->userWithTwoFactor();

        $row = (array) DB::table('users')->where('id', $user->id)->first();
        $this->assertStringNotContainsString($secret, (string) $row['two_factor_secret']);
        $this->assertStringNotContainsString(str_replace('-', '', $codes[0]), (string) $row['two_factor_recovery_codes']);
        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $user->toArray());
    }
}
