<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('queued');
            $table->string('active_key')->nullable()->unique();
            $table->date('date_from');
            $table->date('date_to');
            $table->unsignedBigInteger('before_notice_id')->default(0);
            $table->json('results')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamps();
        });
        Schema::create('scan_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notice_id')->constrained()->restrictOnDelete();
            $table->string('source');
            $table->string('external_id');
            $table->boolean('changed')->default(false);
            $table->unique(['scan_run_id', 'source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_run_items');
        Schema::dropIfExists('scan_runs');
    }
};
