<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesImage;
use App\Models\VehicleCategory;
use App\Models\VehicleClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Back office: manage vehicle classes (specs, offered transmissions, names/descriptions per language, image). */
class VehicleClassController extends Controller
{
    use ManagesImage;

    /** All classes plus the option lists the form needs (labels in the current language). */
    public function index(): JsonResponse
    {
        $classes = VehicleClass::with(['translations', 'transmissionOptions', 'fuelOptions', 'category.translations'])
            ->join('vehicle_categories', 'vehicle_categories.id', '=', 'vehicle_classes.vehicle_category_id')
            ->orderBy('vehicle_categories.sort_order')->orderBy('vehicle_classes.sort_order')->orderBy('vehicle_classes.id')
            ->select('vehicle_classes.*')
            ->get();

        return response()->json([
            'data' => $classes->map(fn (VehicleClass $c) => $this->present($c))->values(),
            'options' => [
                'categories' => VehicleCategory::ordered()->with('translations')->get()
                    ->map(fn (VehicleCategory $c) => ['value' => $c->id, 'label' => $c->name]),
                'fuel_types' => $this->options('fuel_types'),
                'transmissions' => $this->options('transmissions'),
                'license_categories' => $this->options('license_categories'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $class = DB::transaction(function () use ($request, $data) {
            $class = VehicleClass::create(
                $this->attributes($data)
                + $this->imageAttributes($request, 'classes')
                + ['slug' => $this->uniqueSlug($data['names'][$this->defaultLocale()])]
            );
            $this->saveRelations($class, $data);

            return $class;
        });

        return response()->json($this->present($this->fresh($class)), 201);
    }

    public function update(Request $request, VehicleClass $class): JsonResponse
    {
        $data = $request->validate($this->rules());

        DB::transaction(function () use ($request, $class, $data) {
            $class->update($this->attributes($data) + $this->imageAttributes($request, 'classes', $class));
            $this->saveRelations($class, $data);
        });

        return response()->json($this->present($this->fresh($class)));
    }

    public function destroy(Request $request, VehicleClass $class): Response
    {
        abort_unless($request->user()->isAdmin(), 403);

        $this->deleteImage($class);
        $class->delete();

        return response()->noContent();
    }

    // ---------------------------------------------------------------------------------------------

    private function rules(): array
    {
        // Specs come from the model's rules; the slug is generated, names/descriptions are per language.
        return Arr::except(VehicleClass::rules(), ['slug', 'baggage_large', 'baggage_small', 'sort_order', 'is_active']) + [
            'names' => ['required', 'array'],
            'names.'.$this->defaultLocale() => ['required', 'string', 'max:191'], // other languages fall back to it
            'names.*' => ['nullable', 'string', 'max:191'],
            'descriptions' => ['nullable', 'array'],
            'descriptions.*' => ['nullable', 'string', 'max:2000'],
            'baggage_large' => ['nullable', 'integer', 'min:0', 'max:255'],
            'baggage_small' => ['nullable', 'integer', 'min:0', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
            'vehicle_category_id' => ['required', Rule::exists('vehicle_categories', 'id')],
        ] + $this->imageRules();
    }

    /** Columns of vehicle_classes (without the generated slug). */
    private function attributes(array $data): array
    {
        return Arr::only($data, [
            'vehicle_category_id', 'example_models', 'license_category', 'seats', 'doors',
            'cargo_volume_liters', 'payload_kg',
        ]) + [
            'baggage_large' => $data['baggage_large'] ?? 0,
            'baggage_small' => $data['baggage_small'] ?? 0,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private function saveRelations(VehicleClass $class, array $data): void
    {
        $class->setTransmissions($data['transmissions']);
        $class->setFuelTypes($data['fuel_types']);

        foreach (config('app.available_locales') as $locale) {
            $name = trim((string) ($data['names'][$locale] ?? ''));

            if ($name === '') {
                $class->translations()->where('locale', $locale)->delete();
            } else {
                $class->setTranslation($locale, [
                    'name' => $name,
                    'description' => trim((string) ($data['descriptions'][$locale] ?? '')) ?: null,
                ]);
            }
        }
    }

    private function defaultLocale(): string
    {
        return config('app.available_locales')[0];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'class';
        $slug = $base;

        for ($i = 2; VehicleClass::where('slug', $slug)->exists(); $i++) {
            $slug = "$base-$i";
        }

        return $slug;
    }

    private function options(string $list): array
    {
        return array_map(fn (string $value) => ['value' => $value, 'label' => __("vehicles.$list.$value")], config("vehicles.$list"));
    }

    private function fresh(VehicleClass $class): VehicleClass
    {
        return $class->load(['translations', 'transmissionOptions', 'fuelOptions', 'category.translations']);
    }

    private function present(VehicleClass $class): array
    {
        $locales = config('app.available_locales');
        $translation = fn (string $locale) => $class->translations->firstWhere('locale', $locale);

        return [
            'id' => $class->id,
            'slug' => $class->slug,
            'name' => $class->name, // current language
            'names' => collect($locales)->mapWithKeys(fn ($l) => [$l => $translation($l)?->name ?? ''])->all(),
            'descriptions' => collect($locales)->mapWithKeys(fn ($l) => [$l => $translation($l)?->description ?? ''])->all(),
            'vehicle_category_id' => $class->vehicle_category_id,
            'category_name' => $class->category->name,
            'example_models' => $class->example_models,
            'fuel_types' => $class->fuelTypes(),
            'fuel_labels' => $class->fuelLabels(),
            'transmissions' => $class->transmissions(),
            'transmission_labels' => $class->transmissionLabels(),
            'license_category' => $class->license_category,
            'seats' => $class->seats,
            'doors' => $class->doors,
            'baggage_large' => $class->baggage_large,
            'baggage_small' => $class->baggage_small,
            'cargo_volume_liters' => $class->cargo_volume_liters,
            'payload_kg' => $class->payload_kg,
            'sort_order' => $class->sort_order,
            'is_active' => $class->is_active,
            'has_image' => (bool) $class->image_path,
            'image_url' => $class->imageUrl(),
        ];
    }
}
