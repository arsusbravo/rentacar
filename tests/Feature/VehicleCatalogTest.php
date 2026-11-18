<?php

namespace Tests\Feature;

use App\Models\VehicleCategory;
use App\Models\VehicleCategoryTranslation;
use App\Models\VehicleClass;
use App\Models\VehicleClassFuelType;
use App\Models\VehicleClassTransmission;
use App\Models\VehicleClassTranslation;
use Database\Seeders\VehicleCategorySeeder;
use Database\Seeders\VehicleClassSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Tests\TestCase;

class VehicleCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public'); // the category seeder copies its images to the public disk
    }

    public function test_names_follow_the_locale_with_dutch_as_default(): void
    {
        $category = VehicleCategory::factory()->named('Personenauto', 'Passenger car')->create();

        $this->assertSame('Personenauto', $category->name); // app default: nl
        app()->setLocale('en');
        $this->assertSame('Passenger car', $category->fresh()->name);
    }

    public function test_missing_translation_falls_back_to_dutch_then_to_the_slug(): void
    {
        $category = VehicleCategory::factory()->create(['slug' => 'bakfiets']);
        $this->assertSame('bakfiets', $category->name); // no translations at all

        $category->setTranslation('nl', ['name' => 'Bakfiets']);
        app()->setLocale('en'); // no English yet
        $this->assertSame('Bakfiets', $category->fresh()->name);
    }

    public function test_set_translation_updates_instead_of_duplicating_and_rejects_unknown_locales(): void
    {
        $class = VehicleClass::factory()->create();
        $class->setTranslation('nl', ['name' => 'Klein']);
        $class->setTranslation('nl', ['name' => 'Kleiner', 'description' => 'Handig']);

        $this->assertSame(1, $class->translations()->count());
        $this->assertSame('Kleiner', $class->fresh()->name);
        $this->assertSame('Handig', $class->fresh()->description);

        $this->expectException(InvalidArgumentException::class);
        $class->setTranslation('de', ['name' => 'Klein']);
    }

    public function test_a_locale_can_only_be_translated_once_per_item(): void
    {
        $category = VehicleCategory::factory()->create();
        VehicleCategoryTranslation::create(['vehicle_category_id' => $category->id, 'locale' => 'nl', 'name' => 'A']);

        $this->expectException(QueryException::class);
        VehicleCategoryTranslation::create(['vehicle_category_id' => $category->id, 'locale' => 'nl', 'name' => 'B']);
    }

    public function test_class_belongs_to_a_category_and_categories_list_their_classes_in_order(): void
    {
        $category = VehicleCategory::factory()->create();
        VehicleClass::factory()->create(['vehicle_category_id' => $category->id, 'slug' => 'b', 'sort_order' => 20]);
        VehicleClass::factory()->create(['vehicle_category_id' => $category->id, 'slug' => 'a', 'sort_order' => 10]);

        $this->assertSame(['a', 'b'], $category->classes->pluck('slug')->all());
        $this->assertTrue($category->classes->first()->category->is($category));
    }

    public function test_a_category_with_classes_cannot_be_deleted(): void
    {
        $class = VehicleClass::factory()->create();

        $this->expectException(QueryException::class);
        $class->category->delete();
    }

    public function test_deleting_removes_the_translations_too(): void
    {
        $class = VehicleClass::factory()->named('Compact', 'Compact')->create();
        $category = $class->category;
        $category->setTranslation('nl', ['name' => 'Personenauto']);

        $class->delete();
        $this->assertSame(0, VehicleClassTranslation::count());

        $category->delete();
        $this->assertSame(0, VehicleCategoryTranslation::count());
    }

    public function test_seeders_are_idempotent_and_fully_translated(): void
    {
        foreach (range(1, 2) as $_) {
            $this->seed(VehicleCategorySeeder::class);
            $this->seed(VehicleClassSeeder::class);
        }

        $this->assertSame(4, VehicleCategory::count());
        $this->assertSame(12, VehicleClass::count());
        $this->assertSame(8, VehicleCategoryTranslation::count());
        $this->assertSame(24, VehicleClassTranslation::count());

        app()->setLocale('en');
        $suv = VehicleClass::with(['translations', 'transmissionOptions'])->where('slug', 'station-wagon')->first();
        $this->assertSame('Station wagon', $suv->name);
        $this->assertSame('Passenger car', $suv->category->load('translations')->name);
        $this->assertSame(['Manual', 'Automatic'], $suv->transmissionLabels());
    }

    public function test_category_seeder_attaches_the_images_and_never_overwrites_an_existing_one(): void
    {
        $this->seed(VehicleCategorySeeder::class);

        foreach (['passenger-car', 'minibus', 'van', 'truck'] as $slug) {
            $category = VehicleCategory::where('slug', $slug)->first();
            $this->assertSame("categories/$slug.jpg", $category->image_path);
            Storage::disk('public')->assertExists($category->image_path);
        }

        // an image uploaded in the admin survives a re-seed
        VehicleCategory::where('slug', 'van')->update(['image_path' => 'categories/uploaded.jpg']);
        $this->seed(VehicleCategorySeeder::class);
        $this->assertSame('categories/uploaded.jpg', VehicleCategory::where('slug', 'van')->value('image_path'));
    }

    public function test_class_seeder_attaches_the_images_that_exist_and_never_overwrites_an_existing_one(): void
    {
        $this->seed(VehicleCategorySeeder::class);
        $this->seed(VehicleClassSeeder::class);

        // classes with a file in database/seeders/images/classes
        foreach (['mini', 'compact', 'midsize', 'station-wagon', 'suv', 'electric', 'mpv-7', 'minibus-9', 'van-small', 'box-truck'] as $slug) {
            $class = VehicleClass::where('slug', $slug)->first();
            $this->assertSame("classes/$slug.jpg", $class->image_path, $slug);
            Storage::disk('public')->assertExists($class->image_path);
        }

        // classes without a file keep the placeholder
        $this->assertNull(VehicleClass::where('slug', 'van-medium')->value('image_path'));
        $this->assertNull(VehicleClass::where('slug', 'van-large')->value('image_path'));

        // an image uploaded in the admin survives a re-seed
        VehicleClass::where('slug', 'mini')->update(['image_path' => 'classes/uploaded.jpg']);
        $this->seed(VehicleClassSeeder::class);
        $this->assertSame('classes/uploaded.jpg', VehicleClass::where('slug', 'mini')->value('image_path'));
    }

    public function test_seeded_values_are_all_allowed_by_the_config(): void
    {
        $this->seed(VehicleCategorySeeder::class);
        $this->seed(VehicleClassSeeder::class);

        $this->assertEmpty(VehicleClassFuelType::whereNotIn('fuel_type', config('vehicles.fuel_types'))->get());
        $this->assertEmpty(VehicleClass::doesntHave('fuelOptions')->get(), 'every class offers at least one fuel type');
        $this->assertEmpty(VehicleClassTransmission::whereNotIn('transmission', config('vehicles.transmissions'))->get());
        $this->assertEmpty(VehicleClass::doesntHave('transmissionOptions')->get(), 'every class offers at least one transmission');
        $this->assertEmpty(VehicleClass::whereNotIn('license_category', config('vehicles.license_categories'))->get());
    }

    public function test_validation_rules_use_the_flexible_config_lists(): void
    {
        $category = VehicleCategory::factory()->create();
        $valid = [
            'vehicle_category_id' => $category->id, 'slug' => 'x', 'example_models' => 'A, B',
            'fuel_types' => ['electric', 'petrol'], 'transmissions' => ['automatic', 'manual'], 'license_category' => 'B', 'seats' => 5,
        ];

        $this->assertTrue(Validator::make($valid, VehicleClass::rules())->passes());

        $bad = Validator::make(['fuel_types' => ['steam'], 'transmissions' => ['cvt'], 'license_category' => 'Z'] + $valid, VehicleClass::rules());
        $this->assertEqualsCanonicalizing(['fuel_types.0', 'transmissions.0', 'license_category'], $bad->errors()->keys());

        $this->assertTrue(Validator::make(['transmissions' => []] + $valid, VehicleClass::rules())->fails(), 'at least one transmission');
        $this->assertTrue(Validator::make(['fuel_types' => []] + $valid, VehicleClass::rules())->fails(), 'at least one fuel type');
        $this->assertTrue(Validator::make(['fuel_types' => ['petrol', 'petrol']] + $valid, VehicleClass::rules())->fails(), 'no duplicate fuel types');
        $this->assertTrue(Validator::make(['transmissions' => ['manual', 'manual']] + $valid, VehicleClass::rules())->fails(), 'no duplicates');

        // Flexible: a new value only needs a config entry, no migration.
        config(['vehicles.transmissions' => ['manual', 'automatic', 'cvt']]);
        $this->assertTrue(Validator::make(['transmissions' => ['cvt']] + $valid, VehicleClass::rules())->passes());
    }

    public function test_a_class_can_offer_several_transmissions_and_the_client_chooses_when_there_are_more_than_one(): void
    {
        $both = VehicleClass::factory()->withTransmissions(['automatic', 'manual'])->create();
        $one = VehicleClass::factory()->create(); // default: manual only

        $this->assertSame(['manual', 'automatic'], $both->fresh()->transmissions()); // config order, not insertion order
        $this->assertTrue($both->requiresTransmissionChoice());

        $this->assertSame(['manual'], $one->fresh()->transmissions());
        $this->assertFalse($one->requiresTransmissionChoice());
    }

    public function test_a_class_can_offer_several_fuel_types_and_the_client_chooses_when_there_are_more_than_one(): void
    {
        $several = VehicleClass::factory()->withFuelTypes(['diesel', 'petrol', 'hybrid'])->create();
        $one = VehicleClass::factory()->create(); // default: petrol only

        $this->assertSame(['petrol', 'diesel', 'hybrid'], $several->fresh()->fuelTypes()); // config order, not insertion order
        $this->assertTrue($several->requiresFuelChoice());
        $this->assertSame(['Benzine', 'Diesel', 'Hybride'], $several->fresh()->fuelLabels());

        $this->assertSame(['petrol'], $one->fresh()->fuelTypes());
        $this->assertFalse($one->requiresFuelChoice());
    }

    public function test_set_fuel_types_replaces_the_list_independently_of_transmissions(): void
    {
        $class = VehicleClass::factory()->withFuelTypes(['petrol', 'diesel'])->withTransmissions(['manual', 'automatic'])->create();

        $class->setFuelTypes(['electric', 'electric']);
        $fresh = $class->fresh();

        $this->assertSame(['electric'], $fresh->fuelTypes());
        $this->assertSame(['manual', 'automatic'], $fresh->transmissions()); // untouched
        $this->assertSame(1, VehicleClassFuelType::where('vehicle_class_id', $class->id)->count());
    }

    public function test_deleting_a_class_removes_its_fuel_types_too(): void
    {
        VehicleClass::factory()->withFuelTypes(['petrol', 'diesel'])->create()->delete();

        $this->assertSame(0, VehicleClassFuelType::count());
    }

    public function test_set_transmissions_replaces_the_list_without_duplicates(): void
    {
        $class = VehicleClass::factory()->withTransmissions(['manual', 'automatic'])->create();

        $class->setTransmissions(['automatic', 'automatic']);
        $this->assertSame(['automatic'], $class->fresh()->transmissions());
        $this->assertSame(1, VehicleClassTransmission::where('vehicle_class_id', $class->id)->count());
    }

    public function test_deleting_a_class_removes_its_transmissions(): void
    {
        $class = VehicleClass::factory()->withTransmissions(['manual', 'automatic'])->create();
        $class->delete();

        $this->assertSame(0, VehicleClassTransmission::count());
    }

    public function test_labels_exist_in_both_languages_for_every_allowed_value(): void
    {
        foreach (['nl', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach (['fuel_types', 'transmissions', 'license_categories'] as $list) {
                foreach (config("vehicles.$list") as $value) {
                    $key = "vehicles.$list.$value";
                    $this->assertNotSame($key, __($key), "Missing [$locale] label for $key");
                }
            }
        }
    }
}
