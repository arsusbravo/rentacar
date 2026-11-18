<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A class can be offered with several fuel types; the client chooses (and it will affect the price).
        Schema::create('vehicle_class_fuel_types', function (Blueprint $table) {
            $table->foreignId('vehicle_class_id')->constrained()->cascadeOnDelete();
            $table->string('fuel_type', 20); // value from config('vehicles.fuel_types')

            $table->primary(['vehicle_class_id', 'fuel_type']);
        });

        // Keep the existing single value.
        DB::statement('insert into vehicle_class_fuel_types (vehicle_class_id, fuel_type) select id, fuel_type from vehicle_classes');

        Schema::table('vehicle_classes', function (Blueprint $table) {
            $table->dropColumn('fuel_type');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_classes', function (Blueprint $table) {
            $table->string('fuel_type', 20)->default('petrol')->after('example_models');
        });

        // A class can only keep one value again: take the first one.
        DB::statement('update vehicle_classes set fuel_type = (select min(fuel_type) from vehicle_class_fuel_types f where f.vehicle_class_id = vehicle_classes.id) where exists (select 1 from vehicle_class_fuel_types f where f.vehicle_class_id = vehicle_classes.id)');

        Schema::dropIfExists('vehicle_class_fuel_types');
    }
};
