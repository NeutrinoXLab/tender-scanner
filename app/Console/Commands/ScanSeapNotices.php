<?php

namespace App\Console\Commands;

use App\Services\SeapClient;
use App\Services\SeapImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ScanSeapNotices extends Command
{
    protected $signature = 'seap:scan
                            {--from= : Data de inceput, format YYYY-MM-DD}
                            {--to= : Data de sfarsit inclusiv, format YYYY-MM-DD}
                            {--family=* : PI, CN sau SCN; implicit toate trei}
                            {--limit=0 : Maximum de anunturi per familie pentru test real limitat (0 = fara limita)}
                            {--probe : O singura pagina de un element per familie, fara detalii sau import}
                            {--report : Salveaza raportul JSON in storage/app/private}';

    protected $description = 'Importa oportunitati SEAP: intentii, participare si proceduri simplificate';

    public function handle(SeapImporter $importer): int
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $families = $this->option('family') ?: array_keys(SeapClient::FAMILIES);
        $validator = Validator::make(['from' => $from, 'to' => $to, 'families' => $families, 'limit' => $this->option('limit')], [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($from ? ['after_or_equal:from'] : [])],
            'limit' => ['integer', 'min:0', 'max:18'],
            'families.*' => ['in:PI,CN,SCN'],
        ]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        $this->info('Scanez oportunitatile SEAP...');
        $path = $this->option('report') ? 'seap-scan-'.now()->format('Ymd-His').'.json' : null;
        $checkpoint = [];
        $report = $importer->scan($from, $to, array_values(array_unique($families)), function (string $message, string $family, array $stats) use ($path, $from, $to, &$checkpoint): void {
            $this->line($message);
            if ($path !== null) {
                $checkpoint[$family] = $stats;
                Storage::disk('local')->put($path, json_encode(['from' => $from, 'to' => $to, 'finished_at' => null,
                    'complete' => false, 'families' => $checkpoint], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
        }, (bool) $this->option('probe'), (int) $this->option('limit'));
        $this->table(['Familie', 'Raportate', 'Preluate', 'Procesate', 'Noi', 'Actualizate', 'Neschimbate', 'Duplicate', 'Omise', 'Complet'],
            collect($report)->map(fn (array $stats, string $family): array => [$family, $stats['reported'], $stats['fetched'], $stats['processed'],
                $stats['new'], $stats['updated'], $stats['unchanged'], $stats['duplicates'], $stats['skipped'], $stats['complete'] ? 'Da' : 'Nu'])->values()->all());
        foreach ($report as $family => $stats) {
            foreach (array_slice($stats['errors'], 0, 5) as $error) {
                $this->warn($family.': '.$error);
            }
        }
        if ($path !== null) {
            Storage::disk('local')->put($path, json_encode(['from' => $from, 'to' => $to, 'finished_at' => now()->toIso8601String(),
                'complete' => ! in_array(false, array_column($report, 'complete'), true),
                'families' => $report, 'total_processed' => array_sum(array_column($report, 'processed'))], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->info('Raport: '.Storage::disk('local')->path($path));
        }

        return $this->option('probe') || (int) $this->option('limit') > 0
            ? (count(array_filter($report, fn ($stats) => ! in_array($stats['status'], ['probe_ok', 'sample_ok'], true))) > 0 ? self::FAILURE : self::SUCCESS)
            : (in_array(false, array_column($report, 'complete'), true) ? self::FAILURE : self::SUCCESS);
    }
}
