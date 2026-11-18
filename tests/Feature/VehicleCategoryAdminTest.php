<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VehicleCategory;
use App\Models\VehicleClass;
use Database\Seeders\VehicleCategorySeeder;
use Database\Seeders\VehicleClassSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VehicleCategoryAdminTest extends TestCase
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
        return array_merge(['names' => ['nl' => 'Personenauto', 'en' => 'Passenger car'], 'sort_order' => 10, 'is_active' => '1'], $extra);
    }

    public function test_category_descriptions_no_longer_exist(): void
    {
        $this->assertFalse(Schema::hasColumn('vehicle_category_translations', 'description'));
        $this->assertTrue(Schema::hasColumn('vehicle_categories', 'image_path'));
    }

    public function test_staff_can_create_a_category_with_an_image(): void
    {
        $response = $this->staff()->post('/admin/api/categories', $this->payload(['image' => UploadedFile::fake()->image('car.jpg', 800, 600)]), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('slug', 'personenauto')->assertJsonPath('names.en', 'Passenger car')->assertJsonPath('has_image', true);

        $path = VehicleCategory::first()->image_path;
        Storage::disk('public')->assertExists($path);
        $this->assertStringContainsString('/storage/'.$path, $response->json('image_url'));
    }

    public function test_without_an_image_the_placeholder_is_used(): void
    {
        $this->staff()->postJson('/admin/api/categories', $this->payload())
            ->assertCreated()->assertJsonPath('has_image', false);

        $this->assertStringEndsWith('/images/category-placeholder.svg', VehicleCategory::first()->imageUrl());
        $this->assertFileExists(public_path('images/category-placeholder.svg'));
    }

    public function test_only_dutch_is_required_and_english_falls_back(): void
    {
        $this->staff()->postJson('/admin/api/categories', ['names' => ['nl' => 'Bestelwagen', 'en' => '']])->assertCreated();
        $this->staff()->postJson('/admin/api/categories', ['names' => ['nl' => '']])->assertJsonValidationErrors('names.nl');

        app()->setLocale('en');
        $this->assertSame('Bestelwagen', VehicleCategory::first()->name);
    }

    public function test_update_replaces_the_image_and_removes_the_old_file(): void
    {
        $this->staff();
        $id = $this->postJson('/admin/api/categories', $this->payload(['image' => UploadedFile::fake()->image('a.jpg')]))->json('id');
        $old = VehicleCategory::find($id)->image_path;

        $this->post("/admin/api/categories/$id", $this->payload(['_method' => 'PUT', 'image' => UploadedFile::fake()->image('b.png')]), ['Accept' => 'application/json'])->assertOk();

        $new = VehicleCategory::find($id)->image_path;
        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($new);
    }

    public function test_image_can_be_removed_and_names_edited_without_touching_it(): void
    {
        $this->staff();
        $id = $this->postJson('/admin/api/categories', $this->payload(['image' => UploadedFile::fake()->image('a.jpg')]))->json('id');
        $path = VehicleCategory::find($id)->image_path;

        // editing the name keeps the image
        $this->putJson("/admin/api/categories/$id", $this->payload(['names' => ['nl' => 'Auto', 'en' => '']]))->assertOk()->assertJsonPath('has_image', true);
        Storage::disk('public')->assertExists($path);
        $this->assertSame(1, VehicleCategory::find($id)->translations()->count()); // empty English name removed

        $this->putJson("/admin/api/categories/$id", $this->payload(['remove_image' => true]))->assertOk()->assertJsonPath('has_image', false);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_only_real_images_are_accepted(): void
    {
        $this->staff()->post('/admin/api/categories', $this->payload(['image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]), ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('image');
        $this->staff()->post('/admin/api/categories', $this->payload(['image' => UploadedFile::fake()->image('big.jpg')->size(5000)]), ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('image');
    }

    public function test_clients_and_guests_cannot_manage_categories(): void
    {
        $this->getJson('/admin/api/categories')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson('/admin/api/categories', $this->payload())->assertForbidden();
    }

    public function test_delete_is_admin_only_and_blocked_while_classes_exist(): void
    {
        $category = VehicleCategory::factory()->create();
        $class = VehicleClass::factory()->create(['vehicle_category_id' => $category->id]);

        $this->actingAs(User::factory()->employee()->create())->deleteJson("/admin/api/categories/{$category->id}")->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())->deleteJson("/admin/api/categories/{$category->id}")
            ->assertUnprocessable()->assertJsonPath('message', 'Deze categorie heeft nog voertuigklassen en kan niet worden verwijderd.');

        $class->delete();
        $this->deleteJson("/admin/api/categories/{$category->id}")->assertNoContent();
        $this->assertModelMissing($category);
    }

    public function test_deleting_a_category_removes_its_image(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $id = $this->postJson('/admin/api/categories', $this->payload(['image' => UploadedFile::fake()->image('a.jpg')]))->json('id');
        $path = VehicleCategory::find($id)->image_path;

        $this->deleteJson("/admin/api/categories/$id")->assertNoContent();
        Storage::disk('public')->assertMissing($path);
    }

    public function test_index_lists_categories_in_order_with_class_counts(): void
    {
        $this->seed([VehicleCategorySeeder::class, VehicleClassSeeder::class]);

        $this->staff()->getJson('/admin/api/categories')->assertOk()->assertJsonCount(4)
            ->assertJsonPath('0.name', 'Personenauto')->assertJsonPath('0.classes_count', 6)->assertJsonPath('0.names.en', 'Passenger car');
    }

    public function test_homepage_shows_uploaded_image_or_placeholder_and_no_category_text(): void
    {
        $this->seed([VehicleCategorySeeder::class, VehicleClassSeeder::class]);
        VehicleCategory::query()->update(['image_path' => null]); // start without images
        VehicleCategory::where('slug', 'van')->update(['image_path' => 'categories/van.jpg']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('storage/categories/van.jpg', $html);
        $this->assertStringContainsString('images/category-placeholder.svg', $html); // the others
        $this->assertStringNotContainsString('Voor verhuizingen, klussen en transport', $html);
    }
}
