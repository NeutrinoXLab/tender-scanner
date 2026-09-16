<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notices', function (Blueprint $table): void {
            $table->text('title')->change();
            $table->string('seap_official_number')->nullable()->unique();
        });
        DB::table('notices')->where('source', 'seap')->whereNull('canonical_notice_id')->whereNotNull('notice_number')
            ->orderBy('id')->chunkById(500, function ($notices): void {
                foreach ($notices as $notice) {
                    if (preg_match('/^(?:CN|SCN)\d+$/', $notice->notice_number)
                        && DB::table('notices')->where('source', 'seap')->whereNull('canonical_notice_id')->where('notice_number', $notice->notice_number)->count() === 1) {
                        DB::table('notices')->where('id', $notice->id)->update(['seap_official_number' => $notice->notice_number]);
                    }
                }
            });
        Schema::create('seap_bulk_resources', function (Blueprint $table): void {
            $table->id();
            $table->string('resource_id')->unique();
            $table->string('kind');
            $table->string('name');
            $table->text('url');
            $table->string('version');
            $table->string('file_hash')->nullable();
            $table->unsignedBigInteger('row_offset')->default(0);
            $table->unsignedBigInteger('byte_offset')->default(0);
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('supplier_history', function (Blueprint $table): void {
            $table->id();
            $table->string('identity', 64)->unique();
            $table->string('contract_key', 64)->index();
            $table->string('supplier_key')->index();
            $table->string('supplier_name');
            $table->string('supplier_cui')->nullable()->index();
            $table->string('authority_name');
            $table->string('authority_cui')->nullable();
            $table->string('notice_number')->index();
            $table->string('award_number');
            $table->string('lot')->nullable();
            $table->string('contract_number');
            $table->date('contract_date')->nullable()->index();
            $table->string('contract_kind')->nullable();
            $table->string('cpv_code')->index();
            $table->text('cpv_name')->nullable();
            $table->decimal('value', 20, 2)->nullable();
            $table->string('currency', 10)->default('RON');
            $table->string('resource_id');
            $table->unsignedBigInteger('resource_row');
            $table->text('resource_url');
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_history');
        Schema::dropIfExists('seap_bulk_resources');
        Schema::table('notices', function (Blueprint $table): void {
            $table->dropUnique(['seap_official_number']);
            $table->dropColumn('seap_official_number');
        });
    }
};
