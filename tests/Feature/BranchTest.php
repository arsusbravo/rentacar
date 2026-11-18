<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = ['name' => 'Rentacar Utrecht', 'city' => 'Utrecht', 'address' => 'Stationsplein 1', 'is_active' => true];

    public function test_seeder_creates_the_three_example_branches(): void
    {
        $this->seed(BranchSeeder::class);
        $this->seed(BranchSeeder::class); // idempotent

        $this->assertSame(['Amsterdam', 'Den Haag', 'Rotterdam'], Branch::orderBy('city')->pluck('city')->all());
    }

    public function test_public_list_only_shows_active_branches(): void
    {
        Branch::create($this->payload);
        Branch::create(['name' => 'Closed', 'city' => 'Nowhere', 'address' => 'x', 'is_active' => false]);

        $this->getJson('/api/branches')->assertOk()->assertJsonCount(1)->assertJsonPath('0.city', 'Utrecht');
    }

    public function test_staff_can_create_and_update_but_clients_cannot(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/admin/api/branches', $this->payload)->assertForbidden();

        $this->actingAs(User::factory()->employee()->create());
        $id = $this->postJson('/admin/api/branches', $this->payload)->assertCreated()->json('id');
        $this->putJson("/admin/api/branches/$id", ['city' => 'Utrecht', 'name' => 'Renamed', 'address' => 'x', 'is_active' => false])
            ->assertOk()->assertJsonPath('name', 'Renamed');
    }

    public function test_validation_errors_are_returned(): void
    {
        $this->actingAs(User::factory()->employee()->create())
            ->postJson('/admin/api/branches', ['name' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'city', 'address']);
    }

    public function test_only_admins_can_delete(): void
    {
        $branch = Branch::create($this->payload);

        $this->actingAs(User::factory()->employee()->create())->deleteJson("/admin/api/branches/{$branch->id}")->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->deleteJson("/admin/api/branches/{$branch->id}")->assertNoContent();
        $this->assertModelMissing($branch);
    }
}
