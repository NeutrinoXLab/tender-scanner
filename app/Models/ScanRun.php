<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ScanRun extends Model
{
    protected $guarded = [];

    protected $casts = ['checkpoint' => 'array', 'results' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'heartbeat_at' => 'datetime'];

    /** @return array<int, string> */
    public function families(): array
    {
        return $this->checkpoint['_families'] ?? ['PI', 'CN', 'SCN'];
    }

    public function seapComplete(): bool
    {
        return data_get($this->results, 'seap.status') === 'completed'
            && collect($this->families())->every(fn (string $family): bool => (bool) data_get($this->results, "seap.families.$family.complete", false));
    }

    public function batchAvailable(): bool
    {
        $after = $this->checkpoint['_next_batch_at'] ?? null;

        return $after === null || CarbonImmutable::parse($after)->lte(now());
    }

    public function counts(?string $source = null): array
    {
        $rows = DB::table('scan_run_items')->join('notices', 'notices.id', '=', 'scan_run_items.notice_id')
            ->where('scan_run_id', $this->id)->when($source, fn ($query) => $query->where('scan_run_items.source', $source))
            ->selectRaw('COALESCE(notices.canonical_notice_id, notices.id) as canonical_id, MAX(scan_run_items.changed) as changed')
            ->groupByRaw('COALESCE(notices.canonical_notice_id, notices.id)')->get();
        $new = $rows->where('canonical_id', '>', $this->before_notice_id)->count();
        $updated = $rows->filter(fn ($row) => $row->canonical_id <= $this->before_notice_id && $row->changed)->count();

        return ['new' => $new, 'updated' => $updated, 'checked' => $rows->count(), 'unchanged' => $rows->count() - $new - $updated];
    }

    public function summary(): array
    {
        $results = $this->results ?? [];
        foreach (['seap', 'ted'] as $source) {
            $results[$source] = ($results[$source] ?? ['status' => 'pending', 'errors' => []]) + $this->counts($source);
        }
        $families = $results['seap']['families'] ?? [];
        $terminal = ! in_array($this->status, ['queued', 'running'], true);
        $unverified = false;
        foreach ($this->families() as $family) {
            if (! isset($families[$family])) {
                $unverified = $terminal;
                $families[$family] = ['status' => $terminal ? 'unverified' : 'pending', 'processed' => null,
                    'reported' => null, 'fetched' => null, 'new' => 0, 'updated' => 0, 'duplicates' => 0,
                    'errors' => [], 'complete' => false, 'from' => $this->checkpoint['_ranges'][$family]['from'] ?? $this->date_from,
                    'to' => $this->checkpoint['_ranges'][$family]['to'] ?? $this->date_to];
            }
        }
        $results['seap']['families'] = $families;
        if ($unverified && $results['seap']['status'] === 'completed') {
            $results['seap']['status'] = 'unverified';
        }

        return ['id' => $this->id, 'status' => $unverified && $this->status === 'completed' ? 'unverified' : $this->status,
            'can_resume' => $this->mode === 'full' && in_array($this->status, ['paused', 'partial', 'failed'], true) && ! empty($this->checkpoint) && $this->batchAvailable(),
            'resume_after' => $this->batchAvailable() ? null : ($this->checkpoint['_next_batch_at'] ?? null),
            'mode' => $this->mode ?? 'full', 'from' => $this->date_from, 'to' => $this->date_to,
            'started_at' => $this->started_at?->timezone('Europe/Bucharest')->format('d.m.Y H:i:s'),
            'finished_at' => $this->finished_at?->timezone('Europe/Bucharest')->format('d.m.Y H:i:s'),
            'created_at' => $this->created_at?->timezone('Europe/Bucharest')->format('d.m.Y H:i:s'),
            'message' => $this->message, 'results' => $results, 'counts' => $this->counts(),
            'waiting' => $this->status === 'queued' && $this->created_at->lt(now()->subMinute())];
    }
}
