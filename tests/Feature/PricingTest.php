<?php

namespace Tests\Feature;

use App\Models\Extra;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Models\VehicleClass;
use App\Models\VehicleClassRate;
use App\Pricing\QuoteBuilder;
use App\Pricing\RentalPriceCalculator;
use Database\Seeders\ExtraSeeder;
use Database\Seeders\RateSeeder;
use Database\Seeders\VehicleCategorySeeder;
use Database\Seeders\VehicleClassSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function rate(array $prices): VehicleClassRate
    {
        return new VehicleClassRate($prices);
    }

    /** Cents for a rental of $hours hours with these prices; null when nothing can be priced. */
    private function price(array $prices, int|float $hours): ?array
    {
        $pickup = Carbon::parse('2030-01-01 10:00');

        return RentalPriceCalculator::calculate($this->rate($prices), $pickup, $pickup->copy()->addMinutes((int) round($hours * 60)));
    }

    private function cents(array $prices, int|float $hours): ?int
    {
        return $this->price($prices, $hours)['cents'] ?? null;
    }

    // --- cheapest combination ---------------------------------------------------------------------------

    public function test_nine_days_use_a_week_plus_two_days_when_that_is_cheaper(): void
    {
        $result = $this->price(['price_day' => 50, 'price_week' => 250], 9 * 24);

        $this->assertSame(35000, $result['cents']);
        $this->assertSame([['unit' => 'week', 'count' => 1, 'cents' => 25000], ['unit' => 'day', 'count' => 2, 'cents' => 10000]], $result['breakdown']);
    }

    public function test_the_day_price_is_used_when_a_week_would_cost_more(): void
    {
        $result = $this->price(['price_day' => 50, 'price_week' => 400], 9 * 24);

        $this->assertSame(45000, $result['cents']);
        $this->assertSame([['unit' => 'day', 'count' => 9, 'cents' => 45000]], $result['breakdown']);
    }

    public function test_a_week_that_costs_the_same_as_seven_days_is_one_week(): void
    {
        $this->assertSame([['unit' => 'week', 'count' => 1, 'cents' => 35000]], $this->price(['price_day' => 50, 'price_week' => 350], 7 * 24)['breakdown']);
    }

    public function test_half_day_and_day(): void
    {
        $prices = ['price_half_day' => 30, 'price_day' => 50];

        $this->assertSame(3000, $this->cents($prices, 4));    // half day
        $this->assertSame(3000, $this->cents($prices, 6));    // a half day covers up to 6 hours
        $this->assertSame(5000, $this->cents($prices, 8));    // a day beats two half days (60)
        $this->assertSame(5000, $this->cents($prices, 24));
        $this->assertSame(8000, $this->cents($prices, 24 + 5)); // a day and a half day
        $this->assertSame(10000, $this->cents($prices, 24 + 7)); // two days beat a day plus two half days
    }

    public function test_started_hours_count(): void
    {
        $prices = ['price_day' => 50];

        $this->assertSame(5000, $this->cents($prices, 24));
        $this->assertSame(10000, $this->cents($prices, 24 + 1 / 60)); // one minute over 24 hours is a second day
    }

    public function test_month_and_year(): void
    {
        $prices = ['price_day' => 50, 'price_week' => 250, 'price_month' => 900, 'price_year' => 9000];

        $this->assertSame(90000, $this->cents($prices, 30 * 24));          // one month, not 4 weeks + 2 days (1100)
        $this->assertSame(95000, $this->cents($prices, 31 * 24));          // month + day
        $this->assertSame(900000, $this->cents($prices, 365 * 24));        // one year
        $this->assertSame(900000, $this->cents($prices, 300 * 24));        // 10 months cost more than the year
    }

    public function test_a_single_tariff_covers_the_whole_rental(): void
    {
        $this->assertSame(900000, $this->cents(['price_year' => 9000], 10 * 24));
        $this->assertSame(3000, $this->cents(['price_half_day' => 30], 5));
        $this->assertSame(6000, $this->cents(['price_half_day' => 30], 8)); // two half days
    }

    public function test_without_a_time_tariff_nothing_can_be_priced(): void
    {
        $this->assertNull($this->price([], 10));
        $this->assertNull($this->price(['price_km' => 0.25], 10));
    }

    public function test_a_rental_is_at_least_one_hour_and_never_negative(): void
    {
        $pickup = Carbon::parse('2030-01-01 10:00');
        $rate = $this->rate(['price_half_day' => 30, 'price_day' => 50]);

        $this->assertSame(3000, RentalPriceCalculator::calculate($rate, $pickup, $pickup->copy())['cents']);
        $this->assertSame(3000, RentalPriceCalculator::calculate($rate, $pickup, $pickup->copy()->subDay())['cents']);
        $this->assertSame(1, RentalPriceCalculator::hours($pickup, $pickup->copy()));
    }

    public function test_the_longest_rental_is_priced_quickly(): void
    {
        $started = microtime(true);
        $result = $this->price(['price_half_day' => 30, 'price_day' => 50, 'price_week' => 250, 'price_month' => 900, 'price_year' => 9000], config('pricing.max_rental_days') * 24);

        $this->assertSame(5 * 900000, $result['cents']); // five years
        $this->assertLessThan(2.0, microtime(true) - $started);
    }

    // --- quote ----------------------------------------------------------------------------------------

    /** A class with petrol/diesel x manual/automatic, priced, with an own-risk reduction. */
    private function pricedClass(): VehicleClass
    {
        $class = VehicleClass::factory()->named('Compact', 'Compact')->withFuelTypes(['petrol', 'diesel'])->withTransmissions(['manual', 'automatic'])
            ->create(['own_risk' => 750, 'own_risk_reduced' => 250, 'own_risk_reduction_price' => 8]);

        foreach (['petrol' => 50, 'diesel' => 55] as $fuel => $day) {
            foreach (['manual' => 0, 'automatic' => 5] as $transmission => $extra) {
                VehicleClassRate::create([
                    'vehicle_class_id' => $class->id, 'fuel_type' => $fuel, 'transmission' => $transmission,
                    'price_half_day' => 30, 'price_day' => $day + $extra, 'price_week' => ($day + $extra) * 5, 'price_km' => 0.2,
                ]);
            }
        }

        return $class->fresh();
    }

    private function quote(VehicleClass $class, string $fuel = 'petrol', string $transmission = 'manual', array $extras = [], bool $reduce = false, int $days = 9): ?array
    {
        $pickup = Carbon::parse('2030-01-02 10:00');

        return app(QuoteBuilder::class)->build($class, $fuel, $transmission, $pickup, $pickup->copy()->addDays($days), $extras, $reduce);
    }

    public function test_quote_adds_vat_to_the_rental_price(): void
    {
        $quote = $this->quote($this->pricedClass());

        // 9 days: 1 week (250) + 2 days (100) = 350
        $this->assertSame(35000, $quote['rental']['cents']);
        $this->assertSame(35000, $quote['subtotal_cents']);
        $this->assertSame(21.0, $quote['vat_rate']);
        $this->assertSame(7350, $quote['vat_cents']);
        $this->assertSame(42350, $quote['total_cents']);
        $this->assertSame(20, $quote['km']['price_cents']);
        $this->assertSame(250, $quote['km']['included']);
        $this->assertSame(9, $quote['days']);
    }

    public function test_the_price_depends_on_fuel_and_transmission(): void
    {
        $class = $this->pricedClass();

        // 9 days = 1 week + 2 days
        $this->assertSame(35000, $this->quote($class, 'petrol', 'manual')['rental']['cents']);     // 250 + 2 x 50
        $this->assertSame(38500, $this->quote($class, 'petrol', 'automatic')['rental']['cents']);  // 275 + 2 x 55
        $this->assertSame(38500, $this->quote($class, 'diesel', 'manual')['rental']['cents']);     // 275 + 2 x 55
        $this->assertSame(42000, $this->quote($class, 'diesel', 'automatic')['rental']['cents']);  // 300 + 2 x 60
    }

    public function test_vat_is_rounded_to_whole_cents(): void
    {
        $class = VehicleClass::factory()->create();
        VehicleClassRate::create(['vehicle_class_id' => $class->id, 'fuel_type' => 'petrol', 'transmission' => 'manual', 'price_day' => 10.05]);

        $quote = $this->quote($class, days: 1);

        $this->assertSame(1005, $quote['subtotal_cents']);
        $this->assertSame(211, $quote['vat_cents']); // 211.05
        $this->assertSame(1216, $quote['total_cents']);
    }

    public function test_the_own_risk_reduction_is_priced_per_day_and_only_when_chosen_and_offered(): void
    {
        $class = $this->pricedClass();

        $without = $this->quote($class, reduce: false);
        $this->assertFalse($without['own_risk']['reduction_chosen']);
        $this->assertNull($without['own_risk']['reduction_cents']);
        $this->assertSame(75000, $without['own_risk']['cents']);
        $this->assertTrue($without['own_risk']['reduction_offered']);

        $with = $this->quote($class, reduce: true);
        $this->assertSame(7200, $with['own_risk']['reduction_cents']);      // 8 per day x 9 days
        $this->assertSame(35000 + 7200, $with['subtotal_cents']);
        $this->assertSame(25000, $with['own_risk']['reduced_cents']);

        $class->update(['own_risk_reduced' => null, 'own_risk_reduction_price' => null]);
        $notOffered = $this->quote($class->fresh(), reduce: true);
        $this->assertFalse($notOffered['own_risk']['reduction_offered']);
        $this->assertFalse($notOffered['own_risk']['reduction_chosen']);
        $this->assertSame(35000, $notOffered['subtotal_cents']);
    }

    public function test_extras_are_added_but_only_coupled_active_ones_and_capped(): void
    {
        $class = $this->pricedClass();
        $make = function (string $slug, float $price, string $unit, array $classes, array $more = []): Extra {
            $extra = Extra::create($more + ['slug' => $slug, 'price' => $price, 'price_unit' => $unit, 'max_quantity' => 1, 'sort_order' => 0, 'is_active' => true]);
            $extra->classes()->sync($classes);

            return $extra;
        };

        $winter = $make('winter', 6, 'per_day', [$class->id]);
        $tow = $make('tow', 30, 'per_time', [$class->id]);
        $seat = $make('seat', 12.5, 'per_unit', [$class->id], ['max_quantity' => 3]);
        $other = $make('other', 99, 'per_time', []);                                    // coupled to no class
        $off = $make('off', 99, 'per_time', [$class->id], ['is_active' => false]);     // inactive

        $quote = $this->quote($class, extras: [
            ['id' => $winter->id], ['id' => $tow->id, 'quantity' => 5], ['id' => $seat->id, 'quantity' => 9],
            ['id' => $other->id], ['id' => $off->id], ['id' => 99999],
        ]);

        $lines = collect($quote['extras'])->keyBy('id');
        $this->assertCount(3, $lines);
        $this->assertSame(5400, $lines[$winter->id]['cents']);   // 6 x 9 days
        $this->assertSame(3000, $lines[$tow->id]['cents']);      // once, quantity ignored
        $this->assertSame(3, $lines[$seat->id]['quantity']);     // capped at max_quantity
        $this->assertSame(3750, $lines[$seat->id]['cents']);     // 12.50 x 3

        $this->assertSame(35000 + 5400 + 3000 + 3750, $quote['subtotal_cents']);
        $this->assertSame((int) round($quote['subtotal_cents'] * 0.21), $quote['vat_cents']);
    }

    public function test_no_quote_without_a_price_or_for_a_combination_the_class_does_not_offer(): void
    {
        $class = $this->pricedClass();
        $unpriced = VehicleClass::factory()->create();

        $this->assertNull($this->quote($unpriced));
        $this->assertNull($this->quote($class, 'electric', 'manual'));      // fuel not offered
        $this->assertNull($this->quote($class, 'petrol', 'cvt'));           // transmission not offered

        VehicleClassRate::where('fuel_type', 'diesel')->where('transmission', 'automatic')->delete();
        $this->assertNull($this->quote($class->fresh(), 'diesel', 'automatic')); // offered, but no price entered
    }

    // --- price sets follow the class options ------------------------------------------------------------

    public function test_price_sets_of_combinations_the_class_no_longer_offers_are_removed(): void
    {
        $class = $this->pricedClass();
        $this->assertSame(4, $class->rates()->count());

        $class->setTransmissions(['manual']);     // automatic goes away
        $this->assertSame(2, $class->rates()->count());
        $this->assertEmpty($class->rates()->where('transmission', 'automatic')->get());

        $class->setFuelTypes(['petrol']);         // diesel goes away
        $this->assertSame(1, $class->fresh()->rates()->count());
        $this->assertSame('petrol', $class->rates()->first()->fuel_type);

        // adding an option adds a combination without a price (nothing is invented)
        $class->setFuelTypes(['petrol', 'electric']);
        $this->assertCount(2, $class->fresh()->combinations());
        $this->assertSame(1, $class->rates()->count());
    }

    public function test_the_admin_class_form_prunes_prices_too(): void
    {
        $class = $this->pricedClass();
        $category = $class->category;

        $this->actingAs(User::factory()->employee()->create())->putJson("/admin/api/classes/{$class->id}", [
            'vehicle_category_id' => $category->id, 'names' => ['nl' => 'Compact'], 'example_models' => 'x',
            'fuel_types' => ['petrol'], 'transmissions' => ['manual'], 'license_category' => 'B', 'seats' => 5,
        ])->assertOk();

        $this->assertSame(1, $class->rates()->count());
    }

    public function test_deleting_a_class_removes_its_prices(): void
    {
        $class = $this->pricedClass();
        $class->delete();

        $this->assertSame(0, VehicleClassRate::count());
    }

    public function test_the_lowest_day_price_over_the_combinations_is_the_from_price(): void
    {
        $class = $this->pricedClass();

        $this->assertSame(5000, $class->fromDayPriceCents());   // petrol manual
        $this->assertNull(VehicleClass::factory()->create()->fromDayPriceCents());

        // a rate for a combination that is not offered does not count
        VehicleClassRate::create(['vehicle_class_id' => $class->id, 'fuel_type' => 'lpg', 'transmission' => 'manual', 'price_day' => 1]);
        $this->assertSame(5000, $class->fresh()->fromDayPriceCents());
    }

    // --- admin API -------------------------------------------------------------------------------------

    private function staff(): static
    {
        return $this->actingAs(User::factory()->employee()->create());
    }

    private function ratePayload(array $overrides = []): array
    {
        return array_merge([
            'rates' => [
                ['fuel_type' => 'petrol', 'transmission' => 'manual', 'price_half_day' => 30, 'price_day' => 50, 'price_week' => 300, 'price_month' => null, 'price_year' => null, 'price_km' => 0.2],
                ['fuel_type' => 'petrol', 'transmission' => 'automatic', 'price_half_day' => null, 'price_day' => 55, 'price_week' => null, 'price_month' => null, 'price_year' => null, 'price_km' => null],
                ['fuel_type' => 'diesel', 'transmission' => 'manual', 'price_half_day' => null, 'price_day' => null, 'price_week' => null, 'price_month' => null, 'price_year' => null, 'price_km' => null],
            ],
            'own_risk' => 1000, 'own_risk_reduced' => 250, 'own_risk_reduction_price' => 9,
        ], $overrides);
    }

    private function emptyClass(): VehicleClass
    {
        return VehicleClass::factory()->named('SUV', 'SUV')->withFuelTypes(['petrol', 'diesel'])->withTransmissions(['manual', 'automatic'])->create();
    }

    public function test_staff_can_save_prices_and_own_risk(): void
    {
        $class = $this->emptyClass();

        $this->staff()->putJson("/admin/api/classes/{$class->id}/rates", $this->ratePayload())->assertOk()
            ->assertJsonPath('own_risk', 1000)->assertJsonPath('own_risk_reduced', 250)->assertJsonPath('own_risk_reduction_price', 9)
            ->assertJsonPath('from_day_price', 50)
            ->assertJsonCount(4, 'combinations');

        $this->assertSame(2, $class->rates()->count());                        // the empty diesel row is not stored
        $this->assertSame('300.00', $class->rates()->where('transmission', 'manual')->first()->price_week);
        $this->assertSame('1000.00', $class->fresh()->own_risk);
    }

    public function test_saving_again_updates_and_an_emptied_row_is_deleted(): void
    {
        $class = $this->emptyClass();
        $this->staff()->putJson("/admin/api/classes/{$class->id}/rates", $this->ratePayload())->assertOk();

        $payload = $this->ratePayload();
        $payload['rates'][0]['price_day'] = 60;
        $payload['rates'][1] = ['fuel_type' => 'petrol', 'transmission' => 'automatic'] + array_fill_keys(VehicleClassRate::PRICES, null);
        $this->putJson("/admin/api/classes/{$class->id}/rates", $payload)->assertOk()->assertJsonPath('from_day_price', 60);

        $this->assertSame(1, $class->rates()->count());
        $this->assertSame('60.00', $class->rates()->first()->price_day);
    }

    public function test_index_lists_classes_with_their_combinations_by_category(): void
    {
        $class = $this->emptyClass();
        $this->staff()->putJson("/admin/api/classes/{$class->id}/rates", $this->ratePayload())->assertOk();

        $response = $this->getJson('/admin/api/rates')->assertOk()->assertJsonPath('vat_rate', 21)->assertJsonCount(1, 'data');
        $combos = collect($response->json('data.0.classes.0.combinations'));

        $this->assertCount(4, $combos);
        $this->assertSame(['Benzine', 'Handgeschakeld', true], [$combos[0]['fuel_label'], $combos[0]['transmission_label'], $combos[0]['priced']]);
        $this->assertSame(50, $combos->firstWhere(fn ($c) => $c['fuel_type'] === 'petrol' && $c['transmission'] === 'manual')['price_day']);
        $this->assertFalse($combos->firstWhere(fn ($c) => $c['fuel_type'] === 'diesel' && $c['transmission'] === 'automatic')['priced']);
    }

    public function test_validation(): void
    {
        $class = $this->emptyClass();
        $this->staff();
        $url = "/admin/api/classes/{$class->id}/rates";

        $this->putJson($url, $this->ratePayload(['rates' => [['fuel_type' => 'petrol', 'transmission' => 'manual', 'price_day' => -5]]]))->assertJsonValidationErrors('rates.0.price_day');
        $this->putJson($url, $this->ratePayload(['rates' => [['fuel_type' => 'petrol', 'transmission' => 'manual', 'price_day' => 'veel']]]))->assertJsonValidationErrors('rates.0.price_day');
        $this->putJson($url, $this->ratePayload(['rates' => [['fuel_type' => 'electric', 'transmission' => 'manual', 'price_day' => 40]]]))->assertJsonValidationErrors('rates.0.fuel_type');   // not offered
        $this->putJson($url, $this->ratePayload(['rates' => [['fuel_type' => 'petrol', 'transmission' => 'manual', 'price_km' => 0.2]]]))->assertJsonValidationErrors('rates.0.price_day');          // only per km
        $this->putJson($url, $this->ratePayload(['own_risk_reduced' => 250, 'own_risk_reduction_price' => null]))->assertJsonValidationErrors('own_risk_reduced');                                  // needs both
        $this->putJson($url, $this->ratePayload(['own_risk' => 500, 'own_risk_reduced' => 600, 'own_risk_reduction_price' => 5]))->assertJsonValidationErrors('own_risk_reduced');                  // lower than the own risk

        $this->assertSame(0, VehicleClassRate::count());
    }

    public function test_a_reduction_to_zero_own_risk_is_allowed_and_the_reduction_can_be_left_out(): void
    {
        $class = $this->emptyClass();
        $this->staff();
        $url = "/admin/api/classes/{$class->id}/rates";

        $this->putJson($url, $this->ratePayload(['own_risk_reduced' => 0, 'own_risk_reduction_price' => 12]))->assertOk()->assertJsonPath('own_risk_reduced', 0);
        $this->assertTrue($class->fresh()->ownRiskReductionOffered());

        $this->putJson($url, $this->ratePayload(['own_risk_reduced' => null, 'own_risk_reduction_price' => null]))->assertOk()->assertJsonPath('own_risk_reduced', null);
        $this->assertFalse($class->fresh()->ownRiskReductionOffered());
    }

    public function test_only_staff_can_manage_prices(): void
    {
        $class = $this->emptyClass();

        $this->getJson('/admin/api/rates')->assertUnauthorized();
        $this->putJson("/admin/api/classes/{$class->id}/rates", $this->ratePayload())->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/admin/api/rates')->assertForbidden();
        $this->actingAs(User::factory()->create())->putJson("/admin/api/classes/{$class->id}/rates", $this->ratePayload())->assertForbidden();
    }

    // --- public: "from" price and the quote endpoint ------------------------------------------------------

    public function test_the_vehicles_page_shows_the_from_price_or_price_on_request(): void
    {
        $category = VehicleCategory::factory()->named('Personenauto', 'Passenger car')->create(['slug' => 'passenger-car']);
        $priced = VehicleClass::factory()->named('Compact', 'Compact')->withFuelTypes(['petrol', 'diesel'])->create(['vehicle_category_id' => $category->id, 'example_models' => 'VW Polo']);
        VehicleClassRate::create(['vehicle_class_id' => $priced->id, 'fuel_type' => 'petrol', 'transmission' => 'manual', 'price_day' => 35]);
        VehicleClassRate::create(['vehicle_class_id' => $priced->id, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'price_day' => 41.5]);
        VehicleClass::factory()->named('Mini', 'Mini')->create(['vehicle_category_id' => $category->id, 'example_models' => 'Toyota Aygo']);

        $this->get('/vehicles?category=passenger-car')->assertOk()
            ->assertSee('Vanaf')->assertSee('€ 35,00')->assertSee('per dag · excl. BTW')
            ->assertDontSee('€ 41,50')                       // the dearer fuel is not the "from" price
            ->assertSee('Prijs op aanvraag');                // the class without prices

        $this->withUnencryptedCookie('locale', 'en')->get('/vehicles?category=passenger-car')
            ->assertSee('From')->assertSee('€ 35.00')->assertSee('per day · excl. VAT')->assertSee('Price on request');
    }

    private function quotePayload(VehicleClass $class, array $overrides = []): array
    {
        return array_merge([
            'class_id' => $class->id, 'fuel_type' => 'petrol', 'transmission' => 'manual',
            'pickup_date' => '2030-01-02', 'pickup_time' => '10:00', 'return_date' => '2030-01-11', 'return_time' => '10:00',
            'extras' => [], 'reduce_own_risk' => false,
        ], $overrides);
    }

    public function test_the_quote_endpoint_returns_the_price_build_up(): void
    {
        $class = $this->pricedClass();

        $this->postJson('/api/price-quote', $this->quotePayload($class))->assertOk()
            ->assertJsonPath('rental.cents', 35000)->assertJsonPath('rental.breakdown.0.unit', 'week')
            ->assertJsonPath('subtotal_cents', 35000)->assertJsonPath('vat_cents', 7350)->assertJsonPath('total_cents', 42350)
            ->assertJsonPath('own_risk.reduction_offered', true)->assertJsonPath('days', 9);

        $this->postJson('/api/price-quote', $this->quotePayload($class, ['reduce_own_risk' => true]))->assertOk()
            ->assertJsonPath('own_risk.reduction_cents', 7200)->assertJsonPath('subtotal_cents', 42200);
    }

    public function test_the_quote_endpoint_rejects_what_cannot_be_priced(): void
    {
        $class = $this->pricedClass();

        $this->postJson('/api/price-quote', $this->quotePayload(VehicleClass::factory()->create()))
            ->assertUnprocessable()->assertJsonPath('message', 'Voor dit voertuig is nog geen prijs beschikbaar.');
        $this->postJson('/api/price-quote', $this->quotePayload($class, ['fuel_type' => 'electric']))
            ->assertUnprocessable()->assertJsonPath('message', 'Dit voertuig wordt niet met deze brandstof en versnellingsbak aangeboden.');
        $this->postJson('/api/price-quote', $this->quotePayload($class, ['return_date' => '2030-01-02', 'return_time' => '09:00']))->assertJsonValidationErrors('return_time');
        $this->postJson('/api/price-quote', $this->quotePayload($class, ['return_date' => '2040-01-02']))->assertJsonValidationErrors('return_date'); // longer than the maximum
        $this->postJson('/api/price-quote', $this->quotePayload($class, ['class_id' => 999]))->assertJsonValidationErrors('class_id');
        $this->postJson('/api/price-quote', $this->quotePayload($class, ['extras' => [['quantity' => 2]]]))->assertJsonValidationErrors('extras.0.id');

        $class->update(['is_active' => false]);
        $this->postJson('/api/price-quote', $this->quotePayload($class))->assertJsonValidationErrors('class_id');
    }

    public function test_the_period_request_refuses_rentals_longer_than_the_maximum(): void
    {
        $this->seed(\Database\Seeders\BranchSeeder::class);
        Carbon::setTestNow('2030-01-01 09:00');
        $branch = \App\Models\Branch::first()->id;

        $payload = ['pickup_branch_id' => $branch, 'return_branch_id' => $branch, 'pickup_date' => '2030-01-02', 'pickup_time' => '10:00', 'return_date' => '2040-01-02', 'return_time' => '10:00'];

        $this->postJson('/api/rental-period/validate', $payload)->assertJsonValidationErrors('return_date');
        $this->postJson('/api/rental-period/validate', ['return_date' => '2030-02-02'] + $payload)->assertNoContent();
    }

    // --- seeder ----------------------------------------------------------------------------------------

    public function test_the_rate_seeder_prices_every_combination_and_is_idempotent(): void
    {
        $this->seed([VehicleCategorySeeder::class, VehicleClassSeeder::class, ExtraSeeder::class]);
        $combinations = VehicleClass::all()->sum(fn (VehicleClass $c) => count($c->combinations()));

        $this->seed(RateSeeder::class);
        $this->seed(RateSeeder::class);

        $this->assertSame($combinations, VehicleClassRate::count());
        $this->assertSame(0, VehicleClass::whereNull('own_risk')->count());
        $this->assertSame(12, VehicleClass::whereNotNull('own_risk_reduction_price')->count());

        // every offered combination can be priced, with every tariff
        foreach (VehicleClass::with(['fuelOptions', 'transmissionOptions', 'rates'])->get() as $class) {
            foreach ($class->combinations() as $combination) {
                $rate = $class->rateFor($combination['fuel_type'], $combination['transmission']);
                $this->assertNotNull($rate, "{$class->slug} {$combination['fuel_type']} {$combination['transmission']}");
                $this->assertTrue($rate->isPriced());
                $this->assertGreaterThan(0, (float) $rate->price_km);
                $this->assertEqualsWithDelta((float) $rate->price_day * 6, (float) $rate->price_week, 0.001);
            }
        }

        // diesel and automatic cost more than petrol and manual
        $compact = VehicleClass::where('slug', 'compact')->with('rates')->first();
        $dayPrice = fn (string $fuel, string $transmission) => (float) $compact->rateFor($fuel, $transmission)->price_day;
        $this->assertGreaterThan($dayPrice('petrol', 'manual'), $dayPrice('diesel', 'manual'));
        $this->assertGreaterThan($dayPrice('petrol', 'manual'), $dayPrice('petrol', 'automatic'));
    }
}
