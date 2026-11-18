<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VehicleClass;
use App\Models\VehicleClassRate;
use App\Pricing\DepositCalculator;
use App\Pricing\QuoteBuilder;
use Database\Seeders\DepositSeeder;
use Database\Seeders\VehicleCategorySeeder;
use Database\Seeders\VehicleClassSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DepositTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function deposit(int $base, int $price, bool $keepsOwnRisk = false, int $hours = 216): array
    {
        return DepositCalculator::calculate($base, $price, $keepsOwnRisk, $hours);
    }

    // --- the formula --------------------------------------------------------------------------------

    public function test_the_price_plus_margin_decides_when_it_is_higher_than_the_base(): void
    {
        // base 500, price 435.60 -> 585.60 -> rounded up to 600
        $result = $this->deposit(50000, 43560);

        $this->assertSame(60000, $result['cents']);
        $this->assertSame('price', $result['basis']);
        $this->assertSame(0, $result['surcharge_cents']);
        $this->assertTrue($result['applicable']);
        $this->assertNull($result['reason']);
    }

    public function test_the_base_deposit_decides_when_it_is_high_enough(): void
    {
        $result = $this->deposit(80000, 43560);

        $this->assertSame(80000, $result['cents']);
        $this->assertSame('base', $result['basis']);
    }

    public function test_a_tie_goes_to_the_price(): void
    {
        $this->assertSame('price', $this->deposit(30000, 15000)['basis']);   // price + 150 = 300 = base
    }

    public function test_keeping_the_full_own_risk_adds_the_surcharge_to_the_rounded_amount(): void
    {
        $this->assertSame(60000, $this->deposit(50000, 43560, false)['cents']);
        $this->assertSame(85000, $this->deposit(50000, 43560, true)['cents']);   // 600 + 250
        $this->assertSame(25000, $this->deposit(50000, 43560, true)['surcharge_cents']);
        $this->assertSame(105000, $this->deposit(80000, 43560, true)['cents']);  // base 800 + 250
    }

    public function test_the_example_of_a_cheap_short_rental(): void
    {
        // 3 days, price 339.41 incl. VAT, base 300: price + 150 = 489.41 -> 500; + 250 when the own risk is kept
        $this->assertSame(75000, $this->deposit(30000, 33941, true, 72)['cents']);
        // lowering the own risk costs 36 (+VAT) more rent but saves the surcharge: 382.97 + 150 -> 550
        $this->assertSame(55000, $this->deposit(30000, 38297, false, 72)['cents']);
    }

    public function test_the_amount_is_rounded_up_to_a_multiple_of_50_euro(): void
    {
        $this->assertSame(55000, $this->deposit(55000, 0)['cents']);          // exactly on a multiple: unchanged
        $this->assertSame(60000, $this->deposit(55001, 0)['cents']);          // one cent over: next step
        $this->assertSame(20000, $this->deposit(0, 100)['cents']);            // price 1.00 + 150 = 151 -> 200
        $this->assertSame(20000, $this->deposit(0, 5000)['cents']);           // 50 + 150 = 200 exactly
        $this->assertSame(25000, $this->deposit(0, 5001)['cents']);
    }

    public function test_without_a_base_it_is_the_price_plus_the_margin(): void
    {
        $this->assertSame(15000, $this->deposit(0, 0)['cents']);              // nothing to pay: just the margin, 150 is a whole step
        $this->assertSame(20000, $this->deposit(0, 4000)['cents']);           // 40 + 150 = 190 -> 200
        $this->assertSame(15000, $this->deposit(-500, 0)['cents']);           // a negative base counts as none
    }

    public function test_the_deposit_is_always_above_the_price_and_a_whole_step_plus_the_surcharge(): void
    {
        mt_srand(20261118);

        for ($i = 0; $i < 3000; $i++) {
            [$base, $price, $keeps] = [mt_rand(0, 300000), mt_rand(0, 2000000), (bool) mt_rand(0, 1)];
            $result = $this->deposit($base, $price, $keeps, mt_rand(1, 719));
            $surcharge = $keeps ? 25000 : 0;
            $core = $result['cents'] - $surcharge;

            $this->assertGreaterThan($price, $result['cents'], "price $price");
            $this->assertGreaterThanOrEqual($price + 15000, $core);
            $this->assertGreaterThanOrEqual($base, $core);
            $this->assertSame(0, $core % 5000);
            $this->assertLessThan(max($base, $price + 15000) + 5000, $core); // never more than one step over
            $this->assertSame($surcharge, $result['surcharge_cents']);
        }
    }

    public function test_a_rental_of_a_month_or_longer_is_leasing_without_a_deposit(): void
    {
        $this->assertTrue($this->deposit(50000, 20000, true, 719)['applicable']);       // 29 days 23 hours
        $leasing = $this->deposit(50000, 20000, true, 720);                             // 30 days
        $this->assertFalse($leasing['applicable']);
        $this->assertNull($leasing['cents']);
        $this->assertSame('leasing', $leasing['reason']);
        $this->assertSame(0, $leasing['surcharge_cents']);
        $this->assertSame(30, $leasing['leasing_from_days']);
        $this->assertFalse($this->deposit(50000, 20000, false, 24 * 365)['applicable']);
    }

    public function test_margin_rounding_surcharge_and_the_leasing_threshold_are_configurable(): void
    {
        config(['pricing.deposit.margin' => 200, 'pricing.deposit.round_to' => 100, 'pricing.deposit.own_risk_surcharge' => 100, 'pricing.deposit.leasing_from_days' => 14]);

        $this->assertSame(70000, $this->deposit(0, 43560)['cents']);                    // 435.60 + 200 = 635.60 -> 700
        $this->assertSame(80000, $this->deposit(0, 43560, true)['cents']);              // + 100
        $this->assertTrue($this->deposit(0, 100, false, 14 * 24 - 1)['applicable']);
        $this->assertFalse($this->deposit(0, 100, false, 14 * 24)['applicable']);
    }

    // --- in the quote ----------------------------------------------------------------------------------

    /** Petrol/manual class with prices, an own risk (750) that can be lowered to 250, and a base deposit of 500. */
    private function pricedClass(array $attributes = []): VehicleClass
    {
        $class = VehicleClass::factory()->named('Compact', 'Compact car')->create($attributes + [
            'own_risk' => 750, 'own_risk_reduced' => 250, 'own_risk_reduction_price' => 8, 'deposit' => 500,
        ]);
        VehicleClassRate::create(['vehicle_class_id' => $class->id, 'fuel_type' => 'petrol', 'transmission' => 'manual', 'price_day' => 50, 'price_week' => 250]);

        return $class->fresh();
    }

    private function quote(VehicleClass $class, bool $reduce = false, int $days = 9, array $extras = []): array
    {
        $pickup = Carbon::parse('2030-01-02 10:00');

        return app(QuoteBuilder::class)->build($class, 'petrol', 'manual', $pickup, $pickup->copy()->addDays($days), $extras, $reduce);
    }

    public function test_the_quote_has_a_deposit_that_is_not_part_of_the_total(): void
    {
        $quote = $this->quote($this->pricedClass());

        // 9 days = 350 excl., 423.50 incl.: price + 150 = 573.50 -> 600, + 250 because the full own risk (750) is kept
        $this->assertSame(42350, $quote['total_cents']);
        $this->assertSame(85000, $quote['deposit']['cents']);
        $this->assertSame('price', $quote['deposit']['basis']);
        $this->assertSame(25000, $quote['deposit']['surcharge_cents']);

        // the base deposit changes the deposit, never the amounts the client pays
        $other = $this->quote($this->pricedClass(['deposit' => 2000, 'slug' => 'other']));
        $this->assertSame(225000, $other['deposit']['cents']);        // base 2000 + 250
        $this->assertSame('base', $other['deposit']['basis']);
        $this->assertSame(35000, $other['subtotal_cents']);
        $this->assertSame(7350, $other['vat_cents']);
        $this->assertSame(42350, $other['total_cents']);
    }

    public function test_lowering_the_own_risk_removes_the_surcharge(): void
    {
        $class = $this->pricedClass();

        $kept = $this->quote($class);
        $lowered = $this->quote($class, reduce: true);

        $this->assertSame(85000, $kept['deposit']['cents']);
        // price (350 + 72) x 1.21 = 510.62 + 150 = 660.62 -> 700, no surcharge
        $this->assertSame(51062, $lowered['total_cents']);
        $this->assertSame(70000, $lowered['deposit']['cents']);
        $this->assertSame(0, $lowered['deposit']['surcharge_cents']);
        $this->assertLessThan($kept['deposit']['cents'], $lowered['deposit']['cents']);   // although the client pays more rent
    }

    public function test_the_deposit_is_compared_with_the_price_including_vat_and_extras(): void
    {
        $class = $this->pricedClass(['own_risk' => null, 'own_risk_reduced' => null, 'own_risk_reduction_price' => null, 'deposit' => 100]);

        $quote = $this->quote($class);                       // total 423.50 incl.: + 150 = 573.50 -> 600
        $this->assertSame(60000, $quote['deposit']['cents']);
        $this->assertSame('price', $quote['deposit']['basis']);

        $extra = \App\Models\Extra::create(['slug' => 'tow', 'price' => 100, 'price_unit' => 'per_time', 'max_quantity' => 1, 'sort_order' => 0, 'is_active' => true]);
        $extra->classes()->sync([$class->id]);
        $with = $this->quote($class, extras: [['id' => $extra->id]]);   // (350 + 100) x 1.21 = 544.50 + 150 = 694.50 -> 700
        $this->assertSame(54450, $with['total_cents']);
        $this->assertSame(70000, $with['deposit']['cents']);
        $this->assertGreaterThan($with['total_cents'], $with['deposit']['cents']);
    }

    public function test_a_class_without_own_risk_gets_no_surcharge(): void
    {
        $quote = $this->quote($this->pricedClass(['own_risk' => null, 'own_risk_reduced' => null, 'own_risk_reduction_price' => null, 'deposit' => null]));

        $this->assertSame(60000, $quote['deposit']['cents']);        // 423.50 + 150 = 573.50 -> 600
        $this->assertSame(0, $quote['deposit']['surcharge_cents']);

        $zero = $this->quote($this->pricedClass(['own_risk' => 0, 'own_risk_reduced' => null, 'own_risk_reduction_price' => null, 'slug' => 'zero']));
        $this->assertSame(0, $zero['deposit']['surcharge_cents']);
    }

    public function test_the_surcharge_applies_when_the_own_risk_cannot_be_lowered(): void
    {
        // an own risk but no reduction on offer: the client keeps the full own risk
        $quote = $this->quote($this->pricedClass(['own_risk_reduced' => null, 'own_risk_reduction_price' => null]), reduce: true);

        $this->assertFalse($quote['own_risk']['reduction_chosen']);
        $this->assertSame(85000, $quote['deposit']['cents']);
        $this->assertSame(25000, $quote['deposit']['surcharge_cents']);
    }

    public function test_long_rentals_need_no_deposit_but_are_still_priced(): void
    {
        $class = $this->pricedClass();
        VehicleClassRate::where('vehicle_class_id', $class->id)->update(['price_month' => 900]);

        $month = $this->quote($class->fresh(), days: 30);
        $this->assertSame(90000, $month['rental']['cents']);          // priced with the month tariff
        $this->assertFalse($month['deposit']['applicable']);
        $this->assertNull($month['deposit']['cents']);
        $this->assertSame('leasing', $month['deposit']['reason']);

        $this->assertTrue($this->quote($class->fresh(), days: 29)['deposit']['applicable']);
    }

    public function test_the_api_endpoints_return_the_deposit(): void
    {
        $this->seed(\Database\Seeders\BranchSeeder::class);
        Carbon::setTestNow('2030-01-01 09:00');
        $class = $this->pricedClass();
        $branch = \App\Models\Branch::first()->id;

        $quote = $this->postJson('/api/price-quote', [
            'class_id' => $class->id, 'fuel_type' => 'petrol', 'transmission' => 'manual',
            'pickup_date' => '2030-01-02', 'pickup_time' => '10:00', 'return_date' => '2030-01-11', 'return_time' => '10:00',
        ])->assertOk()->assertJsonPath('deposit.cents', 85000)->assertJsonPath('deposit.applicable', true)->assertJsonPath('total_cents', 42350);

        $this->postJson('/api/order-preview', [
            'pickup_branch_id' => $branch, 'return_branch_id' => $branch, 'class_id' => $class->id, 'fuel_type' => 'petrol', 'transmission' => 'manual',
            'pickup_date' => '2030-01-02', 'pickup_time' => '10:00', 'return_date' => '2030-01-11', 'return_time' => '10:00',
            'first_name' => 'Jan', 'last_name' => 'Berg', 'date_of_birth' => '1990-05-17', 'street' => 'Weg', 'house_number' => '1', 'postal_code' => '3013 AJ', 'city' => 'Rotterdam',
            'has_valid_license' => true, 'accept_terms' => true,
        ])->assertOk()->assertJsonPath('quote.deposit.cents', 85000);

        $this->postJson('/api/price-quote', [
            'class_id' => $class->id, 'fuel_type' => 'petrol', 'transmission' => 'manual',
            'pickup_date' => '2030-01-02', 'pickup_time' => '10:00', 'return_date' => '2030-02-05', 'return_time' => '10:00',
        ])->assertOk()->assertJsonPath('deposit.applicable', false)->assertJsonPath('deposit.reason', 'leasing')->assertJsonPath('deposit.leasing_from_days', 30);
    }

    // --- admin -------------------------------------------------------------------------------------------

    private function staff(): static
    {
        return $this->actingAs(User::factory()->employee()->create());
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'rates' => [['fuel_type' => 'petrol', 'transmission' => 'manual', 'price_day' => 50]],
            'own_risk' => 1000, 'own_risk_reduced' => 250, 'own_risk_reduction_price' => 9, 'deposit' => 600,
        ], $overrides);
    }

    public function test_staff_can_save_clear_and_validate_the_base_deposit(): void
    {
        $class = VehicleClass::factory()->create();
        $url = "/admin/api/classes/{$class->id}/rates";

        $this->staff()->putJson($url, $this->payload())->assertOk()->assertJsonPath('deposit', 600);
        $this->assertSame('600.00', $class->fresh()->deposit);
        $this->assertSame(60000, $class->fresh()->depositCents());

        $this->putJson($url, $this->payload(['deposit' => 749.5]))->assertOk()->assertJsonPath('deposit', 749.5);
        $this->putJson($url, $this->payload(['deposit' => null]))->assertOk()->assertJsonPath('deposit', null);
        $this->assertNull($class->fresh()->deposit);

        $this->putJson($url, $this->payload(['deposit' => -1]))->assertJsonValidationErrors('deposit');
        $this->putJson($url, $this->payload(['deposit' => 'veel']))->assertJsonValidationErrors('deposit');
        $this->putJson($url, $this->payload(['deposit' => 100000]))->assertJsonValidationErrors('deposit');
    }

    public function test_the_price_index_lists_the_deposit_and_the_formula_settings(): void
    {
        $class = VehicleClass::factory()->named('SUV', 'SUV')->create(['deposit' => 800]);

        $this->staff()->getJson('/admin/api/rates')->assertOk()
            ->assertJsonPath('data.0.classes.0.deposit', 800)
            ->assertJsonPath('deposit_margin', 150)->assertJsonPath('deposit_round_to', 50)->assertJsonPath('deposit_leasing_days', 30);

        $this->actingAs(User::factory()->create())->putJson("/admin/api/classes/{$class->id}/rates", $this->payload())->assertForbidden();
    }

    // --- seeder ------------------------------------------------------------------------------------------

    public function test_the_deposit_seeder_only_fills_classes_without_a_deposit(): void
    {
        $this->seed([VehicleCategorySeeder::class, VehicleClassSeeder::class]);
        VehicleClass::where('slug', 'suv')->update(['deposit' => 1234.5]);   // entered by hand
        VehicleClass::where('slug', 'mini')->update(['deposit' => 0]);       // an explicit 0 is a value too

        $this->seed(DepositSeeder::class);
        $this->seed(DepositSeeder::class);

        $deposit = fn (string $slug) => VehicleClass::where('slug', $slug)->value('deposit');
        $this->assertSame('1234.50', $deposit('suv'));       // not overwritten
        $this->assertSame('0.00', $deposit('mini'));         // not overwritten
        $this->assertSame('150.00', $deposit('compact'));    // filled
        $this->assertSame('500.00', $deposit('box-truck'));
        $this->assertSame(0, VehicleClass::whereNull('deposit')->count());
    }
}
