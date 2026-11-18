<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Own risk (eigen risico) of a class, and the optional buy-down: for a price per rental day the
        // client lowers the own risk to own_risk_reduced (which may be 0). Excluding VAT.
        Schema::table('vehicle_classes', function (Blueprint $table) {
            $table->decimal('own_risk', 8, 2)->nullable()->after('payload_kg');
            $table->decimal('own_risk_reduced', 8, 2)->nullable()->after('own_risk');
            $table->decimal('own_risk_reduction_price', 8, 2)->nullable()->after('own_risk_reduced');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_classes', function (Blueprint $table) {
            $table->dropColumn(['own_risk', 'own_risk_reduced', 'own_risk_reduction_price']);
        });
    }
};
