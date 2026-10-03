<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50);
            $table->string('status', 20)->index();      // queued, running, success, partial, failed
            $table->string('trigger', 20);               // schedule, manual, console
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20)->nullable();    // http, mock
            $table->unsignedInteger('records_processed')->default(0);
            $table->unsignedInteger('stations_processed')->default(0);
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
