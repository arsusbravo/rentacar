<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VehicleCategory;
use App\Models\VehicleClass;
use App\Models\VehicleClassFuelType;
use App\Models\VehicleClassTransmission;
use Database\Seeders\VehicleCategorySeeder;
use Database\Seeders\VehicleClassSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VehicleClassAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function staff(): static
    {
        return $this->actingAs(User::factory()->employee()->create());
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'vehicle_category_id' => VehicleCategory::factory()->named('Personenauto', 'Passenger car')->create()->id,
            'names' => ['nl' => 'Compact', 'en' => 'Compact car'],
            'descriptions' => ['nl' => 'Handig en zuinig', 'en' => 'Handy and economical'],
            'example_models' => 'VW Polo, Opel Corsa',
            'fuel_types' => ['petrol', 'diesel'],
            'transmissions' => ['manual', 'automatic'],
            'license_category' => 'B',
            'seats' => 5, 'doors' => 5, 'baggage_large' => 1, 'baggage_small' => 1,
            'cargo_volume_liters' => 350, 'payload_kg' => null, 'sort_order' => 20, 'is_active' => true,
        ], $extra);
    }

    public function test_staff_can_create_a_class_with_translations_and_several_transmissions(): void
    {
        $this->staff()->postJson('/admin/api/classes', $this->payload())
            ->assertCreated()
            ->assertJsonPath('slug', 'compact')
            ->assertJsonPath('names.en', 'Compact car')
            ->assertJsonPath('descriptions.nl', 'Handig en zuinig')
            ->assertJsonPath('transmissions', ['manual', 'automatic'])
            ->assertJsonPath('category_name', 'Personenauto')
            ->assertJsonPath('fuel_types', ['petrol', 'diesel'])
            ->assertJsonPath('fuel_labels', ['Benzine', 'Diesel']);

        $class = VehicleClass::first();
        $this->assertSame(['manual', 'automatic'], $class->transmissions());
        $this->assertSame(350, $class->cargo_volume_liters);
    }

    public function test_slugs_stay_unique_and_empty_baggage_defaults_to_zero(): void
    {
        $this->staff();
        $category = VehicleCategory::factory()->create()->id;
        $one = ['vehicle_category_id' => $category, 'baggage_large' => null, 'baggage_small' => null, 'sort_order' => null];

        $this->postJson('/admin/api/classes', $this->payload($one))->assertCreated()->assertJsonPath('slug', 'compact')->assertJsonPath('baggage_large', 0);
        $this->postJson('/admin/api/classes', $this->payload($one))->assertCreated()->assertJsonPath('slug', 'compact-2');
    }

    public function test_validation(): void
    {
        $this->staff();

        $this->postJson('/admin/api/classes', [])->assertJsonValidationErrors(['names.nl', 'vehicle_category_id', 'example_models', 'fuel_types', 'transmissions', 'license_category', 'seats']);

        $this->postJson('/admin/api/classes', $this->payload(['fuel_types' => ['steam'], 'license_category' => 'Z', 'vehicle_category_id' => 999]))
            ->assertJsonValidationErrors(['fuel_types.0', 'license_category', 'vehicle_category_id']);
        $this->postJson('/admin/api/classes', $this->payload(['fuel_types' => []]))->assertJsonValidationErrors('fuel_types');
        $this->postJson('/admin/api/classes', $this->payload(['fuel_types' => ['petrol', 'petrol']]))->assertJsonValidationErrors('fuel_types.0');
        $this->postJson('/admin/api/classes', $this->payload(['transmissions' => []]))->assertJsonValidationErrors('transmissions');
        $this->postJson('/admin/api/classes', $this->payload(['transmissions' => ['cvt']]))->assertJsonValidationErrors('transmissions.0');
        $this->postJson('/admin/api/classes', $this->payload(['transmissions' => ['manual', 'manual']]))->assertJsonValidationErrors('transmissions.0');
        $this->postJson('/admin/api/classes', $this->payload(['seats' => 0]))->assertJsonValidationErrors('seats');

        $this->assertSame(0, VehicleClass::count());
    }

    public function test_dutch_name_is_required_but_other_languages_are_optional(): void
    {
        $this->staff();

        $this->postJson('/admin/api/classes', $this->payload(['names' => ['nl' => 'Mini', 'en' => ''], 'descriptions' => ['nl' => '', 'en' => '']]))
            ->assertCreated()->assertJsonPath('names.en', '')->assertJsonPath('descriptions.nl', '');

        app()->setLocale('en');
        $this->assertSame('Mini', VehicleClass::first()->name); // falls back to Dutch
    }

    public function test_update_changes_specs_transmissions_and_translations(): void
    {
        $this->staff();
        $id = $this->postJson('/admin/api/classes', $this->payload())->json('id');

        $this->putJson("/admin/api/classes/$id", $this->payload([
            'vehicle_category_id' => VehicleClass::find($id)->vehicle_category_id,
            'names' => ['nl' => 'Compact plus', 'en' => ''], // removing the English name removes that translation
            'transmissions' => ['automatic'],
            'seats' => 4,
            'fuel_types' => ['electric'],
        ]))->assertOk()->assertJsonPath('name', 'Compact plus')->assertJsonPath('transmissions', ['automatic'])->assertJsonPath('slug', 'compact');

        $class = VehicleClass::find($id);
        $this->assertSame(['automatic'], $class->transmissions());
        $this->assertSame(1, $class->translations()->count());
        $this->assertSame(1, VehicleClassTransmission::where('vehicle_class_id', $id)->count());
        $this->assertSame(['electric'], $class->fuelTypes());
        $this->assertSame(1, VehicleClassFuelType::where('vehicle_class_id', $id)->count());
    }

    public function test_index_returns_classes_in_category_order_with_option_lists(): void
    {
        $this->seed([VehicleCategorySeeder::class, VehicleClassSeeder::class]);

        $response = $this->staff()->getJson('/admin/api/classes')->assertOk()
            ->assertJsonCount(12, 'data')
            ->assertJsonPath('data.0.slug', 'mini')                 // first category, first class
            ->assertJsonPath('data.0.category_name', 'Personenauto')
            ->assertJsonPath('data.11.slug', 'box-truck')           // last category
            ->assertJsonPath('options.fuel_types.0', ['value' => 'petrol', 'label' => 'Benzine'])
            ->assertJsonPath('data.0.fuel_types', ['petrol'])
            ->assertJsonPath('options.transmissions.1', ['value' => 'automatic', 'label' => 'Automaat'])
            ->assertJsonCount(4, 'options.categories');

        $this->assertSame(['manual', 'automatic'], collect($response->json('data'))->firstWhere('slug', 'compact')['transmissions']);
    }

    public function test_options_follow_the_current_language(): void
    {
        app()->setLocale('en'); // the SetLocale middleware leaves a locale alone when there is no cookie

        $this->staff()->getJson('/admin/api/classes')->assertJsonPath('options.transmissions.0.label', 'Manual');
    }

    public function test_clients_and_guests_cannot_manage_classes(): void
    {
        $this->getJson('/admin/api/classes')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson('/admin/api/classes', $this->payload())->assertForbidden();
    }

    public function test_only_admins_can_delete_and_it_removes_translations_and_transmissions(): void
    {
        $class = VehicleClass::factory()->named('Mini', 'Mini')->withTransmissions(['manual', 'automatic'])->create();

        $this->actingAs(User::factory()->employee()->create())->deleteJson("/admin/api/classes/{$class->id}")->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->deleteJson("/admin/api/classes/{$class->id}")->assertNoContent();

        $this->assertModelMissing($class);
        $this->assertSame(0, VehicleClassTransmission::count());
        $this->assertDatabaseCount('vehicle_class_translations', 0);
    }

    // --- image -----------------------------------------------------------------------------------

    private function multipart(array $extra = []): array
    {
        // What the admin form sends: flat multipart fields instead of JSON.
        return array_merge($this->payload(), ['transmissions' => ['manual'], 'is_active' => '1'], $extra);
    }

    public function test_a_class_can_be_created_with_an_image(): void
    {
        $response = $this->staff()->post('/admin/api/classes', $this->multipart(['image' => UploadedFile::fake()->image('polo.jpg', 800, 600)]), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('has_image', true);

        $path = VehicleClass::first()->image_path;
        $this->assertStringStartsWith('classes/', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertStringContainsString('/storage/'.$path, $response->json('image_url'));
    }

    public function test_without_an_image_the_placeholder_is_used(): void
    {
        $this->staff()->postJson('/admin/api/classes', $this->payload())->assertCreated()->assertJsonPath('has_image', false);

        $this->assertStringEndsWith('/images/category-placeholder.svg', VehicleClass::first()->imageUrl());
    }

    public function test_update_replaces_keeps_and_removes_the_image(): void
    {
        $this->staff();
        $id = $this->post('/admin/api/classes', $this->multipart(['image' => UploadedFile::fake()->image('a.jpg')]), ['Accept' => 'application/json'])->json('id');
        $first = VehicleClass::find($id)->image_path;

        // editing without a file keeps the image
        $this->putJson("/admin/api/classes/$id", $this->payload(['vehicle_category_id' => VehicleClass::find($id)->vehicle_category_id, 'seats' => 4]))->assertOk()->assertJsonPath('has_image', true);
        Storage::disk('public')->assertExists($first);

        // a new file replaces it and removes the old one
        $this->post("/admin/api/classes/$id", $this->multipart(['_method' => 'PUT', 'vehicle_category_id' => VehicleClass::find($id)->vehicle_category_id, 'image' => UploadedFile::fake()->image('b.png')]), ['Accept' => 'application/json'])->assertOk();
        $second = VehicleClass::find($id)->image_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        // remove_image clears it
        $this->putJson("/admin/api/classes/$id", $this->payload(['vehicle_category_id' => VehicleClass::find($id)->vehicle_category_id, 'remove_image' => true]))->assertOk()->assertJsonPath('has_image', false);
        Storage::disk('public')->assertMissing($second);
    }

    public function test_only_real_images_up_to_4_mb_are_accepted(): void
    {
        $this->staff()->post('/admin/api/classes', $this->multipart(['image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]), ['Accept' => 'application/json'])->assertJsonValidationErrors('image');
        $this->staff()->post('/admin/api/classes', $this->multipart(['image' => UploadedFile::fake()->image('big.jpg')->size(5000)]), ['Accept' => 'application/json'])->assertJsonValidationErrors('image');
        $this->assertSame(0, VehicleClass::count());
    }

    public function test_deleting_a_class_removes_its_image_file(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $id = $this->post('/admin/api/classes', $this->multipart(['image' => UploadedFile::fake()->image('a.jpg')]), ['Accept' => 'application/json'])->json('id');
        $path = VehicleClass::find($id)->image_path;

        $this->deleteJson("/admin/api/classes/$id")->assertNoContent();
        Storage::disk('public')->assertMissing($path);
    }

    public function test_the_vehicles_page_shows_the_class_image_or_the_placeholder(): void
    {
        $category = VehicleCategory::factory()->named('Personenauto', 'Passenger car')->create(['slug' => 'passenger-car']);
        VehicleClass::factory()->named('Mini', 'Mini')->create(['vehicle_category_id' => $category->id, 'image_path' => 'classes/mini.jpg']);
        VehicleClass::factory()->named('Compact', 'Compact')->create(['vehicle_category_id' => $category->id]);

        $html = $this->get('/vehicles?category=passenger-car')->assertOk()->getContent();

        $this->assertStringContainsString('storage/classes/mini.jpg', $html);
        $this->assertStringContainsString('images/category-placeholder.svg', $html);
    }
}
