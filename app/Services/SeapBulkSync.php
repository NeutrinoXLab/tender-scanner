<?php

namespace App\Services;

use App\Models\Notice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SeapBulkSync
{
    public function __construct(private BulkSpreadsheetReader $reader, private NoticeIngestor $ingestor) {}

    /** @return array{status: string, processed: int, resource: ?string, skipped: int} */
    public function sync(int $year, int $limit = 1000): array
    {
        return Cache::lock('seap-bulk-sync', 600)->block(1, function () use ($year, $limit): array {
            $response = Http::connectTimeout(10)->timeout(30)->get('https://data.gov.ro/api/3/action/package_show', ['id' => 'achizitii-publice-'.$year])->throw()->json();
            if (($response['success'] ?? false) !== true || ! is_array(data_get($response, 'result.resources'))) {
                throw new RuntimeException('Catalog data.gov.ro invalid.');
            }
            foreach ($response['result']['resources'] as $resource) {
                $name = Str::ascii($resource['name'] ?? '');
                $kind = match (true) {
                    (bool) preg_match('/^Anunturi de (?:initiere|intiere) publicate T/i', $name) => 'notices',
                    (bool) preg_match('/^Contracte T/i', $name) => 'contracts',
                    (bool) preg_match('/^Achizitii directe T/i', $name) => 'direct_purchases',
                    (bool) preg_match('/^Notificari de atribuire la cumpararea directa T/i', $name) => 'direct_awards',
                    default => null,
                };
                if ($kind === null) {
                    continue;
                }
                $url = $resource['url'] ?? '';
                $this->validateUrl($url);
                $version = hash('sha256', json_encode([$url, $resource['last_modified'] ?? '', $resource['size'] ?? '', data_get($resource, 'archiver.hash')], JSON_THROW_ON_ERROR));
                $existing = DB::table('seap_bulk_resources')->where('resource_id', $resource['id'])->first();
                if (! $existing || $existing->version !== $version) {
                    DB::table('seap_bulk_resources')->updateOrInsert(['resource_id' => $resource['id']], [
                        'kind' => $kind, 'name' => $name, 'url' => $url, 'version' => $version,
                        'file_hash' => null, 'row_offset' => 0, 'byte_offset' => 0, 'status' => 'pending', 'error' => null,
                        'completed_at' => null, 'skipped_rows' => 0, 'last_skipped_row' => null,
                        'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
                    ]);
                }
            }
            $resource = DB::table('seap_bulk_resources')->where('name', 'like', '%'.$year)->where('status', '!=', 'completed')->orderByDesc('kind')->orderBy('id')->first();
            if (! $resource) {
                return ['status' => 'completed', 'processed' => 0, 'resource' => null, 'skipped' => 0];
            }
            try {
                $directory = storage_path('app/private/seap-bulk');
                if (! is_dir($directory)) {
                    mkdir($directory, 0750, true);
                }
                $path = $directory.'/'.hash('sha256', $resource->resource_id.$resource->version).'.zip';
                if (! is_file($path)) {
                    $this->validateUrl($resource->url);
                    $download = Http::connectTimeout(10)->timeout(90)->withOptions(['sink' => $path.'.part', 'allow_redirects' => false])->get($resource->url)->throw();
                    if (! $download->successful()) {
                        throw new RuntimeException('Redirecționare neașteptată la descărcarea data.gov.ro.');
                    }
                    if (! rename($path.'.part', $path)) {
                        throw new RuntimeException('Fișierul descărcat nu poate fi salvat.');
                    }
                }
                $hash = hash_file('sha256', $path);
                if ($resource->file_hash !== null && $resource->file_hash !== $hash) {
                    throw new RuntimeException('Fișierul local s-a schimbat; checkpoint-ul nu mai este valid.');
                }
                DB::table('seap_bulk_resources')->where('id', $resource->id)->update(['file_hash' => $hash, 'error' => null]);
                $processed = 0;
                $header = null;
                $started = microtime(true);
                foreach ($this->preparedRows($path, (int) $resource->row_offset, (int) $resource->byte_offset) as [$index, $row, $byteOffset]) {
                    if ($index === 0) {
                        $header = array_map(fn (string $value): string => trim(Str::ascii($value)), $row);
                        $required = match ($resource->kind) {
                            'notices' => ['Numar anunt initiere', 'Stare procedura', 'Denumire procedura', 'Data publicare', 'Cod CPV', 'Autoritate contractanta', 'Valoare estimata procedura (RON)'],
                            'contracts' => ['Numar anunt atribuire', 'Numar contract', 'Ofertant castigator', 'CUI ofertant castigator', 'Data contract', 'Cod CPV', 'CUI autoritate contractanta', 'Numar lot', 'Valoare contract (RON)'],
                            'direct_purchases', 'direct_awards' => [
                                $resource->kind === 'direct_purchases' ? 'Numar achizitie directa' : 'Numar notificare',
                                $resource->kind === 'direct_purchases' ? 'Denumire achizitie' : 'Obiectul achizitiei',
                                'Autoritate contractanta', 'CUI autoritate contractanta', 'Data publicare', 'Data finalizare',
                                'Cod CPV', 'Ofertant castigator', 'CUI ofertant castigator', 'Valoare achizitie (RON)',
                            ],
                            default => throw new RuntimeException('Tip de export nesuportat.'),
                        };
                        foreach ($required as $column) {
                            if (! in_array($column, $header, true)) {
                                throw new RuntimeException('Coloană obligatorie lipsă: '.$column);
                            }
                        }

                        continue;
                    }
                    if ($index <= $resource->row_offset) {
                        continue;
                    }
                    if ($processed >= max(1, $limit) || microtime(true) - $started >= 45) {
                        return ['status' => 'paused', 'processed' => $processed, 'resource' => $resource->name,
                            'skipped' => (int) DB::table('seap_bulk_resources')->where('id', $resource->id)->value('skipped_rows')];
                    }
                    $data = [];
                    foreach ($header as $position => $column) {
                        $key = array_key_exists($column, $data) ? $column.' atribuire' : $column;
                        $data[$key] = $row[$position] ?? '';
                    }
                    DB::transaction(function () use ($resource, $data, $index, $byteOffset): void {
                        if ($resource->kind === 'notices') {
                            $this->notice($data, $resource->resource_id, $resource->url);
                        } elseif (in_array($resource->kind, ['direct_purchases', 'direct_awards'], true)) {
                            if (! $this->directPurchase($data, $resource->kind, $resource->resource_id, $resource->url, $index + 1)) {
                                DB::table('seap_bulk_resources')->where('id', $resource->id)->update([
                                    'skipped_rows' => DB::raw('skipped_rows + 1'), 'last_skipped_row' => $index + 1,
                                ]);
                                Log::warning('Direct purchase without completion date skipped', ['resource' => $resource->resource_id, 'row' => $index + 1]);
                            }
                        } else {
                            $this->contract($data, $resource->resource_id, $resource->url, $index + 1);
                        }
                        DB::table('seap_bulk_resources')->where('id', $resource->id)->update(['row_offset' => $index, 'byte_offset' => $byteOffset, 'status' => 'paused', 'updated_at' => now()]);
                    }, 5);
                    $processed++;
                }
                DB::table('seap_bulk_resources')->where('id', $resource->id)->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
                Log::info('SEAP bulk resource completed', ['resource' => $resource->resource_id, 'processed' => $processed]);

                return ['status' => 'completed', 'processed' => $processed, 'resource' => $resource->name,
                    'skipped' => (int) DB::table('seap_bulk_resources')->where('id', $resource->id)->value('skipped_rows')];
            } catch (Throwable $exception) {
                DB::table('seap_bulk_resources')->where('id', $resource->id)->update(['status' => 'error', 'error' => mb_substr($exception->getMessage(), 0, 2000), 'updated_at' => now()]);
                Log::error('SEAP bulk sync stopped', ['resource' => $resource->resource_id, 'error' => $exception->getMessage()]);
                throw $exception;
            }
        });
    }

    /** @param array<string, string> $row */
    public function notice(array $row, string $resourceId, string $url): void
    {
        $number = strtoupper(trim($row['Numar anunt initiere'] ?? ''));
        if (! preg_match('/^(?:SCN|CN)/', $number)) {
            return;
        }
        if (! preg_match('/^(SCN|CN)\d+$/', $number, $match)) {
            throw new RuntimeException('Număr oficial CN/SCN invalid: '.$number);
        }
        $matches = Notice::where('source', 'seap')->whereNull('canonical_notice_id')->where('notice_number', $number)->limit(2)->get();
        if ($matches->count() > 1) {
            throw new RuntimeException('Mai multe înregistrări existente pentru '.$number.'; este necesară verificarea identității.');
        }
        $existing = $matches->first();
        $snapshot = ['resource_id' => $resourceId, 'url' => $url, 'data' => $row];
        if ($existing && ! data_get($existing->seap_metadata, 'bulk_only', false)) {
            $metadata = $existing->seap_metadata ?? [];
            $metadata += ['family' => $match[1], 'category' => 'opportunity', 'cpv_name' => $row['Denumire CPV'] ?? null,
                'authority_cui' => $row['CUI autoritate contractanta'] ?? null];
            $metadata['bulk'] = $snapshot;
            $existing->update(['seap_metadata' => $metadata]);

            return;
        }
        $this->ingestor->ingest('seap', $existing?->seap_id ?? 'BULK:'.$number, [
            'notice_number' => $number, 'title' => $row['Denumire procedura'],
            'authority_name' => $row['Autoritate contractanta'] ?? null,
            'notice_type' => $match[1].' — '.($row['Tip anunt'] ?? ''),
            'procedure_type' => $row['Tip procedura'] ?? null, 'contract_type' => $row['Tip contract'] ?? null,
            'contract_assignment_type' => $row['Modalitate de atribuire'] ?? null,
            'cpv_code' => $row['Cod CPV'] ?? null, 'publication_date' => $this->date($row['Data publicare'] ?? ''),
            'estimated_value' => $this->decimal($row['Valoare estimata procedura (RON)'] ?? ''),
            'currency' => 'RON', 'status' => $row['Stare procedura'] ?? null,
            'seap_url' => 'https://e-licitatie.ro/pub/notices/'.($match[1] === 'CN' ? 'c-notice/v2/list' : 'simplified-notice/v2/list'),
            'seap_metadata' => ['family' => $match[1], 'category' => 'opportunity', 'bulk_only' => true,
                'cpv_name' => $row['Denumire CPV'] ?? null, 'authority_cui' => $row['CUI autoritate contractanta'] ?? null,
                'bulk' => $snapshot],
        ], ['bulk' => $row]);
    }

    /** @return \Generator<int, array{int, array<int, string>, int}> */
    private function preparedRows(string $path, int $rowOffset, int $byteOffset): \Generator
    {
        $stream = fopen($this->reader->spool($path), 'rb');
        if ($stream === false) {
            throw new RuntimeException('Indexul bulk nu poate fi deschis.');
        }
        try {
            $header = fgets($stream);
            if ($header === false) {
                throw new RuntimeException('Export gol, fără antet.');
            }
            yield [0, json_decode($header, true, flags: JSON_THROW_ON_ERROR), ftell($stream)];
            if ($byteOffset > 0 && fseek($stream, $byteOffset) !== 0) {
                throw new RuntimeException('Checkpoint bulk invalid.');
            }
            $index = $rowOffset;
            while (($line = fgets($stream)) !== false) {
                yield [++$index, json_decode($line, true, flags: JSON_THROW_ON_ERROR), ftell($stream)];
            }
        } finally {
            fclose($stream);
        }
    }

    /** @param array<string, string> $row */
    public function contract(array $row, string $resourceId, string $url, int $line): void
    {
        $supplier = trim($row['Ofertant castigator'] ?? '');
        $cui = strtoupper(preg_replace('/\s+/u', '', $row['CUI ofertant castigator'] ?? ''));
        $cui = preg_replace('/^RO(?=\d+$)/', '', $cui);
        if (in_array($cui, ['-', '0', 'N/A', 'NULL'], true)) {
            $cui = '';
        }
        $supplierKey = $cui !== '' ? 'cui:'.$cui : 'name:'.hash('sha256', mb_strtolower($supplier));
        $date = $this->date($row['Data contract'] ?? '');
        $date = $date === null ? null : substr($date, 0, 10);
        $identity = [$row['CUI autoritate contractanta'] ?? '', $row['Numar anunt atribuire'] ?? '', $row['Numar lot'] ?? '', $row['Numar contract'] ?? '', $date, $row['Incheiat prin'] ?? '', $supplierKey];
        if ($supplier === '' || $identity[1] === '' || $identity[3] === '') {
            throw new RuntimeException('Identificare incompletă a contractului la rândul '.$line);
        }
        $key = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        $contractKey = hash('sha256', json_encode([$identity[0], $identity[1], $identity[3], $date, $identity[5]], JSON_THROW_ON_ERROR));
        $values = ['contract_key' => $contractKey, 'supplier_key' => $supplierKey, 'supplier_name' => $supplier, 'supplier_cui' => $cui ?: null,
            'authority_name' => $row['Autoritate contractanta'] ?? '', 'authority_cui' => $identity[0],
            'notice_number' => $row['Numar anunt initiere'] ?? '', 'award_number' => $identity[1], 'lot' => $identity[2],
            'contract_number' => $identity[3], 'contract_date' => $date, 'contract_kind' => $identity[5],
            'cpv_code' => $row['Cod CPV'] ?? '', 'cpv_name' => $row['Denumire CPV'] ?? '',
            'value' => $this->decimal($row['Valoare contract (RON)'] ?? ''), 'currency' => 'RON',
            'resource_id' => $resourceId, 'resource_row' => $line, 'resource_url' => $url,
            'payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now()];
        DB::table('supplier_history')->upsert([['identity' => $key, 'created_at' => now()] + $values], ['identity'], array_keys($values));
    }

    /** @param array<string, string> $row */
    public function directPurchase(array $row, string $kind, string $resourceId, string $url, int $line): bool
    {
        $prefix = match ($kind) {
            'direct_purchases' => 'DA',
            'direct_awards' => 'DAN',
            default => throw new RuntimeException('Tip de achiziție directă nesuportat.'),
        };
        $number = strtoupper(trim($row[$prefix === 'DA' ? 'Numar achizitie directa' : 'Numar notificare'] ?? ''));
        $supplier = trim($row['Ofertant castigator'] ?? '');
        $title = trim($row[$prefix === 'DA' ? 'Denumire achizitie' : 'Obiectul achizitiei'] ?? '');
        if (! preg_match('/^'.$prefix.'\d+$/', $number) || $supplier === '' || $title === '') {
            throw new RuntimeException('Achiziție directă incompletă sau identificator invalid la rândul '.$line);
        }
        if (trim($row['Data finalizare'] ?? '') === '') {
            return false;
        }
        $cui = strtoupper(preg_replace('/\s+/u', '', $row['CUI ofertant castigator'] ?? ''));
        $cui = preg_replace('/^RO(?=\d+$)/', '', $cui);
        if (in_array($cui, ['-', '0', 'N/A', 'NULL'], true)) {
            $cui = '';
        }
        $completed = $this->date($row['Data finalizare']);
        $key = hash('sha256', 'direct:'.$number);
        $values = [
            'history_type' => $kind, 'official_number' => $number, 'title' => $title,
            'published_at' => $this->date($row['Data publicare'] ?? ''), 'completed_at' => $completed,
            'contract_key' => $key, 'supplier_key' => $cui !== '' ? 'cui:'.$cui : 'name:'.hash('sha256', mb_strtolower($supplier)),
            'supplier_name' => $supplier, 'supplier_cui' => $cui ?: null,
            'authority_name' => $row['Autoritate contractanta'] ?? '', 'authority_cui' => $row['CUI autoritate contractanta'] ?? null,
            'notice_number' => '', 'award_number' => $prefix === 'DAN' ? $number : '', 'contract_number' => '',
            'contract_date' => substr($completed, 0, 10), 'contract_kind' => $row['Tip contract'] ?? null,
            'cpv_code' => $row['Cod CPV'] ?? '', 'cpv_name' => $row['Denumire CPV'] ?? null,
            'value' => $this->decimal($row['Valoare achizitie (RON)'] ?? ''), 'currency' => 'RON',
            'resource_id' => $resourceId, 'resource_row' => $line, 'resource_url' => $url,
            'payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ];
        DB::table('supplier_history')->upsert([['identity' => $key, 'created_at' => now()] + $values], ['identity'], array_keys($values));

        return true;
    }

    public function date(string $value): ?string
    {
        if (trim($value) === '') {
            return null;
        }
        if (is_numeric($value)) {
            return CarbonImmutable::parse('1899-12-30')->addSeconds((int) round((float) $value * 86400))->format('Y-m-d H:i:s');
        }
        if (preg_match('~^\d{2}/\d{2}/\d{4}$~', $value)) {
            return CarbonImmutable::createFromFormat('!d/m/Y', $value)->format('Y-m-d H:i:s');
        }

        return CarbonImmutable::parse($value)->format('Y-m-d H:i:s');
    }

    public function covers(string $day): bool
    {
        if ($this->pendingNotices()->whereDate('publication_date', $day)->exists()) {
            return false;
        }
        foreach (DB::table('seap_bulk_resources')->where('kind', 'notices')->where('status', 'completed')->pluck('name') as $name) {
            if (! preg_match('/ T(I{1,3}|IV) (\d{4})$/', $name, $match)) {
                continue;
            }
            $quarter = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4][$match[1]];
            $start = CarbonImmutable::create((int) $match[2], ($quarter - 1) * 3 + 1, 1)->startOfDay();
            if ($day >= $start->toDateString() && $day <= $start->endOfQuarter()->toDateString()) {
                return true;
            }
        }

        return false;
    }

    public function verificationDay(): ?string
    {
        $date = $this->pendingNotices()->orderBy('publication_date')->value('publication_date');

        return $date === null ? null : CarbonImmutable::parse($date)->toDateString();
    }

    private function pendingNotices(): Builder
    {
        return Notice::where('source', 'seap')->where('seap_metadata->bulk_only', true)
            ->whereIn('status', ['In desfasurare', 'În desfășurare', 'Publicat'])
            ->whereNotNull('publication_date')->whereDate('publication_date', '<=', now()->toDateString())
            ->where(function ($query): void {
                $query->whereNull('seap_metadata->bulk_api_checked_at')
                    ->orWhere('seap_metadata->bulk_api_checked_at', '<', now()->subDays(7)->toIso8601String());
            });
    }

    public function verifiedDay(string $family, string $day): void
    {
        Notice::where('source', 'seap')->where('seap_metadata->bulk_only', true)
            ->where('seap_metadata->family', $family)->whereDate('publication_date', $day)
            ->update(['seap_metadata->bulk_api_checked_at' => now()->toIso8601String()]);
    }

    private function decimal(string $value): ?string
    {
        $value = str_replace(["\xc2\xa0", ' '], '', $value);
        if ($value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            throw new RuntimeException('Valoare numerică invalidă în export: '.$value);
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function validateUrl(string $url): void
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'data.gov.ro' || ! str_starts_with(parse_url($url, PHP_URL_PATH) ?? '', '/dataset/')) {
            throw new RuntimeException('Resursa nu este un download oficial data.gov.ro.');
        }
    }
}
