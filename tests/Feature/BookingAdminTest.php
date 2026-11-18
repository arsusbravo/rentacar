<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\BranchSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\ExtraSeeder;
use Database\Seeders\RateSeeder;
use Database\Seeders\VehicleCategorySeeder;
use Database\Seeders\VehicleClassSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BookingAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function demo(): void
    {
        $this->seed([BranchSeeder::class, VehicleCategorySeeder::class, VehicleClassSeeder::class, ExtraSeeder::class, RateSeeder::class, DemoDataSeeder::class]);
    }

    private function staff(): static
    {
        return $this->actingAs(User::factory()->employee()->create());
    }

    public function test_the_demo_seeder_makes_clients_and_bookings_in_every_status_and_runs_once(): void
    {
        $this->demo();

        $this->assertSame(24, Customer::count());
        $this->assertGreaterThan(20, Booking::count());
        foreach (BookingStatus::cases() as $status) {
            $this->assertGreaterThan(0, Booking::where('status', $status)->count(), $status->value);
        }
        // the money adds up and matches the extra lines
        Booking::with('extras')->each(function (Booking $b) {
            $this->assertSame($b->subtotal_cents + $b->vat_cents, $b->total_cents);
            $this->assertSame($b->rental_cents + $b->own_risk_reduction_cents + $b->extras->sum('cents') + $b->extra_km_cents, $b->subtotal_cents);
            $this->assertTrue($b->return_at->gt($b->pickup_at));
        });

        $count = Booking::count();
        $this->seed(DemoDataSeeder::class);
        $this->assertSame($count, Booking::count());
        $this->assertSame(24, Customer::count());
    }

    public function test_only_staff_can_use_the_booking_and_customer_api(): void
    {
        $this->getJson('/admin/api/bookings')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/admin/api/bookings')->assertForbidden();
        $this->getJson('/admin/api/customers')->assertForbidden();
    }

    public function test_bookings_are_listed_filtered_searched_and_counted(): void
    {
        $this->demo();
        $this->staff();

        $all = $this->getJson('/admin/api/bookings')->assertOk();
        $this->assertSame(Booking::count(), $all->json('meta.total'));
        $this->assertCount(15, $all->json('data'));
        $this->assertSame('unconfirmed', $all->json('data.0.status'));                       // what needs attention comes first
        $this->assertSame(Booking::where('status', 'confirmed')->count(), $all->json('counts.confirmed'));

        $unconfirmed = $this->getJson('/admin/api/bookings?status=unconfirmed')->assertOk();
        $this->assertSame(Booking::where('status', 'unconfirmed')->count(), $unconfirmed->json('meta.total'));
        $this->assertSame([], array_diff(array_column($unconfirmed->json('data'), 'status'), ['unconfirmed']));

        $first = Booking::with('customer')->first();
        $found = $this->getJson('/admin/api/bookings?q='.$first->reference)->assertOk();
        $this->assertSame([$first->reference], array_column($found->json('data'), 'reference'));
        $byName = $this->getJson('/admin/api/bookings?q='.urlencode($first->customer->last_name))->assertOk();
        $this->assertContains($first->reference, array_column($byName->json('data'), 'reference'));
        $this->getJson('/admin/api/bookings?customer='.$first->customer_id)->assertOk()->assertJsonPath('meta.total', Booking::where('customer_id', $first->customer_id)->count());

        $this->getJson('/admin/api/bookings?status=bogus')->assertUnprocessable();
        $this->getJson('/admin/api/bookings?page=2')->assertOk()->assertJsonPath('meta.page', 2);
    }

    public function test_the_list_carries_everything_the_detail_needs(): void
    {
        $this->demo();
        $this->staff();

        $b = $this->getJson('/admin/api/bookings')->json('data.0');
        foreach (['reference', 'status', 'customer', 'vehicle', 'pickup', 'return', 'extras', 'rental_cents', 'subtotal_cents', 'vat_cents', 'total_cents', 'deposit_cents', 'created_at'] as $key) {
            $this->assertArrayHasKey($key, $b);
        }
        $this->assertNotEmpty($b['vehicle']['fuel_label']);
        $this->assertNotEmpty($b['pickup']['branch']);
    }

    public function test_an_unconfirmed_booking_can_be_confirmed_once(): void
    {
        $this->demo();
        $this->staff();
        $booking = Booking::where('status', 'unconfirmed')->first();

        $this->postJson("/admin/api/bookings/{$booking->id}/confirm")->assertOk()->assertJsonPath('status', 'confirmed');
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertNotNull($booking->fresh()->confirmed_at);

        $this->postJson("/admin/api/bookings/{$booking->id}/confirm")->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_a_booking_can_be_cancelled_but_a_cancelled_one_cannot_be_confirmed(): void
    {
        $this->demo();
        $this->staff();
        $booking = Booking::where('status', 'confirmed')->first();

        $this->postJson("/admin/api/bookings/{$booking->id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertNotNull($booking->fresh()->cancelled_at);
        $this->postJson("/admin/api/bookings/{$booking->id}/cancel")->assertUnprocessable();
        $this->postJson("/admin/api/bookings/{$booking->id}/confirm")->assertUnprocessable();
    }

    public function test_customers_can_be_searched_created_updated_and_validated(): void
    {
        $this->demo();
        $this->staff();

        $this->getJson('/admin/api/customers?q=Bakker')->assertOk()->assertJsonPath('data.0.last_name', 'Bakker');
        $this->assertSame(24, $this->getJson('/admin/api/customers')->json('meta.total'));
        $this->assertSame(15, count($this->getJson('/admin/api/customers')->json('data')));

        $id = $this->postJson('/admin/api/customers', ['first_name' => 'Els', 'last_name' => 'van Gelder', 'email' => 'els@example.test', 'postal_code' => '3013 AJ', 'city' => 'Rotterdam'])
            ->assertCreated()->assertJsonPath('full_name', 'Els van Gelder')->json('id');
        $this->putJson("/admin/api/customers/{$id}", ['first_name' => 'Els', 'last_name' => 'van Gelder', 'phone' => '06 12345678'])->assertOk()->assertJsonPath('phone', '06 12345678');

        $this->postJson('/admin/api/customers', [])->assertJsonValidationErrors(['first_name', 'last_name']);
        $this->postJson('/admin/api/customers', ['first_name' => 'A1', 'last_name' => 'B', 'email' => 'nope', 'postal_code' => '0000 XX', 'date_of_birth' => '2999-01-01'])
            ->assertJsonValidationErrors(['first_name', 'email', 'postal_code', 'date_of_birth']);
    }

    public function test_only_admins_delete_customers_and_never_one_with_bookings(): void
    {
        $this->demo();
        $withBookings = Booking::first()->customer;
        $free = Customer::factory()->create();

        $this->staff()->deleteJson("/admin/api/customers/{$free->id}")->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $this->deleteJson("/admin/api/customers/{$withBookings->id}")->assertUnprocessable()->assertJsonValidationErrors('customer');
        $this->assertDatabaseHas('customers', ['id' => $withBookings->id]);
        $this->deleteJson("/admin/api/customers/{$free->id}")->assertNoContent();
        $this->assertDatabaseMissing('customers', ['id' => $free->id]);
    }

    public function test_the_reference_counts_up(): void
    {
        $this->assertSame('RC-'.now()->year.'-0001', Booking::nextReference());
    }
}
