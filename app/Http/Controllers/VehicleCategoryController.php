<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesImage;
use App\Models\VehicleCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Back office: manage vehicle categories (names per language + image upload). */
class VehicleCategoryController extends Controller
{
    use ManagesImage;

    public function index(): JsonResponse
    {
        $categories = VehicleCategory::ordered()->with('translations')->withCount('classes')->get();

        return response()->json($categories->map(fn (VehicleCategory $c) => $this->present($c)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $category = DB::transaction(function () use ($request, $data) {
            $category = VehicleCategory::create([
                'slug' => $this->uniqueSlug($data['names']['nl']),
                'sort_order' => $data['sort_order'] ?? 0,
                'is_active' => $request->boolean('is_active', true),
                'image_path' => $this->imageAttributes($request, 'categories')['image_path'] ?? null,
            ]);
            $this->saveNames($category, $data['names']);

            return $category;
        });

        return response()->json($this->present($category->load('translations')->loadCount('classes')), 201);
    }

    public function update(Request $request, VehicleCategory $category): JsonResponse
    {
        $data = $request->validate($this->rules());

        DB::transaction(function () use ($request, $data, $category) {
            $attributes = [
                'sort_order' => $data['sort_order'] ?? $category->sort_order,
                'is_active' => $request->boolean('is_active', $category->is_active),
            ];

            $attributes += $this->imageAttributes($request, 'categories', $category);

            $category->update($attributes);
            $this->saveNames($category, $data['names']);
        });

        return response()->json($this->present($category->load('translations')->loadCount('classes')));
    }

    public function destroy(Request $request, VehicleCategory $category): Response|JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        if ($category->classes()->exists()) {
            return response()->json(['message' => __('This category still has vehicle classes and cannot be deleted.')], 422);
        }

        $this->deleteImage($category);
        $category->delete();

        return response()->noContent();
    }

    private function rules(): array
    {
        $locales = config('app.available_locales');

        return [
            'names' => ['required', 'array'],
            // Dutch (the default language) is required; other languages are optional and fall back to it.
            'names.'.$locales[0] => ['required', 'string', 'max:191'],
            'names.*' => ['nullable', 'string', 'max:191'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
        ] + $this->imageRules();
    }

    private function saveNames(VehicleCategory $category, array $names): void
    {
        foreach (config('app.available_locales') as $locale) {
            $name = trim((string) ($names[$locale] ?? ''));

            if ($name === '') {
                $category->translations()->where('locale', $locale)->delete();
            } else {
                $category->setTranslation($locale, ['name' => $name]);
            }
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;

        for ($i = 2; VehicleCategory::where('slug', $slug)->exists(); $i++) {
            $slug = "$base-$i";
        }

        return $slug;
    }

    private function present(VehicleCategory $category): array
    {
        return [
            'id' => $category->id,
            'slug' => $category->slug,
            'name' => $category->name, // in the current language
            'names' => collect(config('app.available_locales'))
                ->mapWithKeys(fn ($l) => [$l => $category->translations->firstWhere('locale', $l)?->name ?? ''])->all(),
            'sort_order' => $category->sort_order,
            'is_active' => $category->is_active,
            'has_image' => (bool) $category->image_path,
            'image_url' => $category->imageUrl(),
            'classes_count' => $category->classes_count ?? 0,
        ];
    }
}
