<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Options a client can add to a rental: winter tyres, navigation, tow bar, child seat, ...
        // Title and description live in extra_translations.
        Schema::create('extras', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->decimal('price', 8, 2);
            // How the price is charged: per_day (x rental days), per_unit (x chosen quantity), per_time (once).
            // A plain string (no ENUM): the allowed values are in config/extras.php.
            $table->string('price_unit', 20);
            $table->unsignedTinyInteger('max_quantity')->default(1); // only used for per_unit
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extras');
    }
};
