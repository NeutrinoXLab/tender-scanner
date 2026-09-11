<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notices', function (Blueprint $table) {
            $table->string('seap_id')->nullable()->change();
            $table->string('source')->default('seap')->index();
            $table->string('ted_id')->nullable()->unique();
            $table->text('ted_url')->nullable();
            $table->json('ted_data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notices', function (Blueprint $table) {
            $table->dropUnique(['ted_id']);
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'ted_id', 'ted_url', 'ted_data']);
        });
    }
};
