<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every stop of one train on one service date (KCI "train-schedule").
        // Lets users pick a destination station: trains that stop there later.
        Schema::create('train_stops', function (Blueprint $table) {
            $table->id();
            $table->date('service_date');
            $table->string('train_number', 20);
            $table->unsignedSmallInteger('sequence');
            $table->string('station_code', 10);
            $table->foreignId('station_id')->nullable()->constrained()->nullOnDelete();
            $table->time('time');                 // KCI time_est at this stop (may include seconds)
            $table->boolean('is_transit')->default(false);
            $table->timestamps();

            $table->unique(['service_date', 'train_number', 'sequence']);
            $table->index(['service_date', 'station_id', 'train_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('train_stops');
    }
};
