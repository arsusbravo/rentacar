<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Extra;
use App\Models\VehicleClass;
use App\Models\VehicleClassRate;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrderPreviewTest extends TestCase
{
    use RefreshDatabase;

    private VehicleClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Carbon::setTestNow('2030-01-01 09:00'); // a Tuesday
        $this->seed(BranchSeeder::class);

        $this->class = VehicleClass::factory()->named('Compact', 'Compact car')
            ->withFuelTypes(['petrol', 'diesel'])->withTransmissions(['manual', 'automatic'])
            ->create(['example_models' => 'VW Polo', 'own_risk' => 750, 'own_risk_reduced' => 250, 'own_risk_reduction_price' => 8]);

        VehicleClassRate::create([
            'vehicle_class_id' => $this->class->id, 'fuel_type' => 'petrol', 'transmission' => 'manual',
            'price_day' => 50, 'price_week' => 250, 'price_km' => 0.2,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        $branch = Branch::where('city', 'Rotterdam')->value('id');

        return array_merge([
            'pickup_branch_id' => $branch, 'return_branch_id' => Branch::where('city', 'Amsterdam')->value('id'),
            'pickup_date' => '2030-01-02', 'pickup_time' => '10:00', 'return_date' => '2030-01-11', 'return_time' => '10:00',
            'class_id' => $this->class->id, 'fuel_type' => 'petrol', 'transmission' => 'manual',
            'extras' => [], 'reduce_own_risk' => false,
            'first_name' => 'Jan', 'last_name' => 'van der Berg', 'date_of_birth' => '1990-05-17',
            'street' => 'Stationsplein', 'house_number' => '12a', 'postal_code' => '3013aj', 'city' => 'Rotterdam',
            'has_valid_license' => true, 'accept_terms' => true,
        ], $overrides);
    }

    private function order(array $overrides = [])
    {
        return $this->postJson('/api/order-preview', $this->payload($overrides));
    }

    // --- a valid order ------------------------------------------------------------------------------

    public function test_a_valid_checkout_returns_the_order_details_and_books_nothing(): void
    {
        $this->order()->assertOk()
            ->assertJsonPath('demo', true)
            ->assertJsonPath('client.first_name', 'Jan')->assertJsonPath('client.last_name', 'van der Berg')
            ->assertJsonPath('client.date_of_birth', '1990-05-17')
            ->assertJsonPath('client.street', 'Stationsplein')->assertJsonPath('client.house_number', '12a')
            ->assertJsonPath('client.postal_code', '3013 AJ')           // normalised
            ->assertJsonPath('client.city', 'Rotterdam')
            ->assertJsonPath('vehicle.name', 'Compact')->assertJsonPath('vehicle.example_models', 'VW Polo')
            ->assertJsonPath('vehicle.fuel_label', 'Benzine')->assertJsonPath('vehicle.transmission_label', 'Handgeschakeld')
            ->assertJsonPath('pickup.branch', 'Rentacar Rotterdam')->assertJsonPath('pickup.at', '2030-01-02 10:00')
            ->assertJsonPath('return.branch', 'Rentacar Amsterdam')->assertJsonPath('return.at', '2030-01-11 10:00')
            // 9 days: 1 week (250) + 2 days (100) = 350, 21% VAT
            ->assertJsonPath('quote.rental.cents', 35000)->assertJsonPath('quote.subtotal_cents', 35000)
            ->assertJsonPath('quote.vat_cents', 7350)->assertJsonPath('quote.total_cents', 42350);
    }

    public function test_extras_and_the_own_risk_reduction_are_part_of_the_order(): void
    {
        $extra = Extra::create(['slug' => 'winter', 'price' => 6, 'price_unit' => 'per_day', 'max_quantity' => 1, 'sort_order' => 0, 'is_active' => true]);
        $extra->setTranslation('nl', ['title' => 'Winterbanden']);
        $extra->classes()->sync([$this->class->id]);

        $this->order(['extras' => [['id' => $extra->id]], 'reduce_own_risk' => true])->assertOk()
            ->assertJsonPath('quote.extras.0.title', 'Winterbanden')->assertJsonPath('quote.extras.0.cents', 5400)
            ->assertJsonPath('quote.own_risk.reduction_cents', 7200)
            ->assertJsonPath('quote.subtotal_cents', 35000 + 5400 + 7200);
    }

    public function test_names_and_addresses_are_trimmed_and_a_postal_code_with_a_space_is_fine(): void
    {
        $this->order(['first_name' => '  Jean-Luc ', 'last_name' => "O'Neil-Çelik", 'street' => ' Kerkstraat ', 'postal_code' => '1012 ab', 'city' => " 's-Hertogenbosch "])->assertOk()
            ->assertJsonPath('client.first_name', 'Jean-Luc')->assertJsonPath('client.last_name', "O'Neil-Çelik")
            ->assertJsonPath('client.street', 'Kerkstraat')->assertJsonPath('client.postal_code', '1012 AB')->assertJsonPath('client.city', "'s-Hertogenbosch");
    }

    // --- client details -------------------------------------------------------------------------------

    public function test_every_client_field_and_both_checkboxes_are_required(): void
    {
        $this->postJson('/api/order-preview', array_diff_key($this->payload(), array_flip([
            'first_name', 'last_name', 'date_of_birth', 'street', 'house_number', 'postal_code', 'city', 'has_valid_license', 'accept_terms',
        ])))->assertJsonValidationErrors([
            'first_name', 'last_name', 'date_of_birth', 'street', 'house_number', 'postal_code', 'city', 'has_valid_license', 'accept_terms',
        ]);
    }

    public function test_the_checkboxes_must_be_ticked_with_clear_messages(): void
    {
        $this->order(['has_valid_license' => false, 'accept_terms' => false])
            ->assertJsonValidationErrors(['has_valid_license', 'accept_terms'])
            ->assertJsonPath('errors.has_valid_license.0', 'Je moet bevestigen dat je een geldig rijbewijs hebt.')
            ->assertJsonPath('errors.accept_terms.0', 'Je moet akkoord gaan met de algemene voorwaarden.');

        app()->setLocale('en');
        $this->order(['accept_terms' => false])->assertJsonPath('errors.accept_terms.0', 'You must accept the terms and conditions.');
    }

    public function test_names_cannot_contain_digits_or_symbols(): void
    {
        $this->order(['first_name' => 'Jan2'])->assertJsonValidationErrors('first_name');
        $this->order(['last_name' => 'Berg <b>'])->assertJsonValidationErrors('last_name');
        $this->order(['city' => 'Rotterdam 1'])->assertJsonValidationErrors('city');
        $this->order(['first_name' => str_repeat('a', 101)])->assertJsonValidationErrors('first_name');
    }

    public function test_postal_code_and_house_number_formats(): void
    {
        foreach (['0123 AB', '123 AB', '1234 A', '1234 ABC', 'ABCD 12', '12345'] as $bad) {
            $this->order(['postal_code' => $bad])->assertJsonValidationErrors('postal_code');
        }
        foreach (['3013 AJ', '3013AJ', '1012 ab'] as $good) {
            $this->order(['postal_code' => $good])->assertOk();
        }

        foreach (['abc', '', '12345678901', '-5', '12!'] as $bad) {
            $this->order(['house_number' => $bad])->assertJsonValidationErrors('house_number');
        }
        foreach (['1', '12', '12a', '12-14', '12 bis', '123/4'] as $good) {
            $this->order(['house_number' => $good])->assertOk();
        }

        $this->order(['postal_code' => 'xx'])->assertJsonPath('errors.postal_code.0', 'Vul een geldige postcode in, bijvoorbeeld 3013 AJ.');
    }

    public function test_date_of_birth_must_be_a_real_past_date(): void
    {
        $this->order(['date_of_birth' => 'tomorrow'])->assertJsonValidationErrors('date_of_birth');
        $this->order(['date_of_birth' => '17-05-1990'])->assertJsonValidationErrors('date_of_birth');   // wrong format
        $this->order(['date_of_birth' => '2030-01-01'])->assertJsonValidationErrors('date_of_birth');   // not before today
        $this->order(['date_of_birth' => '2031-01-01'])->assertJsonValidationErrors('date_of_birth');   // future
        $this->order(['date_of_birth' => '1850-01-01'])->assertJsonValidationErrors('date_of_birth');   // implausible
    }

    public function test_the_driver_must_be_old_enough_on_the_pick_up_date(): void
    {
        // pick-up 2030-01-02: born 2012-01-02 is exactly 18
        $this->order(['date_of_birth' => '2012-01-02'])->assertOk();
        $this->order(['date_of_birth' => '2012-01-03'])->assertJsonValidationErrors('date_of_birth')
            ->assertJsonPath('errors.date_of_birth.0', 'Je moet op de ophaaldatum minimaal 18 jaar zijn.');
        $this->order(['date_of_birth' => '2020-06-01'])->assertJsonValidationErrors('date_of_birth');

        config(['booking.min_driver_age' => 21]);
        $this->order(['date_of_birth' => '2009-01-03'])->assertJsonValidationErrors('date_of_birth'); // not 21 yet on 2030-01-02
        $this->order(['date_of_birth' => '2009-01-02'])->assertOk();                                  // exactly 21
    }

    // --- the rental itself is validated too ------------------------------------------------------------

    public function test_the_period_rules_apply(): void
    {
        $this->order(['pickup_date' => '2030-01-05', 'return_date' => '2030-01-07'])->assertJsonValidationErrors('pickup_date');   // Saturday
        $this->order(['pickup_time' => '19:00'])->assertJsonValidationErrors('pickup_time');                                       // after closing
        $this->order(['return_date' => '2030-01-02', 'return_time' => '09:00'])->assertJsonValidationErrors('return_time');          // before pick-up
        $this->order(['pickup_branch_id' => 999])->assertJsonValidationErrors('pickup_branch_id');
    }

    public function test_client_and_period_errors_are_reported_together_only_once_the_fields_are_valid(): void
    {
        // with a client error the period (opening hours) is not checked yet; fix it and the period error shows
        $this->order(['first_name' => '', 'pickup_date' => '2030-01-05', 'return_date' => '2030-01-07'])->assertJsonValidationErrors('first_name')->assertJsonMissingValidationErrors('pickup_date');
        $this->order(['pickup_date' => '2030-01-05', 'return_date' => '2030-01-07'])->assertJsonValidationErrors('pickup_date');
    }

    // --- vehicle and price -------------------------------------------------------------------------------

    public function test_the_vehicle_choice_must_be_offered_and_priced(): void
    {
        $this->order(['class_id' => 999])->assertJsonValidationErrors('class_id');
        $this->order(['fuel_type' => 'electric'])->assertUnprocessable()->assertJsonPath('message', 'Dit voertuig wordt niet met deze brandstof en versnellingsbak aangeboden.');
        $this->order(['fuel_type' => 'diesel'])->assertUnprocessable()->assertJsonPath('message', 'Voor dit voertuig is nog geen prijs beschikbaar.'); // offered, but no price entered

        $this->class->update(['is_active' => false]);
        $this->order()->assertJsonValidationErrors('class_id');
    }

    public function test_the_branch_label_does_not_repeat_the_city(): void
    {
        $this->assertSame('Rentacar Rotterdam', Branch::where('city', 'Rotterdam')->first()->label());
        $this->assertSame('Utrecht – Hoofdkantoor', (new Branch(['name' => 'Hoofdkantoor', 'city' => 'Utrecht']))->label());
    }
}
