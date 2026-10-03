<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->unsignedSmallInteger('operational_area')->nullable()->after('longitude'); // KCI "group_wil"
            $table->boolean('kci_enabled')->default(true)->after('operational_area');        // KCI "fg_enable"
            $table->timestamp('synced_at')->nullable()->after('is_active');                  // last seen in a KCI station sync
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn(['operational_area', 'kci_enabled', 'synced_at']);
        });
    }
};
