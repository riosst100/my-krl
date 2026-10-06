<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every request a "Sync dari KCI" run sends to KCI: URL and outcome (no body).
        Schema::create('sync_log_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_log_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->boolean('ok');
            $table->string('message', 500)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at');

            $table->index(['sync_log_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_log_requests');
    }
};
