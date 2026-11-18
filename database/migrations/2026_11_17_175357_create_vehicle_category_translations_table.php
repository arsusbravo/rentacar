<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_category_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_category_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            // Explicit short name: the generated one is ~63 chars, right at MySQL's 64 limit.
            $table->unique(['vehicle_category_id', 'locale'], 'vct_category_locale_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_category_translations');
    }
};
