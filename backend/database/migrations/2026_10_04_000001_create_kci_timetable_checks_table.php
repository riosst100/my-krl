<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Periodic fingerprints of KCI's (undated) timetable, to learn at what
        // time KCI publishes a new day's timetable.
        Schema::create('kci_timetable_checks', function (Blueprint $table) {
            $table->id();
            $table->string('station_code', 10);
            $table->timestamp('checked_at');
            $table->boolean('ok');
            $table->unsignedSmallInteger('trains')->nullable();
            $table->string('first_departure', 5)->nullable();
            $table->string('last_departure', 5)->nullable();
            $table->string('content_hash', 40)->nullable();
            // True when the content differs from the previous successful check.
            $table->boolean('changed')->default(false);
            $table->json('diff')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['station_code', 'checked_at']);
            $table->index(['changed', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kci_timetable_checks');
    }
};
