<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->id();

            // Identificare SEAP
            $table->string('seap_id')->unique();
            $table->string('notice_number')->nullable()->index();

            // Informații principale
            $table->string('title');
            $table->string('notice_type')->nullable()->index();
            $table->string('procedure_type')->nullable()->index();

            // Autoritate contractantă
            $table->string('authority_name')->nullable()->index();
            $table->string('authority_seap_id')->nullable()->index();

            // CPV
            $table->string('cpv_code')->nullable()->index();

            // Valoare
            $table->decimal('estimated_value', 15, 2)->nullable();
            $table->string('currency', 10)->nullable();

            // Date
            $table->timestamp('publication_date')->nullable()->index();
            $table->timestamp('submission_deadline')->nullable()->index();

            // Localizare
            $table->string('county')->nullable()->index();
            $table->string('locality')->nullable();

            // Status
            $table->string('status')->nullable()->index();

            // Link către SEAP
            $table->text('seap_url')->nullable();

            // Răspunsul original primit de la SEAP
            $table->json('raw_data')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
    }
};