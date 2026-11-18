<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RentalPeriodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BranchSeeder::class);
        Carbon::setTestNow('2030-01-01 09:00'); // a Tuesday
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'pickup_branch_id' => Branch::where('city', 'Rotterdam')->value('id'),
            'return_branch_id' => Branch::where('city', 'Amsterdam')->value('id'), // drop anywhere
            'pickup_date' => '2030-01-02', // Wednesday
            'pickup_time' => '10:00',
            'return_date' => '2030-01-03',
            'return_time' => '23:30', // returns are 24h
        ], $overrides);
    }

    public function test_valid_period_with_other_return_branch_and_late_return(): void
    {
        $this->postJson('/api/rental-period/validate', $this->payload())->assertNoContent();
    }

    public function test_the_optional_vehicle_type_must_be_an_active_category(): void
    {
        $category = \App\Models\VehicleCategory::factory()->create(['slug' => 'van']);

        $this->postJson('/api/rental-period/validate', $this->payload(['category' => 'van']))->assertNoContent();
        $this->postJson('/api/rental-period/validate', $this->payload(['category' => null]))->assertNoContent();
        $this->postJson('/api/rental-period/validate', $this->payload(['category' => 'nope']))->assertJsonValidationErrors('category');

        $category->update(['is_active' => false]);
        $this->postJson('/api/rental-period/validate', $this->payload(['category' => 'van']))->assertJsonValidationErrors('category');
    }

    public function test_weekend_pickup_is_rejected(): void
    {
        $this->postJson('/api/rental-period/validate', $this->payload(['pickup_date' => '2030-01-05', 'return_date' => '2030-01-07']))
            ->assertJsonValidationErrors('pickup_date');
    }

    public function test_weekend_return_is_allowed(): void
    {
        $this->postJson('/api/rental-period/validate', $this->payload(['pickup_date' => '2030-01-04', 'return_date' => '2030-01-06']))
            ->assertNoContent();
    }

    public function test_pickup_time_must_be_within_opening_hours(): void
    {
        $this->postJson('/api/rental-period/validate', $this->payload(['pickup_time' => '18:30']))->assertJsonValidationErrors('pickup_time');
        $this->postJson('/api/rental-period/validate', $this->payload(['pickup_time' => '07:30']))->assertJsonValidationErrors('pickup_time');
        $this->postJson('/api/rental-period/validate', $this->payload(['pickup_time' => '18:00']))->assertNoContent();
    }

    public function test_return_cannot_be_before_pickup(): void
    {
        $this->postJson('/api/rental-period/validate', $this->payload(['return_date' => '2030-01-01']))->assertJsonValidationErrors('return_date');
        $this->postJson('/api/rental-period/validate', $this->payload(['return_date' => '2030-01-02', 'return_time' => '09:00']))->assertJsonValidationErrors('return_time');
    }

    public function test_pickup_cannot_be_in_the_past(): void
    {
        $this->postJson('/api/rental-period/validate', $this->payload(['pickup_date' => '2029-12-31', 'return_date' => '2030-01-02']))
            ->assertJsonValidationErrors('pickup_date');
        $this->postJson('/api/rental-period/validate', $this->payload(['pickup_date' => '2030-01-01', 'pickup_time' => '08:30']))
            ->assertJsonValidationErrors('pickup_time');
    }

    public function test_inactive_branch_is_rejected(): void
    {
        $b = Branch::where('city', 'Amsterdam')->first();
        $b->update(['is_active' => false]);

        $this->postJson('/api/rental-period/validate', $this->payload())->assertJsonValidationErrors('return_branch_id');
    }

    public function test_public_branches_include_opening_hours(): void
    {
        $this->getJson('/api/branches')->assertOk()->assertJsonCount(7, '0.opening_hours')
            ->assertJsonPath('0.opening_hours.0.opens_at', '08:00')->assertJsonPath('0.opening_hours.5.is_closed', true);
    }

    public function test_staff_can_update_opening_hours(): void
    {
        $branch = Branch::first();
        $hours = $branch->openingHours->map(fn ($h) => [
            'weekday' => $h->weekday, 'is_closed' => $h->weekday === 7 ? true : false,
            'opens_at' => '09:00', 'closes_at' => '17:00',
        ])->all();

        $this->actingAs(User::factory()->employee()->create())
            ->putJson("/admin/api/branches/{$branch->id}", ['name' => $branch->name, 'city' => $branch->city, 'address' => $branch->address, 'opening_hours' => $hours])
            ->assertOk();

        $branch->refresh()->load('openingHours');
        $this->assertSame('09:00', $branch->hoursFor(6)->opens_at); // Saturday now open
        $this->assertTrue($branch->hoursFor(7)->is_closed);
    }

    public function test_closing_time_must_be_after_opening_time(): void
    {
        $branch = Branch::first();
        $hours = collect(range(1, 7))->map(fn ($d) => ['weekday' => $d, 'is_closed' => false, 'opens_at' => '18:00', 'closes_at' => '08:00'])->all();

        $this->actingAs(User::factory()->employee()->create())
            ->putJson("/admin/api/branches/{$branch->id}", ['name' => 'x', 'city' => 'x', 'address' => 'x', 'opening_hours' => $hours])
            ->assertJsonValidationErrors('opening_hours.0.closes_at');
    }
}
