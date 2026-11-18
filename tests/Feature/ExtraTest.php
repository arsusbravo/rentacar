<?php

namespace Tests\Feature;

use App\Models\Extra;
use App\Models\ExtraTranslation;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Models\VehicleClass;
use Database\Seeders\ExtraSeeder;
use Database\Seeders\VehicleCategorySeeder;
use Database\Seeders\VehicleClassSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExtraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /** @param list<int> $classes ids of the vehicle classes the extra is coupled to */
    private function extra(array $attributes = [], array $classes = []): Extra
    {
        $extra = Extra::create($attributes + ['slug' => 'e'.Extra::count(), 'price' => 10, 'price_unit' => 'per_day', 'max_quantity' => 1]);
        $extra->setTranslation('nl', ['title' => 'Nederlands', 'description' => 'Omschrijving']);
        $extra->setTranslation('en', ['title' => 'English']);
        $extra->classes()->sync($classes);

        return $extra;
    }

    private function seedAll(): void
    {
        $this->seed([VehicleCategorySeeder::class, VehicleClassSeeder::class, ExtraSeeder::class]);
    }

    private function classId(string $slug): int
    {
        return VehicleClass::where('slug', $slug)->value('id');
    }

    private function couplings(): int
    {
        return DB::table('extra_vehicle_class')->count();
    }

    // --- pricing ----------------------------------------------------------------------------------

    public function test_prices_per_day_unit_and_time(): void
    {
        $perDay = new Extra(['price' => 6.00, 'price_unit' => 'per_day', 'max_quantity' => 1]);
        $perUnit = new Extra(['price' => 12.50, 'price_unit' => 'per_unit', 'max_quantity' => 3]);
        $perTime = new Extra(['price' => 30, 'price_unit' => 'per_time', 'max_quantity' => 1]);

        $this->assertSame(1800, $perDay->totalCents(3));          // 6.00 x 3 days
        $this->assertSame(600, $perDay->totalCents(0));           // at least one day
        $this->assertSame(1800, $perDay->totalCents(3, 5));       // quantity is ignored for per-day extras

        $this->assertSame(2500, $perUnit->totalCents(7, 2));      // 12.50 x 2, independent of the days
        $this->assertSame(3750, $perUnit->totalCents(7, 9));      // capped at max_quantity 3
        $this->assertSame(1250, $perUnit->totalCents(7, 0));      // at least one

        $this->assertSame(3000, $perTime->totalCents(10, 4));     // once, whatever the days or quantity
    }

    public function test_rental_days_count_every_started_24_hours_and_at_least_one(): void
    {
        $pickup = Carbon::parse('2030-01-02 10:00');

        $this->assertSame(1, Extra::rentalDays($pickup, Carbon::parse('2030-01-02 12:00')));
        $this->assertSame(1, Extra::rentalDays($pickup, Carbon::parse('2030-01-03 10:00')));   // exactly 24h
        $this->assertSame(2, Extra::rentalDays($pickup, Carbon::parse('2030-01-03 10:30')));   // 24h30
        $this->assertSame(3, Extra::rentalDays($pickup, Carbon::parse('2030-01-05 09:00')));
        $this->assertSame(1, Extra::rentalDays($pickup, Carbon::parse('2030-01-01 10:00')));   // never below one
    }

    public function test_prices_are_stored_with_two_decimals(): void
    {
        $this->assertSame('12.50', $this->extra(['price' => 12.5, 'price_unit' => 'per_unit'])->fresh()->price);
    }

    // --- translations ----------------------------------------------------------------------------

    public function test_title_and_description_follow_the_language(): void
    {
        $extra = $this->extra();

        $this->assertSame('Nederlands', $extra->title);
        app()->setLocale('en');
        $this->assertSame('English', $extra->fresh()->title);
        $this->assertSame('Omschrijving', $extra->fresh()->description); // no English description: falls back per field to Dutch
    }

    public function test_a_translation_exists_once_per_language(): void
    {
        $extra = $this->extra();

        $this->expectException(QueryException::class);
        ExtraTranslation::create(['extra_id' => $extra->id, 'locale' => 'nl', 'title' => 'Dubbel']);
    }

    // --- coupling to vehicle classes ---------------------------------------------------------------

    public function test_an_extra_is_only_offered_for_the_classes_it_is_coupled_to(): void
    {
        $mini = VehicleClass::factory()->create();
        $suv = VehicleClass::factory()->create();
        $van = VehicleClass::factory()->create();

        $this->extra(['slug' => 'towbar'], [$suv->id, $van->id]);
        $this->extra(['slug' => 'navigation'], [$mini->id, $suv->id, $van->id]);
        $this->extra(['slug' => 'nowhere']); // coupled to no class

        $this->assertEqualsCanonicalizing(['navigation'], Extra::availableFor($mini)->pluck('slug')->all());
        $this->assertEqualsCanonicalizing(['towbar', 'navigation'], Extra::availableFor($suv)->pluck('slug')->all());
        $this->assertEqualsCanonicalizing(['towbar', 'navigation'], Extra::availableFor($van)->pluck('slug')->all());
    }

    public function test_the_coupling_works_from_both_sides_and_ignores_inactive_extras_in_the_checkout_query(): void
    {
        $class = VehicleClass::factory()->create();
        $on = $this->extra(['slug' => 'on'], [$class->id]);
        $off = $this->extra(['slug' => 'off', 'is_active' => false], [$class->id]);

        $this->assertEqualsCanonicalizing(['on', 'off'], $class->extras->pluck('slug')->all());
        $this->assertSame([$class->id], $on->classes->pluck('id')->all());
        $this->assertSame(['on'], Extra::active()->availableFor($class)->pluck('slug')->all());
    }

    public function test_deleting_a_class_or_an_extra_only_removes_the_coupling(): void
    {
        $keep = VehicleClass::factory()->create();
        $drop = VehicleClass::factory()->create();
        $extra = $this->extra([], [$keep->id, $drop->id]);
        $this->assertSame(2, $this->couplings());

        $drop->delete();
        $this->assertSame(1, $this->couplings());
        $this->assertModelExists($extra);

        $extra->delete();
        $this->assertSame(0, $this->couplings());
        $this->assertSame(0, ExtraTranslation::count());
        $this->assertModelExists($keep); // the class stays
    }

    // --- seeder ----------------------------------------------------------------------------------

    public function test_seeder_couples_the_extras_to_classes_and_is_idempotent(): void
    {
        $this->seedAll();
        $this->seed(ExtraSeeder::class);

        $this->assertSame(5, Extra::count());
        $this->assertSame(10, ExtraTranslation::count());

        $slugsOf = fn (string $extra) => Extra::where('slug', $extra)->first()->classes->pluck('slug')->all();

        $this->assertEqualsCanonicalizing(['midsize', 'station-wagon', 'suv', 'mpv-7', 'van-small', 'van-medium', 'van-large'], $slugsOf('towbar'));
        $this->assertEqualsCanonicalizing(['mini', 'compact', 'midsize', 'station-wagon', 'suv', 'electric', 'mpv-7', 'minibus-9'], $slugsOf('child-seat'));
        $this->assertNotContains('box-truck', $slugsOf('winter-tyres'));
        $this->assertCount(12, $slugsOf('navigation'));            // '*' = every class
        $this->assertCount(12, $slugsOf('additional-driver'));

        $this->assertSame('per_time', Extra::where('slug', 'towbar')->value('price_unit'));
        $this->assertSame(3, Extra::where('slug', 'child-seat')->value('max_quantity'));
    }

    public function test_seeder_without_classes_creates_the_extras_but_couples_nothing(): void
    {
        $this->seed(ExtraSeeder::class);

        $this->assertSame(5, Extra::count());
        $this->assertSame(0, $this->couplings());
    }

    // --- admin API -----------------------------------------------------------------------------------

    private function staff(): static
    {
        return $this->actingAs(User::factory()->employee()->create());
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'titles' => ['nl' => 'Winterbanden', 'en' => 'Winter tyres'],
            'descriptions' => ['nl' => 'Extra grip', 'en' => 'Extra grip'],
            'price' => 6, 'price_unit' => 'per_day', 'max_quantity' => 1,
            'classes' => [], 'sort_order' => 10, 'is_active' => true,
        ], $extra);
    }

    public function test_staff_can_create_an_extra_and_couple_it_to_classes(): void
    {
        $category = VehicleCategory::factory()->create();
        $a = VehicleClass::factory()->named('Compact', 'Compact')->create(['vehicle_category_id' => $category->id]);
        $b = VehicleClass::factory()->named('SUV', 'SUV')->create(['vehicle_category_id' => $category->id]);

        $this->staff()->postJson('/admin/api/extras', $this->payload(['classes' => [$a->id, $b->id]]))
            ->assertCreated()
            ->assertJsonPath('slug', 'winterbanden')->assertJsonPath('title', 'Winterbanden')->assertJsonPath('titles.en', 'Winter tyres')
            ->assertJsonPath('price', 6)->assertJsonPath('price_unit_label', 'per dag')
            ->assertJsonPath('classes', [$a->id, $b->id])->assertJsonPath('class_names', ['Compact', 'SUV']);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], Extra::first()->classes->pluck('id')->all());
    }

    public function test_an_extra_without_classes_can_be_saved_but_is_offered_nowhere(): void
    {
        $this->staff()->postJson('/admin/api/extras', $this->payload())->assertCreated()->assertJsonPath('classes', [])->assertJsonPath('class_names', []);

        $this->assertSame([], Extra::availableFor(VehicleClass::factory()->create())->pluck('id')->all());
    }

    public function test_the_quantity_only_applies_to_per_unit_extras(): void
    {
        $this->staff();

        $this->postJson('/admin/api/extras', $this->payload(['max_quantity' => 5]))->assertCreated()->assertJsonPath('max_quantity', 1);
        $this->postJson('/admin/api/extras', $this->payload(['titles' => ['nl' => 'Kinderzitje'], 'price_unit' => 'per_unit', 'max_quantity' => 3]))
            ->assertCreated()->assertJsonPath('max_quantity', 3)->assertJsonPath('price_unit_label', 'per stuk');
    }

    public function test_validation(): void
    {
        $this->staff();

        $this->postJson('/admin/api/extras', [])->assertJsonValidationErrors(['titles.nl', 'price', 'price_unit']);
        $this->postJson('/admin/api/extras', $this->payload(['price' => -1]))->assertJsonValidationErrors('price');
        $this->postJson('/admin/api/extras', $this->payload(['price' => 'veel']))->assertJsonValidationErrors('price');
        $this->postJson('/admin/api/extras', $this->payload(['price_unit' => 'per_week']))->assertJsonValidationErrors('price_unit');
        $this->postJson('/admin/api/extras', $this->payload(['price_unit' => 'per_unit', 'max_quantity' => 99]))->assertJsonValidationErrors('max_quantity');
        $this->postJson('/admin/api/extras', $this->payload(['classes' => [999]]))->assertJsonValidationErrors('classes.0');

        $class = VehicleClass::factory()->create();
        $this->postJson('/admin/api/extras', $this->payload(['classes' => [$class->id, $class->id]]))->assertJsonValidationErrors('classes.0');
        $this->assertSame(0, Extra::count());
    }

    public function test_dutch_title_is_required_but_english_is_optional(): void
    {
        $this->staff();

        $this->postJson('/admin/api/extras', $this->payload(['titles' => ['nl' => 'Trekhaak', 'en' => ''], 'descriptions' => ['nl' => '', 'en' => '']]))
            ->assertCreated()->assertJsonPath('titles.en', '')->assertJsonPath('descriptions.nl', '');
        app()->setLocale('en');
        $this->assertSame('Trekhaak', Extra::first()->title); // falls back to Dutch
    }

    public function test_update_changes_price_unit_classes_and_translations(): void
    {
        $a = VehicleClass::factory()->create();
        $b = VehicleClass::factory()->create();
        $this->staff();
        $id = $this->postJson('/admin/api/extras', $this->payload(['classes' => [$a->id]]))->json('id');

        $this->putJson("/admin/api/extras/$id", $this->payload([
            'titles' => ['nl' => 'Winterbanden plus', 'en' => ''], 'price' => 7.5, 'price_unit' => 'per_time', 'classes' => [$b->id], 'is_active' => false,
        ]))->assertOk()->assertJsonPath('title', 'Winterbanden plus')->assertJsonPath('price', 7.5)->assertJsonPath('price_unit', 'per_time')->assertJsonPath('slug', 'winterbanden');

        $extra = Extra::find($id);
        $this->assertSame([$b->id], $extra->classes->pluck('id')->all()); // coupled to $b only now
        $this->assertSame(1, $extra->translations()->count());           // the emptied English title was removed
        $this->assertFalse($extra->is_active);

        // and coupling can be removed completely
        $this->putJson("/admin/api/extras/$id", $this->payload(['classes' => []]))->assertOk()->assertJsonPath('classes', []);
        $this->assertSame(0, $this->couplings());
    }

    public function test_index_lists_extras_in_order_with_the_classes_grouped_by_category(): void
    {
        $this->seedAll();

        $response = $this->staff()->getJson('/admin/api/extras')->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.slug', 'winter-tyres')->assertJsonPath('data.0.price', 6)
            ->assertJsonPath('options.price_units.1', ['value' => 'per_unit', 'label' => 'per stuk'])
            ->assertJsonCount(4, 'options.class_groups')
            ->assertJsonPath('options.class_groups.0.label', 'Personenauto')
            ->assertJsonCount(6, 'options.class_groups.0.classes')
            ->assertJsonPath('options.class_groups.0.classes.0.label', 'Mini');

        $towbar = collect($response->json('data'))->firstWhere('slug', 'towbar');
        $this->assertCount(7, $towbar['classes']);
        $this->assertContains('SUV', $towbar['class_names']);
        $this->assertNotContains('Mini', $towbar['class_names']);
        $this->assertSame(12, collect($response->json('options.class_groups'))->sum(fn ($g) => count($g['classes'])));
    }

    public function test_clients_and_guests_cannot_manage_extras_and_only_admins_can_delete(): void
    {
        $this->getJson('/admin/api/extras')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson('/admin/api/extras', $this->payload())->assertForbidden();

        $extra = $this->extra();
        $this->actingAs(User::factory()->employee()->create())->deleteJson("/admin/api/extras/{$extra->id}")->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->deleteJson("/admin/api/extras/{$extra->id}")->assertNoContent();
        $this->assertModelMissing($extra);
    }

    // --- checkout (/rent) -----------------------------------------------------------------------------

    public function test_checkout_only_offers_the_extras_coupled_to_the_selected_class(): void
    {
        $this->seedAll();

        // Mini: no tow bar, but a child seat and the extras coupled to every class
        $this->get('/rent?class='.$this->classId('mini'))->assertOk()
            ->assertSee('data-extras', false)
            ->assertSee('Winterbanden', false)->assertSee('Navigatiesysteem', false)->assertSee('Kinderzitje', false)->assertSee('Extra bestuurder', false)
            ->assertDontSee('Trekhaak', false);

        // Middenklasse: has a tow bar
        $this->get('/rent?class='.$this->classId('midsize'))->assertOk()
            ->assertSee('Trekhaak', false)->assertSee('&quot;price_unit&quot;:&quot;per_time&quot;', false)
            ->assertSee('Kinderzitje', false)->assertSee('&quot;price_unit&quot;:&quot;per_unit&quot;', false)->assertSee('&quot;max_quantity&quot;:3', false);

        // 9-seater bus: no tow bar either; a van: tow bar but no child seat
        $this->get('/rent?class='.$this->classId('minibus-9'))->assertOk()->assertSee('Kinderzitje', false)->assertDontSee('Trekhaak', false);
        $this->get('/rent?class='.$this->classId('van-medium'))->assertOk()->assertSee('Trekhaak', false)->assertDontSee('Kinderzitje', false);

        // Box truck: only navigation and an additional driver
        $this->get('/rent?class='.$this->classId('box-truck'))->assertOk()
            ->assertSee('Navigatiesysteem', false)->assertSee('Extra bestuurder', false)
            ->assertDontSee('Winterbanden', false)->assertDontSee('Trekhaak', false)->assertDontSee('Kinderzitje', false);
    }

    public function test_uncoupling_an_extra_removes_it_from_that_classs_checkout(): void
    {
        $this->seedAll();
        $towbar = Extra::where('slug', 'towbar')->first();
        $suv = $this->classId('suv');

        $this->get("/rent?class=$suv")->assertSee('Trekhaak', false);

        $towbar->classes()->detach($suv);
        $this->get("/rent?class=$suv")->assertDontSee('Trekhaak', false);
        $this->get('/rent?class='.$this->classId('midsize'))->assertSee('Trekhaak', false); // other classes keep it
    }

    public function test_checkout_extras_follow_the_language_and_hide_inactive_ones(): void
    {
        $this->seedAll();
        Extra::where('slug', 'navigation')->update(['is_active' => false]);

        $this->withUnencryptedCookie('locale', 'en')->get('/rent?class='.$this->classId('midsize'))
            ->assertSee('Winter tyres', false)->assertSee('Tow bar', false)
            ->assertDontSee('Navigation system', false)->assertDontSee('Winterbanden', false);
    }

    public function test_a_class_without_coupled_extras_still_checks_out(): void
    {
        $this->seed([VehicleCategorySeeder::class, VehicleClassSeeder::class]);

        $this->get('/rent?class='.$this->classId('compact'))->assertOk()->assertSee('data-extras="[]"', false);
    }
}
