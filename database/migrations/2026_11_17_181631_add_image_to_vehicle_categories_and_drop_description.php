<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Categories get an uploaded image; the descriptive text belongs to vehicle classes.
        Schema::table('vehicle_categories', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('slug');
        });

        Schema::table('vehicle_category_translations', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_category_translations', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
        });

        Schema::table('vehicle_categories', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
