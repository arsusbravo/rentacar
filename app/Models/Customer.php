<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['first_name', 'last_name', 'date_of_birth', 'email', 'phone', 'street', 'house_number', 'postal_code', 'city', 'notes'])]
class Customer extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['date_of_birth' => 'date'];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /** Words in the search text must each match the name, email, phone or city. */
    public function scopeSearch(Builder $query, ?string $text): Builder
    {
        foreach (preg_split('/\s+/', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $like = '%'.addcslashes($word, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                ->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('city', 'like', $like));
        }

        return $query;
    }

    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'email' => $this->email,
            'phone' => $this->phone,
            'street' => $this->street,
            'house_number' => $this->house_number,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'notes' => $this->notes,
            'bookings_count' => $this->bookings_count ?? $this->bookings()->count(),
            'created_at' => $this->created_at?->format('Y-m-d'),
        ];
    }
}
