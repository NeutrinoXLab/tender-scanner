<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seap_worker_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('active_key')->nullable()->unique();
            $table->foreignId('scan_run_id')->constrained()->cascadeOnDelete();
            $table->json('request');
            $table->string('response_hash', 64)->nullable();
            $table->json('receipt')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seap_worker_batches');
    }
};
