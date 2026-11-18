<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BranchImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function branch(): Branch
    {
        return Branch::create(['name' => 'Rentacar Rotterdam', 'city' => 'Rotterdam', 'address' => 'Stationsplein 1', 'is_active' => true]);
    }

    public function test_staff_can_upload_replace_and_remove_a_branch_picture(): void
    {
        $branch = $this->branch();
        $this->actingAs(User::factory()->employee()->create());

        $first = $this->postJson("/admin/api/branches/{$branch->id}/image", ['image' => UploadedFile::fake()->image('a.jpg', 600, 400)])->assertOk();
        $path = $branch->fresh()->image_path;
        Storage::disk('public')->assertExists($path);
        $this->assertStringContainsString('/storage/'.$path, $first->json('image_url'));

        $this->postJson("/admin/api/branches/{$branch->id}/image", ['image' => UploadedFile::fake()->image('b.png', 600, 400)])->assertOk();
        Storage::disk('public')->assertMissing($path);                   // the old file is gone
        $new = $branch->fresh()->image_path;
        Storage::disk('public')->assertExists($new);

        $this->deleteJson("/admin/api/branches/{$branch->id}/image")->assertOk()->assertJsonPath('image_url', null);
        Storage::disk('public')->assertMissing($new);
        $this->assertNull($branch->fresh()->image_path);
    }

    public function test_the_picture_is_validated_and_staff_only(): void
    {
        $branch = $this->branch();
        $url = "/admin/api/branches/{$branch->id}/image";

        $this->postJson($url, [])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson($url, ['image' => UploadedFile::fake()->image('a.jpg')])->assertForbidden();

        $this->actingAs(User::factory()->employee()->create());
        $this->postJson($url, [])->assertJsonValidationErrors('image');
        $this->postJson($url, ['image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertJsonValidationErrors('image');
        $this->postJson($url, ['image' => UploadedFile::fake()->image('big.jpg')->size(5000)])->assertJsonValidationErrors('image');
        $this->assertNull($branch->fresh()->image_path);
    }

    public function test_the_locations_page_shows_the_picture_or_a_placeholder(): void
    {
        $with = $this->branch();
        $with->update(['image_path' => 'branches/rotterdam.jpg']);
        Branch::create(['name' => 'Rentacar Delft', 'city' => 'Delft', 'address' => 'Markt 1', 'is_active' => true]);

        $this->get('/locations')->assertOk()
            ->assertSee('/storage/branches/rotterdam.jpg', false)
            ->assertSee('images/branch-placeholder.svg', false)
            ->assertSee('Foto van Rentacar Rotterdam');
    }

    public function test_deleting_a_branch_removes_its_picture(): void
    {
        $branch = $this->branch();
        $this->actingAs(User::factory()->employee()->create())->postJson("/admin/api/branches/{$branch->id}/image", ['image' => UploadedFile::fake()->image('a.jpg')]);
        $path = $branch->fresh()->image_path;

        $this->actingAs(User::factory()->admin()->create())->deleteJson("/admin/api/branches/{$branch->id}")->assertNoContent();
        Storage::disk('public')->assertMissing($path);
    }

    public function test_the_branch_seeder_attaches_the_seed_pictures_once_and_keeps_uploads(): void
    {
        $this->seed(\Database\Seeders\BranchSeeder::class);

        $branches = Branch::orderBy('city')->get()->keyBy('city');
        $this->assertSame('branches/rotterdam.jpg', $branches['Rotterdam']->image_path);
        $this->assertSame('branches/amsterdam.jpg', $branches['Amsterdam']->image_path);
        $this->assertSame('branches/den-haag.jpg', $branches['Den Haag']->image_path);
        foreach ($branches as $branch) {
            Storage::disk('public')->assertExists($branch->image_path);
        }

        // an uploaded picture is never replaced by a re-run
        $branches['Rotterdam']->update(['image_path' => 'branches/own.jpg']);
        $this->seed(\Database\Seeders\BranchSeeder::class);
        $this->assertSame('branches/own.jpg', $branches['Rotterdam']->fresh()->image_path);
    }
}
