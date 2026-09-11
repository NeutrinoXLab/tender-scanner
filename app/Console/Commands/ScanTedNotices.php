<?php

namespace App\Console\Commands;

use App\Services\ScanContext;
use App\Services\TedClient;
use App\Services\TedImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class ScanTedNotices extends Command
{
    protected $signature = 'ted:scan {--from= : Data YYYY-MM-DD; implicit ultimele 7 zile} {--to= : Data YYYY-MM-DD; implicit azi} {--max-pages=100 : Limită de siguranță} {--page-size=50 : Anunțuri pe pagină, maximum 100}';

    protected $description = 'Importă anunțurile TED ale autorităților din România în baza locală';

    public function handle(TedClient $client, TedImporter $importer): int
    {
        $options = ['from' => $this->option('from') ?? now()->subDays(7)->toDateString(),
            'to' => $this->option('to') ?? now()->toDateString(), 'max_pages' => $this->option('max-pages'), 'page_size' => $this->option('page-size')];
        $validator = Validator::make($options, ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'max_pages' => 'required|integer|min:1|max:1000', 'page_size' => 'required|integer|min:1|max:100']);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        $seenIds = [];
        $count = 0;
        $failures = 0;
        $this->info("TED România: {$options['from']} – {$options['to']}");
        try {
            for ($page = 1; $page <= (int) $options['max_pages']; $page++) {
                $data = $client->search($options['from'], $options['to'], $page, (int) $options['page_size']);
                if ((int) $data['totalNoticeCount'] > 15000) {
                    throw new \RuntimeException('TED limitează paginarea la 15.000 de anunțuri. Restrângeți perioada.');
                }
                if ($data['notices'] === [] && $count + $failures < (int) $data['totalNoticeCount']) {
                    throw new \RuntimeException('TED a returnat o pagină goală înainte de totalul raportat. Import incomplet.');
                }
                foreach ($data['notices'] as $item) {
                    $id = is_array($item) ? ($item['publication-number'] ?? null) : null;
                    if (is_string($id)) {
                        if (isset($seenIds[$id])) {
                            throw new \RuntimeException('Rezultatele TED s-au suprapus între pagini. Reluați importul.');
                        }
                        $seenIds[$id] = true;
                    }
                    try {
                        $importer->import($item);
                        $count++;
                    } catch (\Throwable $e) {
                        app(ScanContext::class)->error($e->getMessage());
                        $failures++;
                        $this->warn($e->getMessage());
                    }
                }
                $this->line("Pagina {$page}: ".count($data['notices'])." anunțuri; importate/actualizate: {$count}.");
                if ($count + $failures >= (int) $data['totalNoticeCount']) {
                    $this->info("Import încheiat: {$count} anunțuri; {$failures} erori.");

                    return $failures === 0 ? self::SUCCESS : self::FAILURE;
                }
            }
            $this->warn('Import parțial: limita de pagini a fost atinsă. Măriți --max-pages sau restrângeți perioada.');

            return self::FAILURE;
        } catch (\Throwable $e) {
            app(ScanContext::class)->error($e->getMessage());
            $this->error('Import TED oprit: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
