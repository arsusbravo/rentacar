<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extra_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('extra_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['extra_id', 'locale'], 'et_extra_locale_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extra_translations');
    }
};
