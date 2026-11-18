<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Models\VehicleClass;
use App\Models\VehicleClassRate;
use App\Pricing\QuoteBuilder;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Carbon::setTestNow('2030-01-01 09:00');
    }

    /** Petrol class: day price 50, per km 0.20, no own risk, base deposit 100 (so price + margin decides). */
    private function priceClass(array $attributes = [], array $rate = []): VehicleClass
    {
        $class = VehicleClass::factory()->named('Compact', 'Compact car')->create($attributes + ['deposit' => 100]);
        VehicleClassRate::create(['vehicle_class_id' => $class->id, 'fuel_type' => 'petrol', 'transmission' => 'manual'] + $rate + ['price_day' => 50, 'price_km' => 0.2]);

        return $class->fresh();
    }

    private function quote(VehicleClass $class, int $extraKm = 0, int $days = 3): array
    {
        $pickup = Carbon::parse('2030-01-02 10:00');

        return app(QuoteBuilder::class)->build($class, 'petrol', 'manual', $pickup, $pickup->copy()->addDays($days), [], false, $extraKm);
    }

    // --- included km ----------------------------------------------------------------------------------

    public function test_a_class_includes_250_km_by_default(): void
    {
        $this->assertSame(250, VehicleClass::factory()->create()->fresh()->km_included);
        $this->assertSame(250, $this->quote($this->priceClass())['km']['included']);
    }

    public function test_the_included_km_are_a_flat_amount_per_rental(): void
    {
        $class = $this->priceClass(['km_included' => 250]);

        // the same 250 km for 1 day and for 9 days
        $this->assertSame(250, $this->quote($class, days: 1)['km']['included']);
        $this->assertSame(250, $this->quote($class, days: 9)['km']['included']);
        $this->assertSame(250, $this->quote($class, days: 9)['km']['total']);
    }

    // --- booking extra km ------------------------------------------------------------------------------

    public function test_extra_km_are_priced_at_the_per_km_rate_and_added_to_the_total(): void
    {
        $class = $this->priceClass();

        $without = $this->quote($class);
        $with = $this->quote($class, 150);

        $this->assertSame(15000, $without['subtotal_cents']);                     // 3 days x 50
        $this->assertSame(0, $without['km']['extra']);
        $this->assertSame(0, $without['km']['extra_cents']);

        $this->assertSame(150, $with['km']['extra']);
        $this->assertSame(3000, $with['km']['extra_cents']);                       // 150 x 0.20
        $this->assertSame(400, $with['km']['total']);                              // 250 included + 150
        $this->assertSame(20, $with['km']['price_cents']);
        $this->assertTrue($with['km']['extra_available']);
        $this->assertSame(18000, $with['subtotal_cents']);                         // 150 + 30
        $this->assertSame(3780, $with['vat_cents']);                               // 21% of 180
        $this->assertSame(21780, $with['total_cents']);
        $this->assertSame(15000, $with['rental']['cents']);                        // the rental itself is unchanged
    }

    public function test_extra_km_raise_the_price_the_deposit_is_compared_with(): void
    {
        $class = $this->priceClass();

        $without = $this->quote($class);       // total 181.50 + 150 = 331.50 -> 350
        $with = $this->quote($class, 1000);    // (150 + 200) x 1.21 = 423.50 + 150 = 573.50 -> 600

        $this->assertSame(35000, $without['deposit']['cents']);
        $this->assertSame(60000, $with['deposit']['cents']);
        $this->assertGreaterThan($with['total_cents'], $with['deposit']['cents']);
    }

    public function test_the_amount_of_extra_km_is_capped_and_never_negative(): void
    {
        $class = $this->priceClass();
        config(['pricing.km.max_extra' => 500]);

        $this->assertSame(500, $this->quote($class, 99999)['km']['extra']);
        $this->assertSame(10000, $this->quote($class, 99999)['km']['extra_cents']);    // 500 x 0.20
        $this->assertSame(0, $this->quote($class, -50)['km']['extra']);
    }

    public function test_without_a_per_km_price_no_extra_km_can_be_booked(): void
    {
        $class = $this->priceClass(rate: ['price_km' => null]);

        $quote = $this->quote($class, 200);

        $this->assertFalse($quote['km']['extra_available']);
        $this->assertSame(0, $quote['km']['extra']);
        $this->assertSame(0, $quote['km']['extra_cents']);
        $this->assertNull($quote['km']['price_cents']);
        $this->assertSame(15000, $quote['subtotal_cents']);
    }

    public function test_a_class_can_include_no_km_at_all(): void
    {
        $quote = $this->quote($this->priceClass(['km_included' => 0]), 100);

        $this->assertSame(0, $quote['km']['included']);
        $this->assertSame(100, $quote['km']['total']);           // only what was booked
        $this->assertSame(2000, $quote['km']['extra_cents']);
    }

    // --- the quote endpoint ------------------------------------------------------------------------------

    private function quotePayload(VehicleClass $class, array $overrides = []): array
    {
        return array_merge([
            'class_id' => $class->id, 'fuel_type' => 'petrol', 'transmission' => 'manual',
            'pickup_date' => '2030-01-02', 'pickup_time' => '10:00', 'return_date' => '2030-01-05', 'return_time' => '10:00',
        ], $overrides);
    }

    public function test_the_quote_endpoint_takes_extra_km_as_an_optional_field(): void
    {
        $class = $this->priceClass();

        $this->postJson('/api/price-quote', $this->quotePayload($class))->assertOk()->assertJsonPath('km.extra', 0)->assertJsonPath('km.included', 250)->assertJsonPath('total_cents', 18150);
        $this->postJson('/api/price-quote', $this->quotePayload($class, ['extra_km' => null]))->assertOk()->assertJsonPath('km.extra', 0);
        $this->postJson('/api/price-quote', $this->quotePayload($class, ['extra_km' => 150]))->assertOk()
            ->assertJsonPath('km.extra', 150)->assertJsonPath('km.extra_cents', 3000)->assertJsonPath('km.total', 400)->assertJsonPath('subtotal_cents', 18000)->assertJsonPath('total_cents', 21780);
    }

    public function test_extra_km_are_validated(): void
    {
        $class = $this->priceClass();

        $this->postJson('/api/price-quote', $this->quotePayload($class, ['extra_km' => -1]))->assertJsonValidationErrors('extra_km');
        $this->postJson('/api/price-quote', $this->quotePayload($class, ['extra_km' => 'veel']))->assertJsonValidationErrors('extra_km');
        $this->postJson('/api/price-quote', $this->quotePayload($class, ['extra_km' => 12.5]))->assertJsonValidationErrors('extra_km');
        $this->postJson('/api/price-quote', $this->quotePayload($class, ['extra_km' => config('pricing.km.max_extra') + 1]))->assertJsonValidationErrors('extra_km');
        $this->postJson('/api/price-quote', $this->quotePayload($class, ['extra_km' => config('pricing.km.max_extra')]))->assertOk();
    }

    public function test_extra_km_without_a_per_km_price_are_refused_not_ignored(): void
    {
        $class = $this->priceClass(rate: ['price_km' => null]);

        $this->postJson('/api/price-quote', $this->quotePayload($class, ['extra_km' => 100]))
            ->assertUnprocessable()->assertJsonPath('message', 'Voor dit voertuig kunnen geen extra kilometers worden bijgeboekt.')->assertJsonValidationErrors('extra_km');

        // zero extra km is fine, the quote says that extra km are not available
        $this->postJson('/api/price-quote', $this->quotePayload($class))->assertOk()->assertJsonPath('km.extra_available', false)->assertJsonPath('km.price_cents', null);
    }

    // --- the demo order ---------------------------------------------------------------------------------------

    private function orderPayload(VehicleClass $class, array $overrides = []): array
    {
        $branch = Branch::first()->id;

        return array_merge($this->quotePayload($class), [
            'pickup_branch_id' => $branch, 'return_branch_id' => $branch,
            'first_name' => 'Jan', 'last_name' => 'Berg', 'date_of_birth' => '1990-05-17', 'street' => 'Weg', 'house_number' => '1', 'postal_code' => '3013 AJ', 'city' => 'Rotterdam',
            'has_valid_license' => true, 'accept_terms' => true,
        ], $overrides);
    }

    public function test_the_demo_order_contains_the_extra_km(): void
    {
        $this->seed(BranchSeeder::class);
        $class = $this->priceClass();

        $this->postJson('/api/order-preview', $this->orderPayload($class, ['extra_km' => 150]))->assertOk()
            ->assertJsonPath('quote.km.extra', 150)->assertJsonPath('quote.km.extra_cents', 3000)->assertJsonPath('quote.total_cents', 21780);

        $this->postJson('/api/order-preview', $this->orderPayload($class))->assertOk()->assertJsonPath('quote.km.extra', 0);
        $this->postJson('/api/order-preview', $this->orderPayload($class, ['extra_km' => -5]))->assertJsonValidationErrors('extra_km');

        $none = $this->priceClass(['slug' => 'none'], ['price_km' => null]);
        $this->postJson('/api/order-preview', $this->orderPayload($none, ['extra_km' => 100]))->assertUnprocessable()->assertJsonValidationErrors('extra_km');
    }

    // --- admin ---------------------------------------------------------------------------------------------------

    private function staff(): static
    {
        return $this->actingAs(User::factory()->employee()->create());
    }

    private function ratesPayload(array $overrides = []): array
    {
        return array_merge(['rates' => [['fuel_type' => 'petrol', 'transmission' => 'manual', 'price_day' => 50]], 'own_risk' => null, 'own_risk_reduced' => null, 'own_risk_reduction_price' => null, 'deposit' => null], $overrides);
    }

    public function test_staff_can_set_the_included_km_per_class(): void
    {
        $class = VehicleClass::factory()->create();
        $url = "/admin/api/classes/{$class->id}/rates";

        $this->staff()->putJson($url, $this->ratesPayload(['km_included' => 400]))->assertOk()->assertJsonPath('km_included', 400);
        $this->assertSame(400, $class->fresh()->km_included);

        $this->putJson($url, $this->ratesPayload(['km_included' => 0]))->assertOk()->assertJsonPath('km_included', 0);
        $this->assertSame(0, $class->fresh()->km_included);

        $this->putJson($url, $this->ratesPayload(['km_included' => null]))->assertOk();      // empty = none included
        $this->assertSame(0, $class->fresh()->km_included);
    }

    public function test_saving_prices_without_the_km_field_leaves_the_included_km_alone(): void
    {
        $class = VehicleClass::factory()->create(['km_included' => 300]);

        $this->staff()->putJson("/admin/api/classes/{$class->id}/rates", $this->ratesPayload())->assertOk()->assertJsonPath('km_included', 300);
        $this->assertSame(300, $class->fresh()->km_included);
    }

    public function test_the_included_km_are_validated(): void
    {
        $class = VehicleClass::factory()->create();
        $url = "/admin/api/classes/{$class->id}/rates";
        $this->staff();

        $this->putJson($url, $this->ratesPayload(['km_included' => -1]))->assertJsonValidationErrors('km_included');
        $this->putJson($url, $this->ratesPayload(['km_included' => 100000]))->assertJsonValidationErrors('km_included');
        $this->putJson($url, $this->ratesPayload(['km_included' => 12.5]))->assertJsonValidationErrors('km_included');
        $this->putJson($url, $this->ratesPayload(['km_included' => 'veel']))->assertJsonValidationErrors('km_included');
        $this->assertSame(250, $class->fresh()->km_included);

        $this->actingAs(User::factory()->create())->putJson($url, $this->ratesPayload(['km_included' => 100]))->assertForbidden();
    }

    public function test_the_price_overview_lists_the_included_km(): void
    {
        VehicleClass::factory()->named('SUV', 'SUV')->create(['km_included' => 500]);

        $this->staff()->getJson('/admin/api/rates')->assertOk()->assertJsonPath('data.0.classes.0.km_included', 500);
    }

    // --- public pages ------------------------------------------------------------------------------------------------

    public function test_the_checkout_gets_the_included_km_and_the_maximum(): void
    {
        $category = VehicleCategory::factory()->create();
        $class = VehicleClass::factory()->named('Compact', 'Compact')->create(['vehicle_category_id' => $category->id, 'km_included' => 300]);

        $this->get('/rent?class='.$class->id)->assertOk()
            ->assertSee('&quot;km_included&quot;:300', false)->assertSee('&quot;km_extra_max&quot;:'.config('pricing.km.max_extra'), false);
    }

    public function test_the_vehicle_cards_show_the_included_km(): void
    {
        $category = VehicleCategory::factory()->named('Personenauto', 'Passenger car')->create(['slug' => 'passenger-car']);
        VehicleClass::factory()->named('Compact', 'Compact')->create(['vehicle_category_id' => $category->id, 'example_models' => 'VW Polo', 'km_included' => 250]);
        VehicleClass::factory()->named('Mini', 'Mini')->create(['vehicle_category_id' => $category->id, 'example_models' => 'Toyota Aygo', 'km_included' => 0, 'slug' => 'mini']);

        $this->get('/vehicles?category=passenger-car')->assertOk()->assertSee('Inbegrepen km')->assertSee('250 km')->assertSee('Geen');
        $this->withUnencryptedCookie('locale', 'en')->get('/vehicles?category=passenger-car')->assertSee('Included km')->assertSee('250 km')->assertSee('None');
    }

    public function test_the_homepage_explains_the_kilometres(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Een aantal kilometers zit in de huurprijs, per huur (bijvoorbeeld 250 km).')
            ->assertSee('Boek dan in het overzicht van je boeking extra kilometers bij');

        $this->withUnencryptedCookie('locale', 'en')->get('/')
            ->assertSee('A number of kilometres is included in the rental price, per rental (for example 250 km).')
            ->assertSee('Add extra kilometres in the booking overview');
    }
}
