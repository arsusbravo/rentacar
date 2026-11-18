<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pick-up hours of a branch for one weekday. Pick-up times must fall between
 * opens_at and closes_at; returns are possible 24 hours a day.
 */
#[Fillable(['branch_id', 'weekday', 'opens_at', 'closes_at', 'is_closed'])]
class OpeningHour extends Model
{
    protected $hidden = ['id', 'branch_id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['weekday' => 'integer', 'is_closed' => 'boolean'];
    }

    /** Return times as H:i (MySQL TIME columns include seconds). */
    protected function opensAt(): Attribute
    {
        return Attribute::get(fn (?string $v) => $v ? substr($v, 0, 5) : null);
    }

    protected function closesAt(): Attribute
    {
        return Attribute::get(fn (?string $v) => $v ? substr($v, 0, 5) : null);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Monday-Friday 08:00-18:00, weekend closed. */
    public static function defaults(): array
    {
        return collect(range(1, 7))->map(fn (int $day) => $day <= 5
            ? ['weekday' => $day, 'opens_at' => '08:00', 'closes_at' => '18:00', 'is_closed' => false]
            : ['weekday' => $day, 'opens_at' => null, 'closes_at' => null, 'is_closed' => true]
        )->all();
    }
}
