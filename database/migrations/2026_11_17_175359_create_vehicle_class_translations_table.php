<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_class_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_class_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['vehicle_class_id', 'locale'], 'vcl_class_locale_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_class_translations');
    }
};
