<?php

namespace App\Models;

use App\Models\Concerns\HasImage;
use App\Models\Concerns\HasTranslations;
use Database\Factories\VehicleClassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

/** A group of similar vehicles within a category ("VW Polo or similar"). */
#[Fillable([
    'vehicle_category_id', 'slug', 'image_path', 'example_models', 'license_category',
    'seats', 'doors', 'baggage_large', 'baggage_small', 'cargo_volume_liters', 'payload_kg',
    'own_risk', 'own_risk_reduced', 'own_risk_reduction_price', 'deposit', 'km_included', 'sort_order', 'is_active',
])]
class VehicleClass extends Model
{
    /** @use HasFactory<VehicleClassFactory> */
    use HasFactory, HasImage, HasTranslations;

    protected function casts(): array
    {
        return [
            'seats' => 'integer',
            'doors' => 'integer',
            'baggage_large' => 'integer',
            'baggage_small' => 'integer',
            'cargo_volume_liters' => 'integer',
            'payload_kg' => 'integer',
            'own_risk' => 'decimal:2',
            'own_risk_reduced' => 'decimal:2',
            'own_risk_reduction_price' => 'decimal:2',
            'deposit' => 'decimal:2',
            'km_included' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class, 'vehicle_category_id');
    }

    // --- prices (all excluding VAT) ----------------------------------------------------------------

    public function rates(): HasMany
    {
        return $this->hasMany(VehicleClassRate::class);
    }

    /**
     * Every fuel type x transmission combination this class is offered with: one price set each.
     *
     * @return list<array{fuel_type: string, transmission: string}>
     */
    public function combinations(): array
    {
        $combinations = [];

        foreach ($this->fuelTypes() as $fuel) {
            foreach ($this->transmissions() as $transmission) {
                $combinations[] = ['fuel_type' => $fuel, 'transmission' => $transmission];
            }
        }

        return $combinations;
    }

    public function offers(string $fuel, string $transmission): bool
    {
        return in_array($fuel, $this->fuelTypes(), true) && in_array($transmission, $this->transmissions(), true);
    }

    /** The price set for a combination the class currently offers, if one was entered. */
    public function rateFor(string $fuel, string $transmission): ?VehicleClassRate
    {
        return $this->offers($fuel, $transmission)
            ? $this->rates->first(fn (VehicleClassRate $r) => $r->fuel_type === $fuel && $r->transmission === $transmission)
            : null;
    }

    /** Remove price sets of combinations the class no longer offers (after its fuel/transmission options changed). */
    public function pruneRates(): static
    {
        $valid = collect($this->combinations())->map(fn ($c) => $c['fuel_type'].'|'.$c['transmission']);

        $this->rates()->get()
            ->reject(fn (VehicleClassRate $r) => $valid->contains($r->fuel_type.'|'.$r->transmission))
            ->each->delete();
        $this->unsetRelation('rates');

        return $this;
    }

    /** Lowest day price in cents over the offered combinations ("from EUR x per day"), or null without prices. */
    public function fromDayPriceCents(): ?int
    {
        return collect($this->combinations())
            ->map(fn ($c) => $this->rateFor($c['fuel_type'], $c['transmission'])?->cents('price_day'))
            ->filter(fn ($cents) => $cents !== null)
            ->min();
    }

    /** Base deposit in cents (null = none set: the deposit then follows from the price and the own risk). */
    public function depositCents(): ?int
    {
        return $this->deposit === null ? null : (int) round(((float) $this->deposit) * 100);
    }

    public function ownRiskCents(): ?int
    {
        return $this->own_risk === null ? null : (int) round(((float) $this->own_risk) * 100);
    }

    /** The client can lower the own risk: a lower amount and a price per day were both entered. */
    public function ownRiskReductionOffered(): bool
    {
        return $this->own_risk_reduced !== null && $this->own_risk_reduction_price !== null;
    }

    public function ownRiskReducedCents(): ?int
    {
        return $this->own_risk_reduced === null ? null : (int) round(((float) $this->own_risk_reduced) * 100);
    }

    public function ownRiskReductionPriceCents(): ?int
    {
        return $this->own_risk_reduction_price === null ? null : (int) round(((float) $this->own_risk_reduction_price) * 100);
    }

    // --- options a client chooses from: transmissions and fuel types (several per class) -------------

    /** Extras coupled to this class (tow bar, navigation, ...). */
    public function extras(): BelongsToMany
    {
        return $this->belongsToMany(Extra::class, 'extra_vehicle_class');
    }

    public function transmissionOptions(): HasMany
    {
        return $this->hasMany(VehicleClassTransmission::class);
    }

    public function fuelOptions(): HasMany
    {
        return $this->hasMany(VehicleClassFuelType::class);
    }

    /**
     * Transmissions this class is offered with, e.g. ['manual', 'automatic'],
     * in the order of config('vehicles.transmissions').
     *
     * @return list<string>
     */
    public function transmissions(): array
    {
        return $this->choices('transmissionOptions', 'transmission', 'transmissions');
    }

    /** Fuel types this class is offered with, in the order of config('vehicles.fuel_types'). */
    public function fuelTypes(): array
    {
        return $this->choices('fuelOptions', 'fuel_type', 'fuel_types');
    }

