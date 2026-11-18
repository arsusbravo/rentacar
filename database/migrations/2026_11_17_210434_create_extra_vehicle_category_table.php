<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which vehicle categories an extra is offered for. An extra without rows is offered for all.
        Schema::create('extra_vehicle_category', function (Blueprint $table) {
            $table->foreignId('extra_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_category_id')->constrained()->cascadeOnDelete();

            $table->primary(['extra_id', 'vehicle_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extra_vehicle_category');
    }
};
