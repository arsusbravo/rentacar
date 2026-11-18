<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The prices of one vehicle class for one fuel type and transmission (excluding VAT). */
#[Fillable(['vehicle_class_id', 'fuel_type', 'transmission', 'price_half_day', 'price_day', 'price_week', 'price_month', 'price_year', 'price_km'])]
class VehicleClassRate extends Model
{
    /** The time-based tariff columns, shortest to longest. */
    public const TARIFFS = ['price_half_day', 'price_day', 'price_week', 'price_month', 'price_year'];

    /** All price columns. */
    public const PRICES = [...self::TARIFFS, 'price_km'];

    protected function casts(): array
    {
        return array_fill_keys(self::PRICES, 'decimal:2');
    }

    public function vehicleClass(): BelongsTo
    {
        return $this->belongsTo(VehicleClass::class, 'vehicle_class_id');
    }

    /** A price in cents, or null when that tariff does not exist. */
    public function cents(string $column): ?int
    {
        $value = $this->{$column};

        return $value === null ? null : (int) round(((float) $value) * 100);
    }

    /** True when at least one time-based tariff exists, so a rental can be priced. */
    public function isPriced(): bool
    {
        return collect(self::TARIFFS)->contains(fn (string $c) => $this->{$c} !== null);
    }
}
