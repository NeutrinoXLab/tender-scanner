<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notices', function (Blueprint $table) {
            // Detalii suplimentare din anunt
            $table->text('description')->nullable()->after('title');

            // Tip contract
            $table->string('contract_type')->nullable()->after('procedure_type');

            // Tip atribuire
            $table->string('contract_assignment_type')->nullable()
                ->after('contract_type');

            // Localizare NUTS
            $table->string('nuts_code')->nullable()->index()
                ->after('locality');

            // Criteriu de atribuire
            $table->string('award_criteria')->nullable()
                ->after('nuts_code');

            // Durata contractului
            $table->unsignedInteger('contract_duration_months')->nullable()
                ->after('award_criteria');

            // Depunere electronica
            $table->boolean('is_electronic_submission')->nullable()
                ->after('contract_duration_months');

            // Finantare europeana
            $table->boolean('is_eu_funded')->nullable()
                ->after('is_electronic_submission');

            // Program / proiect de finantare
            $table->text('funding_program')->nullable()
                ->after('is_eu_funded');
        });
    }

    public function down(): void
    {
        Schema::table('notices', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'contract_type',
                'contract_assignment_type',
                'nuts_code',
                'award_criteria',
                'contract_duration_months',
                'is_electronic_submission',
                'is_eu_funded',
                'funding_program',
            ]);
        });
    }
};