<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Anonymous page views of the public site. No IP address, no cookie: see App\Http\Middleware\TrackVisit. */
    public function up(): void
    {
        Schema::create('page_visits', function (Blueprint $table) {
            $table->id();
            $table->dateTime('visited_at');
            $table->char('visitor', 32);                     // daily-changing hash: counts visitors, identifies nobody
            $table->string('path', 191);
            $table->string('referrer_host', 191)->nullable();
            $table->string('device', 10);                    // desktop | mobile | tablet
            $table->string('locale', 5)->nullable();

            $table->index('visited_at', 'pv_visited_idx');
            $table->index(['visited_at', 'visitor'], 'pv_day_visitor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_visits');
    }
};
