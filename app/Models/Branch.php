<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'city', 'address', 'postal_code', 'phone', 'email', 'image_path', 'is_active'])]
class Branch extends Model
{
    protected $appends = ['image_url'];

    /** Public URL of the uploaded picture, null when there is none. */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->image_path ? asset('storage/'.$this->image_path) : null);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Active branches with their opening hours, as shown on the public site. */
    public static function publicList(): Collection
    {
        return static::active()->with('openingHours')->orderBy('city')
            ->get(['id', 'name', 'city', 'address', 'postal_code', 'phone', 'email', 'image_path']);
    }

    /** "Rentacar Rotterdam", or "City – Name" when the name does not contain the city. */
    public function label(): string
    {
        return str_contains(mb_strtolower($this->name), mb_strtolower($this->city)) ? $this->name : "{$this->city} – {$this->name}";
    }

    public function openingHours(): HasMany
    {
        return $this->hasMany(OpeningHour::class)->orderBy('weekday');
    }

    /** Replace the weekly opening hours (7 rows, ISO weekday 1-7). */
    public function syncOpeningHours(array $hours): void
    {
        foreach ($hours as $row) {
            $closed = (bool) ($row['is_closed'] ?? false);

            $this->openingHours()->updateOrCreate(
                ['weekday' => $row['weekday']],
                [
                    'is_closed' => $closed,
                    'opens_at' => $closed ? null : $row['opens_at'],
                    'closes_at' => $closed ? null : $row['closes_at'],
                ],
            );
        }
    }

    /** Opening hours row for a given ISO weekday, if any. */
    public function hoursFor(int $isoWeekday): ?OpeningHour
    {
        return $this->openingHours->firstWhere('weekday', $isoWeekday);
    }
}
