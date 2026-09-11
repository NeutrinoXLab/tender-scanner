<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notices', function (Blueprint $table): void {
            $table->boolean('is_saved')->default(false)->index();
            $table->boolean('is_ignored')->default(false)->index();
            $table->string('personal_status', 24)->default('new')->index();
            $table->text('personal_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notices', function (Blueprint $table): void {
            $table->dropColumn(['is_saved', 'is_ignored', 'personal_status', 'personal_notes']);
        });
    }
};
