<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notice_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_saved')->default(false);
            $table->boolean('is_ignored')->default(false);
            $table->string('personal_status', 24)->default('new');
            $table->text('personal_notes')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'notice_id']);
            $table->index(['user_id', 'personal_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notices');
    }
};
