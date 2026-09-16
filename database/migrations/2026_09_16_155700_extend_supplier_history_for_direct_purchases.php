<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('seap_bulk_resources', function (Blueprint $table): void {
            $table->unsignedBigInteger('skipped_rows')->default(0);
            $table->unsignedBigInteger('last_skipped_row')->nullable();
        });
        Schema::table('supplier_history', function (Blueprint $table): void {
            $table->string('history_type')->default('contracts')->index();
            $table->string('official_number')->nullable()->unique();
            $table->text('title')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('completed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seap_bulk_resources', function (Blueprint $table): void {
            $table->dropColumn(['skipped_rows', 'last_skipped_row']);
        });
        Schema::table('supplier_history', function (Blueprint $table): void {
            $table->dropUnique(['official_number']);
            $table->dropIndex(['history_type']);
            $table->dropColumn(['history_type', 'official_number', 'title', 'published_at', 'completed_at']);
        });
    }
};
