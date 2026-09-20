<?php

namespace App\Console\Commands;

use App\Models\Notice;
use App\Models\ScanRun;
use App\Models\SeapWorkerBatch;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SeapBackfill extends Command
{
    protected $signature = 'seap:backfill {family?} {--from=} {--to=} {--create : Creează explicit; implicit doar preview}
        {--status= : ID backfill} {--pause= : Suspendă un backfill} {--resume= : Reia un backfill suspendat}';

    protected $description = 'Planifică un backfill exclusiv Remote Worker, fără acces SEAP din comandă';

    public function handle(): int
    {
        if (! config('services.seap_worker.server')) {
            $this->error('Comanda este disponibilă numai pe serverul Remote Worker.');

            return self::FAILURE;
        }
        try {
            $actions = array_filter(['status' => $this->option('status'), 'pause' => $this->option('pause'), 'resume' => $this->option('resume')], fn ($value): bool => $value !== null);
            if ($actions !== []) {
                if (count($actions) !== 1 || $this->argument('family') !== null || $this->option('from') !== null || $this->option('to') !== null || $this->option('create')) {
                    throw ValidationException::withMessages(['action' => 'Folosiți o singură acțiune cu ID, fără familie/interval/--create.']);
                }
                $action = array_key_first($actions);
                validator(['id' => $actions[$action]], ['id' => 'required|integer|min:1'])->validate();
                $id = (int) $actions[$action];
                $run = Cache::lock('seap-worker-exchange', 120)->block(5, fn (): ScanRun => Cache::lock('tender-scan-dispatch', 10)->block(5,
                    fn (): ScanRun => DB::transaction(function () use ($id, $action): ScanRun {
                        $run = ScanRun::where('mode', 'backfill')->whereKey($id)->lockForUpdate()->first();
                        if (! $run) {
                            throw ValidationException::withMessages(['id' => 'Backfill inexistent; runurile normale nu pot fi modificate aici.']);
                        }
                        if ($action !== 'status') {
                            if ($run->seapComplete() || $run->status === 'cancelled') {
                                throw ValidationException::withMessages(['id' => 'Backfill încheiat; nu poate fi suspendat/reluat.']);
                            }
                            $checkpoint = $run->checkpoint;
                            $checkpoint['_backfill_hold'] = $action === 'pause';
                            $pending = SeapWorkerBatch::where('scan_run_id', $run->id)->whereNull('response_hash')->exists();
                            $run->update(['checkpoint' => $checkpoint, 'status' => 'paused', 'active_key' => $pending ? 'manual' : null,
                                'message' => $action === 'pause' ? 'Backfill suspendat explicit; cererea deja emisă poate fi confirmată.' : 'Backfill reluat în coadă; pauza existentă rămâne valabilă.']);
                        }

                        return $run;
                    })));
                $this->showProgress($run);

                return self::SUCCESS;
            }
            $data = validator(['family' => $this->argument('family'), 'from' => $this->option('from'), 'to' => $this->option('to')], [
                'family' => 'required|in:PI,CN,SCN,ADV', 'from' => 'required|date_format:Y-m-d',
                'to' => 'required|date_format:Y-m-d|after_or_equal:from|before_or_equal:'.now('Europe/Bucharest')->toDateString(),
            ])->validate();
            $family = $data['family'];
            $days = (int) CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) + 1;
            $normal = ScanRun::where('active_key', 'manual')->first() ?? ScanRun::oldestIncomplete();
            $this->line('Preview: '.$family.' '.$data['from'].' – '.$data['to'].'; '.$days.' zile calendaristice.');
            $this->line('Run prioritar activ/restant: '.($normal ? '#'.$normal->id.' '.$normal->status : 'niciunul').'.');
            $this->line('Loturi: număr necunoscut fără acces SEAP; se păstrează limitele Remote Worker.');
            $this->line('Limită server/lot: '.min(100, max(1, (int) config('services.seap.batch_size')))
                .'; pauză normală: '.max(1, (int) config('services.seap_worker.batch_interval_minutes', 60)).' minute; la erori minimum 360 minute.');
            if ($cooling = ScanRun::remoteCooldown()) {
                $this->line('Pauză SEAP existentă până la '.$cooling->checkpoint['_next_batch_at'].' (run #'.$cooling->id.').');
            }
            $this->line('Checkpoint: ScanRun mode=backfill, status=pending, familia și intervalul explicit; fără resetarea altor runuri.');
            if ($existing = $this->existingBackfill($data)) {
                $this->line('Există deja backfill #'.$existing->id.' ('.$existing->status.'); --create îl reutilizează.');
            }
            if ($this->option('create')) {
                $run = Cache::lock('tender-scan-dispatch', 10)->block(5, fn (): ScanRun => DB::transaction(function () use ($data, $family): ScanRun {
                    $existing = $this->existingBackfill($data);

                    return $existing ?? ScanRun::create(['mode' => 'backfill', 'status' => 'pending',
                        'date_from' => $data['from'], 'date_to' => $data['to'], 'before_notice_id' => Notice::max('id') ?? 0,
                        'checkpoint' => ['_worker_ready' => true, '_families' => [$family],
                            '_ranges' => [$family => ['from' => $data['from'], 'to' => $data['to']]]],
                        'message' => 'Backfill în așteptare; sincronizarea normală restantă are prioritate.']);
                }));
                $this->showProgress($run);
            } else {
                $this->info('PREVIEW: nimic creat. Adăugați --create numai pentru planificarea explicită.');
            }

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }
    }

    private function showProgress(ScanRun $run): void
    {
        $family = $run->families()[0];
        $saved = $run->checkpoint[$family] ?? [];
        $stats = $saved['stats'] ?? [];
        $windows = $saved['windows'] ?? [];
        $this->line('Backfill #'.$run->id.': '.$run->status.'; '.$family.' '.$run->date_from.' – '.$run->date_to);
        $this->line('Suspendat explicit: '.(($run->checkpoint['_backfill_hold'] ?? false) ? 'da' : 'nu'));
        $this->line('Ultima zi completă: '.($windows === [] ? '—' : end($windows)).'; cursor zi/pagină (index 0): '.($saved['cursor']['day'] ?? '—').'/'.($saved['cursor']['page'] ?? '—'));
        $this->line('Verificate: '.($stats['processed'] ?? 0).'; noi: '.($stats['new'] ?? 0).'; actualizate: '.($stats['updated'] ?? 0));
        $this->line('Următorul moment permis pentru acest run: '.($run->checkpoint['_next_batch_at'] ?? 'la eliberarea cozii'));
        $this->line('Erori: '.implode(' | ', $stats['errors'] ?? []));
        $prior = ScanRun::where('active_key', 'manual')->first() ?? ScanRun::oldestIncomplete() ?? ScanRun::nextBackfill();
        if ($prior && $prior->id !== $run->id) {
            $this->line('Așteaptă run #'.$prior->id.' ('.$prior->mode.', '.$prior->status.').');
        }
        if ($cooling = ScanRun::remoteCooldown()) {
            $this->line('Pauza comună SEAP: '.$cooling->checkpoint['_next_batch_at'].' (run #'.$cooling->id.').');
        }
        $this->line($run->message ?? '');
    }

    /** @param array{family: string, from: string, to: string} $data */
    private function existingBackfill(array $data): ?ScanRun
    {
        return ScanRun::where('mode', 'backfill')->where('date_from', $data['from'])->where('date_to', $data['to'])
            ->where('status', '!=', 'cancelled')->orderBy('id')->get()
            ->first(fn (ScanRun $run): bool => $run->families() === [$data['family']]);
    }
}
