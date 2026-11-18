<?php

namespace App\Http\Controllers;

use App\Models\Extra;
use App\Models\VehicleCategory;
use App\Models\VehicleClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Back office: manage the extras (title/description per language, price, price unit, the classes it is coupled to). */
class ExtraController extends Controller
{
    public function index(): JsonResponse
    {
        $extras = Extra::ordered()->with(['translations', 'classes.translations'])->get();

        return response()->json([
            'data' => $extras->map(fn (Extra $e) => $this->present($e))->values(),
            'options' => [
                'price_units' => array_map(fn (string $u) => ['value' => $u, 'label' => __("extras.price_units.$u")], config('extras.price_units')),
                // every class, grouped by category, to couple the extra to
                'class_groups' => VehicleCategory::ordered()->with(['translations', 'classes.translations'])->get()
                    ->filter(fn (VehicleCategory $c) => $c->classes->isNotEmpty())
                    ->map(fn (VehicleCategory $c) => [
                        'category_id' => $c->id,
                        'label' => $c->name,
                        'classes' => $c->classes->map(fn (VehicleClass $class) => ['value' => $class->id, 'label' => $class->name])->values(),
                    ])->values(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $extra = DB::transaction(function () use ($data) {
            $extra = Extra::create($this->attributes($data) + ['slug' => $this->uniqueSlug($data['titles'][$this->defaultLocale()])]);
            $this->saveRelations($extra, $data);

            return $extra;
        });

        return response()->json($this->present($extra->load(['translations', 'classes.translations'])), 201);
    }

    public function update(Request $request, Extra $extra): JsonResponse
    {
        $data = $request->validate($this->rules());

        DB::transaction(function () use ($extra, $data) {
            $extra->update($this->attributes($data));
            $this->saveRelations($extra, $data);
        });

        return response()->json($this->present($extra->load(['translations', 'classes.translations'])));
    }

    public function destroy(Request $request, Extra $extra): Response
    {
        abort_unless($request->user()->isAdmin(), 403);

        $extra->delete();

        return response()->noContent();
    }

    // ---------------------------------------------------------------------------------------------

    private function rules(): array
    {
        return Extra::rules() + [
            'titles' => ['required', 'array'],
            'titles.'.$this->defaultLocale() => ['required', 'string', 'max:191'], // other languages fall back to it
            'titles.*' => ['nullable', 'string', 'max:191'],
            'descriptions' => ['nullable', 'array'],
            'descriptions.*' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function attributes(array $data): array
    {
        $perUnit = $data['price_unit'] === 'per_unit';

        return Arr::only($data, ['price', 'price_unit']) + [
            // a quantity is only chosen for per-unit extras
            'max_quantity' => $perUnit ? ($data['max_quantity'] ?? 1) : 1,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private function saveRelations(Extra $extra, array $data): void
    {
        $extra->classes()->sync($data['classes'] ?? []);

        foreach (config('app.available_locales') as $locale) {
            $title = trim((string) ($data['titles'][$locale] ?? ''));

            if ($title === '') {
                $extra->translations()->where('locale', $locale)->delete();
            } else {
                $extra->setTranslation($locale, [
                    'title' => $title,
                    'description' => trim((string) ($data['descriptions'][$locale] ?? '')) ?: null,
                ]);
            }
        }
    }

    private function defaultLocale(): string
    {
        return config('app.available_locales')[0];
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'extra';
        $slug = $base;

        for ($i = 2; Extra::where('slug', $slug)->exists(); $i++) {
            $slug = "$base-$i";
        }

        return $slug;
    }

    private function present(Extra $extra): array
    {
        $locales = config('app.available_locales');
        $translation = fn (string $locale) => $extra->translations->firstWhere('locale', $locale);

        return [
            'id' => $extra->id,
            'slug' => $extra->slug,
            'title' => $extra->title, // current language
            'titles' => collect($locales)->mapWithKeys(fn ($l) => [$l => $translation($l)?->title ?? ''])->all(),
            'descriptions' => collect($locales)->mapWithKeys(fn ($l) => [$l => $translation($l)?->description ?? ''])->all(),
            'price' => (float) $extra->price,
            'price_unit' => $extra->price_unit,
            'price_unit_label' => __("extras.price_units.{$extra->price_unit}"),
            'max_quantity' => $extra->max_quantity,
            'classes' => $extra->classes->pluck('id')->values()->all(),
            'class_names' => $extra->classes->map->name->values()->all(),
            'sort_order' => $extra->sort_order,
            'is_active' => $extra->is_active,
        ];
    }
}
