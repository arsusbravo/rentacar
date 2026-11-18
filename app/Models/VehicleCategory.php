<?php

namespace App\Models;

use App\Models\Concerns\HasImage;
use App\Models\Concerns\HasTranslations;
use Database\Factories\VehicleCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kind of vehicle: personenauto, personenbus, bestelwagen, ... */
#[Fillable(['slug', 'image_path', 'sort_order', 'is_active'])]
class VehicleCategory extends Model
{
    /** @use HasFactory<VehicleCategoryFactory> */
    use HasFactory, HasImage, HasTranslations;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function classes(): HasMany
    {
        return $this->hasMany(VehicleClass::class)->orderBy('sort_order');
    }

    /** Lowest day price (cents, excl. VAT) over the active classes that have prices ("from EUR x per day"), or null. */
    public function fromDayPriceCents(): ?int
    {
        return $this->classes
            ->filter(fn (VehicleClass $c) => $c->is_active)
            ->map(fn (VehicleClass $c) => $c->fromDayPriceCents())
            ->filter(fn ($cents) => $cents !== null)
            ->min();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
