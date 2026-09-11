<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ScanRun extends Model
{
    protected $guarded = [];

    protected $casts = ['results' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'heartbeat_at' => 'datetime'];

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

        return ['id' => $this->id, 'status' => $this->status, 'from' => $this->date_from, 'to' => $this->date_to,
            'started_at' => $this->started_at?->timezone('Europe/Bucharest')->format('d.m.Y H:i:s'),
            'finished_at' => $this->finished_at?->timezone('Europe/Bucharest')->format('d.m.Y H:i:s'),
            'created_at' => $this->created_at?->timezone('Europe/Bucharest')->format('d.m.Y H:i:s'),
            'message' => $this->message, 'results' => $results, 'counts' => $this->counts(),
            'waiting' => $this->status === 'queued' && $this->created_at->lt(now()->subMinute())];
    }
}
