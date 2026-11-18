<?php

namespace Tests\Feature;

use App\Models\VehicleCategory;
use App\Models\VehicleClass;
use Database\Seeders\BranchSeeder;
use Database\Seeders\VehicleCategorySeeder;
use Database\Seeders\VehicleClassSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public'); // the category seeder copies its images to the public disk
        $this->seed(BranchSeeder::class);
    }

    public function test_homepage_is_server_rendered_dutch_blade_with_only_the_search_island(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Vind jouw perfecte auto')
            ->assertSee('Ophalen in Amsterdam, Den Haag en Rotterdam')
            ->assertSee('Rotterdam – Rentacar Rotterdam', false)
            ->assertSee('action="'.route('vehicles.index').'"', false)
            ->assertSee('id="home-search"', false)
            ->assertSee('data-vehicles-url="'.route('vehicles.index').'"', false)
            ->assertDontSee('id="app"', false)
            ->assertDontSee('admin.js', false)
            ->assertDontSee('checkout.js', false);
    }

    public function test_the_homepage_explains_how_the_rental_works_instead_of_showing_placeholder_cars(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('id="how-it-works"', false)->assertSee('Zo werkt het')->assertSee('Van zoeken tot boeken in vier stappen.')
            ->assertSee('Zoeken')->assertSee('Kies je voertuig')->assertSee('Stel samen')->assertSee('Vul je gegevens in en boek')
            ->assertSee('Ophalen en terugbrengen')
            ->assertSee('href="'.route('vehicles.index').'"', false)->assertSee('Bekijk alle voertuigen')
            ->assertDontSee("Uitgelichte auto's")->assertDontSee('Auto placeholder')->assertDontSee('Vanaf €--/dag');

        $this->withUnencryptedCookie('locale', 'en')->get('/')
            ->assertSee('How it works')->assertSee('Make it yours')->assertSee('Fill in your details and book')->assertSee('Browse all vehicles')
            ->assertDontSee('Featured cars')->assertDontSee('Car placeholder');
    }

    public function test_the_homepage_says_it_is_a_demo(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Dit is een demo-website')
            ->assertSee('De voertuigen, prijzen, vestigingen, extra opties en borgbedragen zijn voorbeelden.')
            ->assertSee('Er wordt niets echt geboekt, betaald of in rekening gebracht en de gegevens die je invult worden niet opgeslagen.');

        $this->withUnencryptedCookie('locale', 'en')->get('/')
            ->assertSee('This is a demo site')
            ->assertSee('Nothing is really booked, paid or charged, and the details you enter are not stored.');
    }

    public function test_the_homepage_explains_how_the_price_is_calculated_with_a_worked_example(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Zo wordt je prijs berekend')
            ->assertSee('Huurprijs')->assertSee('Brandstof en versnellingsbak')->assertSee('Eigen risico verlagen')->assertSee('Extra opties')
            ->assertSee('BTW')->assertSee('Borg')->assertSee('Kilometers')
            ->assertSee('goedkoopste combinatie')->assertSee('Elk begonnen uur telt mee.')
            ->assertSee('Alle prijzen zijn exclusief BTW. Over het totaal komt 21% BTW.')
            ->assertSee('Bij huur vanaf 30 dagen (lease) is geen borg nodig.')
            ->assertSee('minimaal de prijs inclusief BTW + € 150, naar boven afgerond op € 50')
            ->assertSee('komt er € 250 bij')
            // the worked example (day 50, week 250, 9 days, winter tyres 6 per day), produced by the real calculators
            ->assertSee('Rekenvoorbeeld')
            ->assertSee('1 week + 2 dagen')->assertSee('€ 350,00')
            ->assertSee('9 × € 6,00')->assertSee('€ 54,00')
            ->assertSee('€ 404,00')->assertSee('€ 84,84')->assertSee('€ 488,84')
            ->assertSee('€ 488,84 + € 150 = € 638,84')->assertSee('€ 650,00')->assertSee('€ 900,00');
    }

    public function test_the_calculation_text_and_example_follow_the_settings(): void
    {
        config(['pricing.vat_rate' => 9, 'pricing.deposit.margin' => 200, 'pricing.deposit.own_risk_surcharge' => 100, 'pricing.deposit.leasing_from_days' => 14, 'pricing.half_day_hours' => 4]);

        $this->get('/')->assertOk()
            ->assertSee('Over het totaal komt 9% BTW.')->assertSee('Bij huur vanaf 14 dagen (lease)')->assertSee('tot 4 uur')
            ->assertSee('prijs inclusief BTW + € 200')->assertSee('komt er € 100 bij')
            // 404.00 + 9% = 36.36 -> 440.36; + 200 = 640.36 -> 650; + 100 = 750
            ->assertSee('€ 36,36')->assertSee('€ 440,36')->assertSee('€ 440,36 + € 200 = € 640,36')->assertSee('€ 650,00')->assertSee('€ 750,00');
    }

    public function test_the_calculation_explanation_in_english(): void
    {
        $this->withUnencryptedCookie('locale', 'en')->get('/')->assertOk()
            ->assertSee('How your price is calculated')->assertSee('Rental price')->assertSee('Fuel and transmission')->assertSee('Lowering the own risk')
            ->assertSee('Example')->assertSee('1 week + 2 days')->assertSee('€ 350.00')->assertSee('9 × € 6.00')
            ->assertSee('€ 404.00')->assertSee('€ 84.84')->assertSee('€ 488.84')
            ->assertSee('€ 488.84 + € 150 = € 638.84')->assertSee('€ 650.00')->assertSee('€ 900.00')
            ->assertSee('is leasing and needs no deposit');
    }

    public function test_homepage_in_english(): void
    {
        $this->withUnencryptedCookie('locale', 'en')->get('/')->assertSee('Find your perfect ride')->assertSee('Pick up in Amsterdam, Den Haag and Rotterdam');
    }

    public function test_homepage_shows_the_active_categories_with_their_classes(): void
    {
        $this->seed([VehicleCategorySeeder::class, VehicleClassSeeder::class]);
        VehicleCategory::where('slug', 'truck')->update(['is_active' => false]);

        $this->get('/')->assertOk()
            ->assertSee('Onze voertuigen')
            ->assertSee('Personenauto')->assertSee('Personenbus')->assertSee('Bestelwagen')
            ->assertDontSee('Vrachtwagen')
            // the cards open the vehicles page for that category and no longer list class names
            ->assertSee('href="'.route('vehicles.index', ['category' => 'passenger-car']).'"', false)
            ->assertDontSee('Stationwagen')->assertDontSee('Middelgrote bestelwagen');

        $this->withUnencryptedCookie('locale', 'en')->get('/')
            ->assertSee('Our vehicles')->assertSee('Passenger car')->assertDontSee('Station wagon');
    }

    public function test_homepage_search_goes_to_the_vehicles_page_and_offers_the_vehicle_types(): void
    {
        $this->seed([VehicleCategorySeeder::class, VehicleClassSeeder::class]);

        $this->get('/')->assertOk()
            ->assertSee('data-vehicles-url="'.route('vehicles.index').'"', false) // not /rent
            ->assertSee('data-categories', false)->assertSee('&quot;slug&quot;:&quot;passenger-car&quot;', false)
            // without JavaScript the plain form also goes to the vehicles page, with a vehicle type
            ->assertSee('action="'.route('vehicles.index').'"', false)->assertSee('name="category"', false);
    }

    public function test_the_vehicle_type_picker_gets_an_icon_only_when_the_file_exists(): void
    {
        $this->seed([VehicleCategorySeeder::class, VehicleClassSeeder::class]);
        Storage::disk('public')->put('images/category_icons/van.png', 'png');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('storage\/images\/category_icons\/van.png', html_entity_decode($html));
        $this->assertSame(1, substr_count($html, 'category_icons'));            // van only
        $this->assertStringContainsString('&quot;icon&quot;:null', $html);       // the others have none
    }

    public function test_vehicles_page_has_the_search_form_and_lists_every_category_without_a_filter(): void
    {
        $this->seed([VehicleCategorySeeder::class, VehicleClassSeeder::class]);

        $this->get('/vehicles')->assertOk()
            ->assertSee('Kies je voertuig')
            ->assertSee('id="vehicles-search"', false)->assertSee('data-branches', false)
            ->assertSee('Personenauto')->assertSee('Bestelwagen')->assertSee('Verhuiswagen')
            ->assertSee('Stationwagen')->assertSee('Kleine bestelwagen')
            ->assertDontSee('id="app"', false)->assertDontSee('checkout.js', false);
    }

    public function test_vehicles_page_filters_on_the_chosen_vehicle_type_and_keeps_the_search(): void
    {
        $this->seed([VehicleCategorySeeder::class, VehicleClassSeeder::class]);

        $this->get('/vehicles?category=van&pickup=2&return=3&pickup_date=2030-01-02&pickup_time=10:00&return_date=2030-01-03&return_time=12:00')->assertOk()
            ->assertSee('Kleine bestelwagen')->assertSee('Middelgrote bestelwagen')
            ->assertDontSee('Stationwagen')->assertDontSee('Verhuiswagen')
            // the search form is prefilled
            ->assertSee('&quot;category&quot;:&quot;van&quot;', false)->assertSee('&quot;pickupDate&quot;:&quot;2030-01-02&quot;', false)
            // selecting a vehicle leads to the checkout, with the vehicle and the search
            ->assertSee('href="'.e(route('rent', ['pickup' => 2, 'return' => 3, 'pickup_date' => '2030-01-02', 'pickup_time' => '10:00', 'return_date' => '2030-01-03', 'return_time' => '12:00', 'class' => VehicleClass::where('slug', 'van-small')->value('id')])).'"', false)
            ->assertSee('Selecteer')
            // the links are marked so the search form can put its current values in them (vehicles.js)
            ->assertSee('data-select-class', false);
    }

    public function test_vehicle_cards_show_names_specs_and_choices(): void
    {
        $this->seed([VehicleCategorySeeder::class, VehicleClassSeeder::class]);

        $this->get('/vehicles?category=van')->assertOk()
            ->assertSee('Citroën Berlingo, VW Caddy Cargo, Peugeot Partner')->assertSee('of vergelijkbaar')
            ->assertSee('650 kg')->assertSee('3.300 L')->assertSee('Diesel')->assertSee('Handgeschakeld')
            ->assertDontSee('Je kiest bij het boeken') // vans: one fuel, one transmission
            ->assertSee('<details', false)->assertSee('Specificaties')
            ->assertDontSee('Bijv.')->assertDontSee('Voor kleine verhuizingen en klussen'); // descriptions are not shown

        $this->withUnencryptedCookie('locale', 'en')->get('/vehicles?category=passenger-car')
            ->assertSee('Station wagon')->assertSee('2 large + 1 small')->assertSee('or similar')
            ->assertSee('Manual / Automatic')->assertSee('You choose when booking')
            ->assertSee('Petrol / Diesel')->assertSee('Select');
    }

    public function test_unknown_or_inactive_vehicle_type_shows_all_vehicles(): void
    {
        $this->seed([VehicleCategorySeeder::class, VehicleClassSeeder::class]);
        VehicleCategory::where('slug', 'truck')->update(['is_active' => false]);

        $this->get('/vehicles?category=truck')->assertOk()->assertSee('Personenauto')->assertDontSee('Verhuiswagen');
        $this->get('/vehicles?category=nope')->assertOk()->assertSee('Personenauto')->assertSee('Bestelwagen');
    }

    public function test_vehicles_page_without_any_vehicles(): void
    {
        $this->get('/vehicles')->assertOk()->assertSee('Nog geen voertuigen beschikbaar.');
    }

    public function test_the_navigation_cars_link_opens_the_vehicles_page(): void
    {
        $this->get('/')->assertSee('href="'.route('vehicles.index').'"', false);
    }

    public function test_homepage_hides_categories_without_active_classes(): void
    {
        $this->seed(VehicleCategorySeeder::class); // categories only, no classes

        $this->get('/')->assertOk()->assertDontSee('Onze voertuigen')->assertDontSee('Personenauto');
    }

    public function test_locations_page_lists_branches_with_opening_hours(): void
    {
        $this->get('/locations')->assertOk()
            ->assertSee('Onze vestigingen')->assertSee('Amsterdam')->assertSee('08:00 – 18:00')->assertSee('Gesloten')
            ->assertSee('maandag')->assertDontSee('id="app"', false);
    }

    private function classId(string $slug): int
    {
        return VehicleClass::where('slug', $slug)->value('id');
    }

    public function test_checkout_without_a_selected_vehicle_goes_back_to_the_vehicle_choice_keeping_the_search(): void
    {
        $this->seed([VehicleCategorySeeder::class, VehicleClassSeeder::class]);

        $this->get('/rent')->assertRedirect(route('vehicles.index'));
        $this->get('/rent?class=99999&pickup=2&pickup_date=2030-01-02')->assertRedirect(route('vehicles.index', ['pickup' => 2, 'pickup_date' => '2030-01-02']));
        $this->get('/rent?class=abc')->assertRedirect(route('vehicles.index'));
    }

    public function test_inactive_vehicle_classes_cannot_be_checked_out(): void
    {
        $this->seed([VehicleCategorySeeder::class, VehicleClassSeeder::class]);
        VehicleClass::where('slug', 'mini')->update(['is_active' => false]);
        VehicleCategory::where('slug', 'truck')->update(['is_active' => false]);

        $this->get('/rent?class='.$this->classId('mini'))->assertRedirect(route('vehicles.index'));
        $this->get('/rent?class='.$this->classId('box-truck'))->assertRedirect(route('vehicles.index'));
    }

    public function test_checkout_mounts_vue_with_the_selected_vehicle_and_the_search(): void
    {
        $this->seed([VehicleCategorySeeder::class, VehicleClassSeeder::class]);
        $suv = $this->classId('suv');

        $this->get("/rent?class=$suv&pickup=2&return=3")->assertOk()
            ->assertSee('id="checkout-app"', false)
            ->assertSee('&quot;pickup&quot;:2', false)->assertSee('&quot;return&quot;:3', false)
            ->assertSee('data-vehicle-class', false)->assertSee('&quot;name&quot;:&quot;SUV&quot;', false)
            ->assertSee('Nissan Qashqai', false)
            // several fuels and transmissions: petrol and manual are preselected, the others can be chosen instead
            ->assertSee('&quot;default_fuel_type&quot;:&quot;petrol&quot;', false)->assertSee('&quot;selected_fuel&quot;:&quot;petrol&quot;', false)
            ->assertSee('&quot;default_transmission&quot;:&quot;manual&quot;', false)->assertSee('&quot;selected_transmission&quot;:&quot;manual&quot;', false)
            ->assertSee('data-vehicles-url="'.e(route('vehicles.index', ['pickup' => 2, 'return' => 3, 'category' => 'passenger-car'])).'"', false)
            ->assertDontSee('admin.js', false);
    }

    public function test_a_class_with_one_fuel_and_one_transmission_is_preselected(): void
    {
        $this->seed([VehicleCategorySeeder::class, VehicleClassSeeder::class]);

        $this->get('/rent?class='.$this->classId('van-small'))->assertOk()
            ->assertSee('&quot;selected_fuel&quot;:&quot;diesel&quot;', false)
            ->assertSee('&quot;selected_transmission&quot;:&quot;manual&quot;', false);
    }

    public function test_the_default_fuel_is_petrol_or_else_the_first_offered(): void
    {
        $this->seed([VehicleCategorySeeder::class, VehicleClassSeeder::class]);

        $this->get('/rent?class='.$this->classId('compact'))->assertSee('&quot;default_fuel_type&quot;:&quot;petrol&quot;', false)->assertSee('&quot;selected_fuel&quot;:&quot;petrol&quot;', false);
        $this->get('/rent?class='.$this->classId('mpv-7'))->assertSee('&quot;selected_fuel&quot;:&quot;petrol&quot;', false);                  // diesel and petrol: petrol
        $this->get('/rent?class='.$this->classId('van-small'))->assertSee('&quot;selected_fuel&quot;:&quot;diesel&quot;', false);                  // diesel only
        $this->get('/rent?class='.$this->classId('electric'))->assertSee('&quot;selected_fuel&quot;:&quot;electric&quot;', false);                // no petrol: the first offered
        $this->get('/rent?class='.$this->classId('compact').'&fuel=diesel')->assertSee('&quot;selected_fuel&quot;:&quot;diesel&quot;', false);     // a choice from the URL wins
    }

    public function test_the_default_transmission_is_manual_or_else_the_first_offered(): void
    {
        $this->seed([VehicleCategorySeeder::class, VehicleClassSeeder::class]);

        $this->get('/rent?class='.$this->classId('compact'))->assertSee('&quot;default_transmission&quot;:&quot;manual&quot;', false)->assertSee('&quot;selected_transmission&quot;:&quot;manual&quot;', false);
        // an electric class only has an automatic: that is the default
        $this->get('/rent?class='.$this->classId('electric'))->assertSee('&quot;default_transmission&quot;:&quot;automatic&quot;', false)->assertSee('&quot;selected_transmission&quot;:&quot;automatic&quot;', false);
        // automatic can be preselected from the URL for a class that offers it
        $this->get('/rent?class='.$this->classId('compact').'&transmission=automatic')->assertSee('&quot;selected_transmission&quot;:&quot;automatic&quot;', false);
    }

    public function test_a_choice_from_the_url_is_only_taken_over_when_the_class_offers_it(): void
    {
        $this->seed([VehicleCategorySeeder::class, VehicleClassSeeder::class]);
        $suv = $this->classId('suv');

        $this->get("/rent?class=$suv&fuel=hybrid&transmission=automatic")->assertOk()
            ->assertSee('&quot;selected_fuel&quot;:&quot;hybrid&quot;', false)->assertSee('&quot;selected_transmission&quot;:&quot;automatic&quot;', false);

        $this->get("/rent?class=$suv&fuel=electric&transmission=cvt")->assertOk()   // not offered by the SUV
            ->assertSee('&quot;selected_fuel&quot;:&quot;petrol&quot;', false)->assertSee('&quot;selected_transmission&quot;:&quot;manual&quot;', false); // back to the defaults
    }

    public function test_checkout_prefill_accepts_only_well_formed_values(): void
    {
        $this->seed([VehicleCategorySeeder::class, VehicleClassSeeder::class]);

        $this->get('/rent?class='.$this->classId('suv').'&pickup=1&pickup_date=2030-01-02&pickup_time=10:00&return_date=<script>&return_time=zz')->assertOk()
            ->assertSee('&quot;pickupDate&quot;:&quot;2030-01-02&quot;', false)
            ->assertSee('&quot;pickupTime&quot;:&quot;10:00&quot;', false)
            ->assertSee('&quot;returnDate&quot;:null', false)->assertSee('&quot;returnTime&quot;:null', false);
    }

    public function test_language_switch_sets_cookie_and_returns_to_the_page(): void
    {
        $this->from('/locations')->get('/locale/en')->assertRedirect('/locations')->assertCookie('locale', 'en', false);
    }

    public function test_language_switch_never_redirects_off_site(): void
    {
        $this->withHeader('referer', 'https://evil.example/phish')->get('/locale/en')->assertRedirect(url('/'));
    }

    public function test_unknown_language_is_404(): void
    {
        $this->get('/locale/xx')->assertNotFound();
    }

    public function test_unknown_page_renders_the_blade_404(): void
    {
        $this->get('/does-not-exist')->assertNotFound()->assertSee('Pagina niet gevonden');
    }
}
