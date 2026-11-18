<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Validation\Rule;

/** An option that can be added to a rental (winter tyres, navigation, tow bar, ...). */
#[Fillable(['slug', 'price', 'price_unit', 'max_quantity', 'sort_order', 'is_active'])]
class Extra extends Model
{
    use HasTranslations;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'max_quantity' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** Translated title (current language, falling back to Dutch, then the slug). */
    protected function title(): Attribute
    {
        return Attribute::get(fn () => $this->translate('title') ?? $this->slug);
    }

    /** The vehicle classes this extra is coupled to: it is only offered for those. */
    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(VehicleClass::class, 'extra_vehicle_class');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** Extras coupled to a vehicle class. */
    public function scopeAvailableFor(Builder $query, VehicleClass $class): Builder
    {
        return $query->whereHas('classes', fn (Builder $c) => $c->whereKey($class->getKey()));
    }

    public function priceCents(): int
    {
        return (int) round(((float) $this->price) * 100);
    }

    /**
     * Total price in cents for a rental of $days days.
     *   per_day  : price x days
     *   per_unit : price x quantity (capped at max_quantity)
     *   per_time : price once
     */
    public function totalCents(int $days, int $quantity = 1): int
    {
        return match ($this->price_unit) {
            'per_day' => $this->priceCents() * max(1, $days),
            'per_unit' => $this->priceCents() * max(1, min($quantity, $this->max_quantity)),
            default => $this->priceCents(),
        };
    }

    /** What the checkout needs (title and description in the current language). */
    public function toCheckoutArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'price' => (float) $this->price,
            'price_unit' => $this->price_unit,
            'max_quantity' => $this->price_unit === 'per_unit' ? $this->max_quantity : 1,
        ];
    }

    /** Number of rental days charged for a pick-up/return: every started 24 hours counts, at least one. */
    public static function rentalDays(\DateTimeInterface $pickup, \DateTimeInterface $return): int
    {
        return max(1, (int) ceil(($return->getTimestamp() - $pickup->getTimestamp()) / 86400));
    }

    /** Validation rules for creating/updating an extra (the admin form sends names/descriptions per language). */
    public static function rules(): array
    {
        return [
            'price' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'price_unit' => ['required', Rule::in(config('extras.price_units'))],
            'max_quantity' => ['nullable', 'integer', 'between:1,20'],
            'classes' => ['nullable', 'array'],
            'classes.*' => ['distinct', Rule::exists('vehicle_classes', 'id')],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
