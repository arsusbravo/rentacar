<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One transmission a vehicle class is offered with. */
#[Fillable(['vehicle_class_id', 'transmission'])]
class VehicleClassTransmission extends Model
{
    public $timestamps = false;

    public function vehicleClass(): BelongsTo
    {
        return $this->belongsTo(VehicleClass::class, 'vehicle_class_id');
    }
}
