<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Base deposit (borg) of a class: the minimum that is held at pick-up. The deposit of a rental is
        // calculated from it (see App\Pricing\DepositCalculator). A deposit is refundable: no VAT.
        Schema::table('vehicle_classes', function (Blueprint $table) {
            $table->decimal('deposit', 8, 2)->nullable()->after('own_risk_reduction_price');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_classes', function (Blueprint $table) {
            $table->dropColumn('deposit');
        });
    }
};
