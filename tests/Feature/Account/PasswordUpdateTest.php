<?php

namespace Tests\Feature\Account;

use App\Enums\AuditAction;
use App\Enums\SystemRole;
use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    public function test_staff_member_changes_their_password(): void
    {
        $user = $this->userWithRole(SystemRole::Instructor);

        $this->actingAs($user)
            ->put('/account/password', [
                'current_password' => 'password',
                'password' => 'my-new-long-password',
                'password_confirmation' => 'my-new-long-password',
            ])
            ->assertRedirect(route('account.password.edit'))
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('my-new-long-password', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::OwnPasswordChanged->value,
            'actor_id' => $user->id,
            'auditable_id' => $user->id,
        ]);
    }

    public function test_changing_the_password_signs_out_other_devices(): void
    {
        $user = $this->userWithRole(SystemRole::Instructor);
        $this->storeSession($user, 'other-device');

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'password',
            'password' => 'my-new-long-password',
            'password_confirmation' => 'my-new-long-password',
        ]);

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
    }

    public function test_the_session_that_changed_the_password_is_kept(): void
    {
        $user = $this->userWithRole(SystemRole::Instructor);
        $this->storeSession($user, 'current-device');
        $this->storeSession($user, 'other-device');

        $this->app->make(UserAccountService::class)->changeOwnPassword($user, 'my-new-long-password', 'current-device');

        $this->assertSame(['current-device'], DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all());
    }

    public function test_current_password_must_be_correct(): void
    {
        $user = $this->userWithRole(SystemRole::Instructor);

        $this->actingAs($user)
            ->put('/account/password', [
                'current_password' => 'not-my-password',
                'password' => 'my-new-long-password',
                'password_confirmation' => 'my-new-long-password',
            ])
            ->assertSessionHasErrors(['current_password' => 'The current password is incorrect.']);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_new_password_must_be_long_enough_confirmed_and_different(): void
    {
        $user = $this->userWithRole(SystemRole::Instructor);

        $this->actingAs($user)
            ->put('/account/password', [
                'current_password' => 'password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertSessionHasErrors('password');

        $this->actingAs($user)
            ->put('/account/password', [
                'current_password' => 'password',
                'password' => 'my-new-long-password',
                'password_confirmation' => 'something-else-entirely',
            ])
            ->assertSessionHasErrors(['password' => 'The password confirmation does not match.']);

        $this->actingAs($user)
            ->put('/account/password', [
                'current_password' => 'password',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrors('password');
    }

    public function test_candidates_cannot_use_the_staff_password_page(): void
    {
        $candidate = User::factory()->withRole(SystemRole::Candidate)->create();

        $this->actingAs($candidate)->get('/account/password')->assertForbidden();
        $this->actingAs($candidate)
            ->put('/account/password', [
                'current_password' => 'password',
                'password' => 'my-new-long-password',
                'password_confirmation' => 'my-new-long-password',
            ])
            ->assertForbidden();
    }

    private function storeSession(User $user, string $sessionId): void
    {
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'ip_address' => null,
            'user_agent' => null,
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
    }
}
