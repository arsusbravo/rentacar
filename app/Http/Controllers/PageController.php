<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Extra;
use App\Models\VehicleCategory;
use App\Models\VehicleClass;
use App\Pricing\ExampleQuote;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/** Public pages (server-rendered Blade). */
class PageController extends Controller
{
    public function home(): View
    {
        $categories = $this->categories(withSpecs: true); // the prices need the rates and options

        return view('home', [
            'branches' => Branch::publicList(),
            'categories' => $categories,
            'categoryOptions' => $this->categoryOptions($categories),
            'example' => ExampleQuote::make(), // worked example for "how your price is calculated"
        ]);
    }

    /**
     * Search results: the vehicle classes (optionally of one category) with the search form on top.
     * Selecting a vehicle leads to the checkout (/rent).
     */
    public function vehicles(Request $request): View
    {
        $categories = $this->categories(withSpecs: true);

        $selected = $categories->firstWhere('slug', $request->query('category'));
        $query = $this->searchQuery($request);

        return view('vehicles', [
            'branches' => Branch::publicList(),
            'categoryOptions' => $this->categoryOptions($categories),
            'selected' => $selected,
            'groups' => $selected ? collect([$selected]) : $categories,
            'rentQuery' => $query,
            'initial' => $this->initial($query) + ['category' => $selected?->slug],
        ]);
    }

    public function locations(): View
    {
        return view('locations', ['branches' => Branch::publicList()]);
    }

    /**
     * Rental checkout (Vue), reached after selecting a vehicle class. Without a (valid) class the
     * client is sent back to the vehicle choice, keeping the search.
     */
    public function rent(Request $request): View|RedirectResponse
    {
        $class = VehicleClass::active()
            ->whereHas('category', fn ($q) => $q->active())
            ->with(['translations', 'transmissionOptions', 'fuelOptions', 'rates', 'category.translations'])
            ->find($request->integer('class'));

        $query = $this->searchQuery($request);

        if (! $class) {
            return redirect()->route('vehicles.index', $query);
        }

        return view('rent', [
            'branches' => Branch::publicList(),
            'initial' => $this->initial($query),
            // Only the extras that are coupled to this vehicle class.
            'extras' => Extra::active()->availableFor($class)->ordered()->with('translations')->get()
                ->map->toCheckoutArray()->values(),
            'vehiclesUrl' => route('vehicles.index', $query + ['category' => $class->category->slug]),
            'vehicleClass' => $class->toCheckoutArray() + [
                // fuel and transmission: from the URL when the class offers it, otherwise the default (petrol, manual)
                'selected_fuel' => in_array($request->query('fuel'), $class->fuelTypes(), true) ? $request->query('fuel') : $class->defaultFuelType(),
                'selected_transmission' => in_array($request->query('transmission'), $class->transmissions(), true) ? $request->query('transmission') : $class->defaultTransmission(),
            ],
        ]);
    }

    public function locale(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, config('app.available_locales'), true), 404);

        // Only go back to a page on this site.
        $previous = url()->previous();
        $back = parse_url($previous, PHP_URL_HOST) === $request->getHost() ? $previous : url('/');

        return redirect($back)->withCookie(cookie('locale', $locale, 60 * 24 * 365));
    }

    // ---------------------------------------------------------------------------------------------

    /** Active categories that have at least one active class (names in the current language). */
    private function categories(bool $withSpecs = false): Collection
    {
        $classRelations = $withSpecs ? ['translations', 'transmissionOptions', 'fuelOptions', 'rates'] : ['translations'];

        return VehicleCategory::active()->ordered()
            ->with(['translations', 'classes' => fn ($q) => $q->active()->with($classRelations)])
            ->get()
            ->filter(fn (VehicleCategory $category) => $category->classes->isNotEmpty())
            ->values();
    }

    private function categoryOptions(Collection $categories): Collection
    {
        return $categories->map(fn (VehicleCategory $c) => [
            'id' => $c->id,
            'slug' => $c->slug,
            'name' => $c->name,
            'icon' => $this->categoryIcon($c),
        ])->values();
    }

    /**
     * Icon of a category: storage/app/public/images/category_icons/<slug>.png (a transparent silhouette).
     * null when there is none, so a new category without an icon still works.
     */
    private function categoryIcon(VehicleCategory $category): ?string
    {
        $path = "images/category_icons/{$category->slug}.png";

        return Storage::disk('public')->exists($path) ? asset("storage/$path") : null;
    }

    /** The search choices from the URL, only well-formed values (snake_case, as used in URLs). */
    private function searchQuery(Request $request): array
    {
        $pick = fn (string $key, string $pattern) => preg_match($pattern, (string) $request->query($key)) ? $request->query($key) : null;

        return array_filter([
            'pickup' => $request->integer('pickup') ?: null,
            'return' => $request->integer('return') ?: null,
            'pickup_date' => $pick('pickup_date', '/^\d{4}-\d{2}-\d{2}$/'),
            'pickup_time' => $pick('pickup_time', '/^\d{2}:\d{2}$/'),
            'return_date' => $pick('return_date', '/^\d{4}-\d{2}-\d{2}$/'),
            'return_time' => $pick('return_time', '/^\d{2}:\d{2}$/'),
        ], fn ($value) => $value !== null);
    }

    /** The same choices as the Vue form expects them (camelCase, null when missing). */
    private function initial(array $query): array
    {
        return [
            'pickup' => $query['pickup'] ?? null,
            'return' => $query['return'] ?? null,
            'pickupDate' => $query['pickup_date'] ?? null,
            'pickupTime' => $query['pickup_time'] ?? null,
            'returnDate' => $query['return_date'] ?? null,
            'returnTime' => $query['return_time'] ?? null,
        ];
    }
}
