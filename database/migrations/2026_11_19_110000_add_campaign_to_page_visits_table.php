<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The name from a tagged link (?visits=company), to see which mailing was opened. */
    public function up(): void
    {
        Schema::table('page_visits', function (Blueprint $table) {
            $table->string('campaign', 60)->nullable()->after('referrer_host');
            $table->index('campaign', 'pv_campaign_idx');
        });
    }

    public function down(): void
    {
        Schema::table('page_visits', function (Blueprint $table) {
            $table->dropIndex('pv_campaign_idx');
            $table->dropColumn('campaign');
        });
    }
};
