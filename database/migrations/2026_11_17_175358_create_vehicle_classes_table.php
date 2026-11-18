<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A group of similar vehicles inside a category ("VW Polo or similar").
        // fuel_type, transmission and license_category are plain strings (not DB ENUMs):
        // the allowed values live in config/vehicles.php so they can grow without a migration.
        Schema::create('vehicle_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_category_id')->constrained()->restrictOnDelete();
            $table->string('slug')->unique();
            $table->string('example_models', 255); // e.g. "Toyota Aygo, Citroën C1, Peugeot 108"
            $table->string('fuel_type', 20);
            $table->string('transmission', 20);
            $table->string('license_category', 5)->default('B');
            $table->unsignedTinyInteger('seats');
            $table->unsignedTinyInteger('doors')->nullable();
            $table->unsignedTinyInteger('baggage_large')->default(0);
            $table->unsignedTinyInteger('baggage_small')->default(0);
            $table->unsignedSmallInteger('cargo_volume_liters')->nullable();
            $table->unsignedInteger('payload_kg')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['vehicle_category_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_classes');
    }
};
