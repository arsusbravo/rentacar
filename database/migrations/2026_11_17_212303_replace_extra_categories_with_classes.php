<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extras are coupled to individual vehicle classes: a tow bar is not available on every class.
        // An extra is only offered for the classes it is coupled to.
        Schema::create('extra_vehicle_class', function (Blueprint $table) {
            $table->foreignId('extra_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_class_id')->constrained()->cascadeOnDelete();

            $table->primary(['extra_id', 'vehicle_class_id']);
        });

        // Keep what was offered before: an extra limited to categories gets every class of those
        // categories; an extra without a restriction (offered everywhere) gets every class.
        DB::statement('
            insert into extra_vehicle_class (extra_id, vehicle_class_id)
            select x.extra_id, c.id
              from extra_vehicle_category x
              join vehicle_classes c on c.vehicle_category_id = x.vehicle_category_id
            union
            select e.id, c.id
              from extras e
             cross join vehicle_classes c
             where not exists (select 1 from extra_vehicle_category x where x.extra_id = e.id)
        ');

        Schema::dropIfExists('extra_vehicle_category');
    }

    public function down(): void
    {
        Schema::create('extra_vehicle_category', function (Blueprint $table) {
            $table->foreignId('extra_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_category_id')->constrained()->cascadeOnDelete();

            $table->primary(['extra_id', 'vehicle_category_id']);
        });

        // Lossy: an extra goes back to the categories of the classes it was coupled to.
        DB::statement('
            insert into extra_vehicle_category (extra_id, vehicle_category_id)
            select distinct x.extra_id, c.vehicle_category_id
              from extra_vehicle_class x
              join vehicle_classes c on c.id = x.vehicle_class_id
        ');

        Schema::dropIfExists('extra_vehicle_class');
    }
};
