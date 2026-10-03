<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // KCI publishes a colour per train (it can differ within one line,
        // e.g. a Rangkasbitung-line train shown in the Serpong colour).
        Schema::table('schedules', function (Blueprint $table) {
            $table->string('color', 7)->nullable()->after('train_line_id');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
