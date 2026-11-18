<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kilometres included in the rental price: a flat amount per rental (0 = none included).
        // The client can book extra kilometres at the per-km price of the rate; driving more than
        // included + extra is settled after the return at that same price.
        Schema::table('vehicle_classes', function (Blueprint $table) {
            $table->unsignedMediumInteger('km_included')->default(250)->after('deposit');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_classes', function (Blueprint $table) {
            $table->dropColumn('km_included');
        });
    }
};
