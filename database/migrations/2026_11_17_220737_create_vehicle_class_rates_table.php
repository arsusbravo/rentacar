<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One price set per vehicle class x fuel type x transmission. All prices exclude VAT; a null price
        // means that tariff does not exist for the combination. Per-km is a pure charge per driven kilometre.
        Schema::create('vehicle_class_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_class_id')->constrained()->cascadeOnDelete();
            $table->string('fuel_type', 20);
            $table->string('transmission', 20);
            $table->decimal('price_half_day', 10, 2)->nullable();
            $table->decimal('price_day', 10, 2)->nullable();
            $table->decimal('price_week', 10, 2)->nullable();
            $table->decimal('price_month', 10, 2)->nullable();
            $table->decimal('price_year', 10, 2)->nullable();
            $table->decimal('price_km', 6, 2)->nullable();
            $table->timestamps();

            $table->unique(['vehicle_class_id', 'fuel_type', 'transmission'], 'vcr_class_fuel_transmission_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_class_rates');
    }
};
