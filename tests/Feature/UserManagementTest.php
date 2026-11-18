<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->admin()->create(['name' => 'Owner', 'email' => 'info@arsus.nl']);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['name' => 'Anna Admin', 'email' => 'anna@example.test']);
    }

    public function test_the_hidden_account_is_not_listed_and_clients_are_left_out(): void
    {
        $this->owner();
        User::factory()->create(['email' => 'client@example.test']);
        User::factory()->employee()->create(['name' => 'Eva', 'email' => 'eva@example.test']);
        $this->actingAs($this->admin());

        $emails = array_column($this->getJson('/admin/api/users')->assertOk()->json(), 'email');

        $this->assertEqualsCanonicalizing(['anna@example.test', 'eva@example.test'], $emails);
    }

    public function test_the_hidden_account_cannot_be_reached_changed_or_taken(): void
    {
        $owner = $this->owner();
        $this->actingAs($this->admin());

        $this->putJson("/admin/api/users/{$owner->id}", ['name' => 'X', 'email' => 'x@example.test', 'role' => 'employee'])->assertNotFound();
        $this->deleteJson("/admin/api/users/{$owner->id}")->assertNotFound();
        $this->assertSame(Role::Admin, $owner->fresh()->role);

        // neither created nor reached by renaming someone else
        $this->postJson('/admin/api/users', ['name' => 'X', 'email' => 'INFO@arsus.nl', 'role' => 'admin', 'password' => 'secret-pass'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $other = User::factory()->employee()->create();
        $this->putJson("/admin/api/users/{$other->id}", ['name' => 'X', 'email' => 'info@arsus.nl', 'role' => 'employee'])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_an_admin_can_add_an_admin_and_an_employee_who_can_log_in(): void
    {
        $this->actingAs($this->admin());

        $this->postJson('/admin/api/users', ['name' => 'Nina', 'email' => 'nina@example.test', 'role' => 'admin', 'password' => 'secret-pass'])->assertCreated()->assertJsonPath('role', 'admin');
        $this->postJson('/admin/api/users', ['name' => 'Tom', 'email' => 'tom@example.test', 'role' => 'employee', 'password' => 'secret-pass'])->assertCreated()->assertJsonPath('role', 'employee');

        $this->assertTrue(User::where('email', 'tom@example.test')->first()->isEmployee());
        $this->assertTrue(Hash::check('secret-pass', User::where('email', 'tom@example.test')->value('password')));

        auth()->logout();
        $this->post('/login', ['email' => 'tom@example.test', 'password' => 'secret-pass'])->assertRedirect('/admin');
    }

    public function test_new_users_are_validated(): void
    {
        $this->actingAs($this->admin());
        $this->postJson('/admin/api/users', [])->assertJsonValidationErrors(['name', 'email', 'role', 'password']);
        $this->postJson('/admin/api/users', ['name' => 'A', 'email' => 'nope', 'role' => 'client', 'password' => 'short'])->assertJsonValidationErrors(['email', 'role', 'password']);
        $this->postJson('/admin/api/users', ['name' => 'A', 'email' => 'anna@example.test', 'role' => 'admin', 'password' => 'secret-pass'])->assertJsonValidationErrors('email');
        $this->assertSame(1, User::count());
    }

    public function test_users_can_be_edited_with_or_without_a_new_password(): void
    {
        $this->actingAs($this->admin());
        $user = User::factory()->employee()->create(['email' => 'tom@example.test', 'password' => 'old-password']);
        $hash = $user->password;

        $this->putJson("/admin/api/users/{$user->id}", ['name' => 'Tom B', 'email' => 'tom@example.test', 'role' => 'admin', 'password' => ''])->assertOk()->assertJsonPath('role', 'admin');
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame('Tom B', $user->fresh()->name);

        $this->putJson("/admin/api/users/{$user->id}", ['name' => 'Tom B', 'email' => 'tom@example.test', 'role' => 'admin', 'password' => 'brand-new-pass'])->assertOk();
        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));
    }

    public function test_you_cannot_change_your_own_role_or_delete_yourself_but_can_delete_others(): void
    {
        $me = $this->admin();
        $this->actingAs($me);

        $this->putJson("/admin/api/users/{$me->id}", ['name' => 'Anna', 'email' => $me->email, 'role' => 'employee'])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertSame(Role::Admin, $me->fresh()->role);
        $this->putJson("/admin/api/users/{$me->id}", ['name' => 'Anna B', 'email' => $me->email, 'role' => 'admin'])->assertOk();

        $this->deleteJson("/admin/api/users/{$me->id}")->assertUnprocessable();
        $other = User::factory()->employee()->create();
        $this->deleteJson("/admin/api/users/{$other->id}")->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $other->id]);
    }

    public function test_clients_cannot_be_managed_here_and_employees_have_no_access(): void
    {
        $client = User::factory()->create();
        $this->actingAs($this->admin())->deleteJson("/admin/api/users/{$client->id}")->assertNotFound();

        $this->actingAs(User::factory()->employee()->create());
        $this->getJson('/admin/api/users')->assertForbidden();
        $this->postJson('/admin/api/users', ['name' => 'A', 'email' => 'a@example.test', 'role' => 'admin', 'password' => 'secret-pass'])->assertForbidden();
    }

    public function test_the_profile_shows_who_you_are_and_changes_the_name(): void
    {
        $user = User::factory()->employee()->create(['name' => 'Eva']);
        $this->actingAs($user);

        $this->getJson('/admin/api/me')->assertOk()->assertJsonPath('name', 'Eva')->assertJsonPath('is_admin', false)->assertJsonPath('role', 'employee');
        $this->putJson('/admin/api/profile', ['name' => 'Eva de Boer'])->assertOk()->assertJsonPath('name', 'Eva de Boer');
        $this->putJson('/admin/api/profile', ['name' => ''])->assertJsonValidationErrors('name');
    }

    public function test_anyone_on_staff_changes_their_own_password_with_the_current_one(): void
    {
        $user = User::factory()->employee()->create(['password' => 'old-password']);
        $this->actingAs($user);
        $url = '/admin/api/profile/password';

        $this->putJson($url, ['current_password' => 'wrong', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])->assertJsonValidationErrors('current_password');
        $this->putJson($url, ['current_password' => 'old-password', 'password' => 'new-password-1', 'password_confirmation' => 'different'])->assertJsonValidationErrors('password');
        $this->putJson($url, ['current_password' => 'old-password', 'password' => 'short', 'password_confirmation' => 'short'])->assertJsonValidationErrors('password');
        $this->putJson($url, ['current_password' => 'old-password', 'password' => 'old-password', 'password_confirmation' => 'old-password'])->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));

        $this->putJson($url, ['current_password' => 'old-password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])->assertOk();
        $this->assertTrue(Hash::check('new-password-1', $user->fresh()->password));

        // the hidden account may change its own password and name too
        $owner = $this->owner();
        $this->actingAs($owner)->putJson($url, ['current_password' => 'password', 'password' => 'another-pass-1', 'password_confirmation' => 'another-pass-1'])->assertOk();
    }

    public function test_the_profile_api_needs_a_login(): void
    {
        $this->getJson('/admin/api/me')->assertUnauthorized();
        $this->putJson('/admin/api/profile/password', [])->assertUnauthorized();
    }
}
