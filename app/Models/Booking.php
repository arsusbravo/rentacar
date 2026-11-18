<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['reference', 'customer_id', 'vehicle_class_id', 'fuel_type', 'transmission', 'pickup_branch_id', 'return_branch_id', 'pickup_at', 'return_at', 'status', 'own_risk_reduced', 'extra_km', 'rental_cents', 'own_risk_reduction_cents', 'extras_cents', 'extra_km_cents', 'subtotal_cents', 'vat_cents', 'total_cents', 'deposit_cents', 'confirmed_at', 'cancelled_at'])]
class Booking extends Model
{
    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'pickup_at' => 'datetime',
            'return_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'own_risk_reduced' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicleClass(): BelongsTo
    {
        return $this->belongsTo(VehicleClass::class);
    }

    public function pickupBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'pickup_branch_id');
    }

    public function returnBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'return_branch_id');
    }

    public function extras(): HasMany
    {
        return $this->hasMany(BookingExtra::class);
    }

    /** "RC-2026-0042" for the next booking of this year. */
    public static function nextReference(): string
    {
        $prefix = 'RC-'.now()->year.'-';
        $last = static::where('reference', 'like', $prefix.'%')->orderByDesc('reference')->value('reference');

        return $prefix.str_pad((string) ($last ? (int) substr($last, strlen($prefix)) + 1 : 1), 4, '0', STR_PAD_LEFT);
    }

    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'customer' => ['id' => $this->customer_id, 'name' => $this->customer->fullName(), 'email' => $this->customer->email, 'phone' => $this->customer->phone],
            'vehicle' => [
                'name' => $this->vehicleClass->name,
                'example_models' => $this->vehicleClass->example_models,
                'fuel_type' => $this->fuel_type,
                'transmission' => $this->transmission,
                'fuel_label' => __("vehicles.fuel_types.{$this->fuel_type}"),
                'transmission_label' => __("vehicles.transmissions.{$this->transmission}"),
            ],
            'pickup' => ['branch' => $this->pickupBranch->label(), 'at' => $this->pickup_at->format('Y-m-d H:i')],
            'return' => ['branch' => $this->returnBranch->label(), 'at' => $this->return_at->format('Y-m-d H:i')],
            'own_risk_reduced' => $this->own_risk_reduced,
            'extra_km' => $this->extra_km,
            'extras' => $this->extras->map(fn (BookingExtra $e) => ['title' => $e->title, 'quantity' => $e->quantity, 'cents' => $e->cents])->all(),
            'rental_cents' => $this->rental_cents,
            'own_risk_reduction_cents' => $this->own_risk_reduction_cents,
            'extra_km_cents' => $this->extra_km_cents,
            'subtotal_cents' => $this->subtotal_cents,
            'vat_cents' => $this->vat_cents,
            'total_cents' => $this->total_cents,
            'deposit_cents' => $this->deposit_cents,
            'created_at' => $this->created_at?->format('Y-m-d H:i'),
            'confirmed_at' => $this->confirmed_at?->format('Y-m-d H:i'),
            'cancelled_at' => $this->cancelled_at?->format('Y-m-d H:i'),
        ];
    }
}