    /** The fuel type preselected for the client: the configured default if offered, else the first one. */
    public function defaultFuelType(): ?string
    {
        $offered = $this->fuelTypes();

        return in_array(config('vehicles.default_fuel_type'), $offered, true) ? config('vehicles.default_fuel_type') : ($offered[0] ?? null);
    }

    /** The transmission preselected for the client: the configured default if offered, else the first one. */
    public function defaultTransmission(): ?string
    {
        $offered = $this->transmissions();

        return in_array(config('vehicles.default_transmission'), $offered, true) ? config('vehicles.default_transmission') : ($offered[0] ?? null);
    }

    /** True when the client has to pick one (more than one is offered). */
    public function requiresTransmissionChoice(): bool
    {
        return count($this->transmissions()) > 1;
    }

    public function requiresFuelChoice(): bool
    {
        return count($this->fuelTypes()) > 1;
    }

    /** Replace the offered transmissions / fuel types. */
    public function setTransmissions(array $transmissions): static
    {
        return $this->syncChoices('transmissionOptions', 'transmission', $transmissions)->pruneRates();
    }

    public function setFuelTypes(array $fuelTypes): static
    {
        return $this->syncChoices('fuelOptions', 'fuel_type', $fuelTypes)->pruneRates();
    }

    /** @return list<string> */
    private function choices(string $relation, string $column, string $configKey): array
    {
        $order = array_flip(config("vehicles.$configKey"));

        return $this->{$relation}->pluck($column)
            ->sortBy(fn (string $value) => $order[$value] ?? PHP_INT_MAX)->values()->all();
    }

    private function syncChoices(string $relation, string $column, array $values): static
    {
        $values = array_values(array_unique($values));

        $this->{$relation}()->whereNotIn($column, $values)->delete();
        foreach ($values as $value) {
            $this->{$relation}()->firstOrCreate([$column => $value]);
        }
        $this->unsetRelation($relation);

        return $this;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** Translated labels of the offered fuel types, e.g. ["Benzine", "Diesel"]. */
    public function fuelLabels(): array
    {
        return array_map(fn (string $f) => __('vehicles.fuel_types.'.$f), $this->fuelTypes());
    }

    /** Translated labels of the offered transmissions, e.g. ["Handgeschakeld", "Automaat"]. */
    public function transmissionLabels(): array
    {
        return array_map(fn (string $t) => __('vehicles.transmissions.'.$t), $this->transmissions());
    }

    public function licenseLabel(): string
    {
        return __('vehicles.license_categories.'.$this->license_category);
    }

    /** What the public checkout needs to show and choose (names and labels in the current language). */
    public function toCheckoutArray(): array
    {
        $labelled = fn (string $list, array $values) => array_map(
            fn (string $v) => ['value' => $v, 'label' => __("vehicles.$list.$v")],
            $values,
        );

        return [
            'id' => $this->id,
            'name' => $this->name,
            'example_models' => $this->example_models,
            'image_url' => $this->imageUrl(),
            'category_name' => $this->category->name,
            'fuel_types' => $labelled('fuel_types', $this->fuelTypes()),
            'transmissions' => $labelled('transmissions', $this->transmissions()),
            'default_fuel_type' => $this->defaultFuelType(),
            'default_transmission' => $this->defaultTransmission(),
            'priced' => $this->fromDayPriceCents() !== null,
            'km_included' => (int) $this->km_included,
            'seats' => $this->seats, 'doors' => $this->doors,
            'baggage_large' => $this->baggage_large, 'baggage_small' => $this->baggage_small,
            'cargo_volume_liters' => $this->cargo_volume_liters, 'payload_kg' => $this->payload_kg,
            'license_category' => $this->license_category,
            'km_extra_max' => (int) config('pricing.km.max_extra'),
            // own risk (excluding VAT) and, when offered, what lowering it costs per rental day
            'own_risk' => $this->ownRiskCents() === null ? null : $this->ownRiskCents() / 100,
            'own_risk_reduction' => $this->ownRiskReductionOffered()
                ? ['reduced' => $this->ownRiskReducedCents() / 100, 'price_per_day' => $this->ownRiskReductionPriceCents() / 100]
                : null,
        ];
    }

    /** Validation rules for creating/updating a class (to be used by the admin forms). */
    public static function rules(): array
    {
        return [
            'vehicle_category_id' => ['required', 'exists:vehicle_categories,id'],
            'slug' => ['required', 'string', 'max:191'],
            'example_models' => ['required', 'string', 'max:255'],
            'fuel_types' => ['required', 'array', 'min:1'],
            'fuel_types.*' => ['distinct', Rule::in(config('vehicles.fuel_types'))],
            'transmissions' => ['required', 'array', 'min:1'],
            'transmissions.*' => ['distinct', Rule::in(config('vehicles.transmissions'))],
            'license_category' => ['required', Rule::in(config('vehicles.license_categories'))],
            'seats' => ['required', 'integer', 'between:1,255'],
            'doors' => ['nullable', 'integer', 'between:1,10'],
            'baggage_large' => ['integer', 'min:0', 'max:255'],
            'baggage_small' => ['integer', 'min:0', 'max:255'],
            'cargo_volume_liters' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'payload_kg' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }
}
