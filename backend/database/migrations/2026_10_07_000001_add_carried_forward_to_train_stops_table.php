<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stops copied from an earlier day (ScheduleCarryForwardService) until the
        // train's stops are fetched for this day (on demand, through krl-sync).
        Schema::table('train_stops', function (Blueprint $table) {
            $table->boolean('carried_forward')->default(false)->after('is_transit');
        });
    }

    public function down(): void
    {
        Schema::table('train_stops', function (Blueprint $table) {
            $table->dropColumn('carried_forward');
        });
    }
};
