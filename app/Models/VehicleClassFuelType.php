<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One fuel type a vehicle class is offered with. */
#[Fillable(['vehicle_class_id', 'fuel_type'])]
class VehicleClassFuelType extends Model
{
    public $timestamps = false;

    public function vehicleClass(): BelongsTo
    {
        return $this->belongsTo(VehicleClass::class, 'vehicle_class_id');
    }
}
