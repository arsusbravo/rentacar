<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A class can offer several transmissions (the client chooses when there is more than one).
        // A table instead of a JSON column keeps this working on MySQL 5.5.
        Schema::create('vehicle_class_transmissions', function (Blueprint $table) {
            $table->foreignId('vehicle_class_id')->constrained()->cascadeOnDelete();
            $table->string('transmission', 20); // value from config('vehicles.transmissions')

            $table->primary(['vehicle_class_id', 'transmission']);
        });

        // Keep the existing single value.
        DB::statement('insert into vehicle_class_transmissions (vehicle_class_id, transmission) select id, transmission from vehicle_classes');

        Schema::table('vehicle_classes', function (Blueprint $table) {
            $table->dropColumn('transmission');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_classes', function (Blueprint $table) {
            $table->string('transmission', 20)->default('manual')->after('fuel_type');
        });

        // A class can only keep one value again: take the first one.
        DB::statement('update vehicle_classes set transmission = (select min(transmission) from vehicle_class_transmissions t where t.vehicle_class_id = vehicle_classes.id) where exists (select 1 from vehicle_class_transmissions t where t.vehicle_class_id = vehicle_classes.id)');

        Schema::dropIfExists('vehicle_class_transmissions');
    }
};
