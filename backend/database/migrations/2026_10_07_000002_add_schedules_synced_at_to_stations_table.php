<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the station's timetable last arrived from a sync (krl-sync or an import).
        // Carried-forward copies do not count. Null = never synced.
        Schema::table('stations', function (Blueprint $table) {
            $table->timestamp('schedules_synced_at')->nullable()->after('synced_at');
        });

        // Existing data: the last time a schedule row of the station was written.
        DB::statement('UPDATE stations SET schedules_synced_at = s.last FROM (SELECT station_id, max(updated_at) AS last FROM schedules GROUP BY station_id) s WHERE s.station_id = stations.id');
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn('schedules_synced_at');
        });
    }
};
