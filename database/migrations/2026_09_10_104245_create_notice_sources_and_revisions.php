<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notices', function (Blueprint $table) {
            $table->foreignId('canonical_notice_id')->nullable()->constrained('notices')->restrictOnDelete();
        });
        Schema::create('notice_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id')->constrained()->restrictOnDelete();
            $table->string('source');
            $table->string('external_id');
            $table->text('url')->nullable();
            $table->json('payload')->nullable();
            $table->json('normalized_data');
            $table->string('fingerprint', 64);
            $table->string('matched_by')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['source', 'external_id']);
        });
        Schema::create('notice_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id')->constrained()->restrictOnDelete();
            $table->foreignId('notice_source_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event_type');
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->json('changed_fields');
            $table->timestamps();
        });
        DB::transaction(function () {
            foreach (DB::table('notices')->orderBy('id')->get() as $notice) {
                $normalized = (array) $notice;
                foreach (['id', 'source', 'seap_id', 'ted_id', 'ted_data', 'raw_data', 'seap_url', 'ted_url', 'created_at', 'updated_at', 'canonical_notice_id'] as $key) {
                    unset($normalized[$key]);
                }
                ksort($normalized);
                $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $sources = [];
                if ($notice->source === 'seap' && $notice->seap_id !== null) {
                    $sources[] = ['seap', $notice->seap_id, $notice->seap_url, $notice->raw_data];
                }
                if ($notice->ted_id !== null) {
                    $sources[] = ['ted', $notice->ted_id, $notice->ted_url, $notice->ted_data];
                }
                foreach ($sources as [$source, $externalId, $url, $payload]) {
                    $sourceId = DB::table('notice_sources')->insertGetId([
                        'notice_id' => $notice->id, 'source' => $source, 'external_id' => $externalId,
                        'url' => $url, 'payload' => $payload, 'normalized_data' => $json,
                        'fingerprint' => hash('sha256', $json), 'matched_by' => 'existing_record',
                        'first_seen_at' => $notice->created_at ?? now(), 'last_seen_at' => $notice->updated_at ?? now(),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('notice_revisions')->insert([
                        'notice_id' => $notice->id, 'notice_source_id' => $sourceId, 'event_type' => 'baseline',
                        'after_data' => $json, 'changed_fields' => '[]', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_revisions');
        Schema::dropIfExists('notice_sources');
        Schema::table('notices', fn (Blueprint $table) => $table->dropConstrainedForeignId('canonical_notice_id'));
    }
};
