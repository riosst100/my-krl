<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row = one train calling at one station on one service date.
        // KCI publishes a single time per stop (time_est) plus the arrival time
        // at the final destination; platform and arrival-at-stop are not provided.
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('train_line_id')->nullable()->constrained()->nullOnDelete();
            $table->string('train_number', 20);
            $table->string('route_name', 150)->nullable();
            $table->string('destination', 100);
            $table->time('departure_time');
            $table->time('destination_arrival_time')->nullable();
            $table->date('service_date');
            $table->timestamps();

            $table->unique(['station_id', 'service_date', 'train_number']);
            $table->index(['station_id', 'service_date', 'departure_time']);
            $table->index(['service_date', 'train_number']);
            $table->index('service_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
