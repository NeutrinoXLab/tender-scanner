<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SeapWorkerTransport
{
    public function run(bool $check = false): int
    {
        $url = rtrim((string) config('services.seap_worker.url'), '/');
        $parts = parse_url($url);
        if (config('services.seap_worker.server') || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || strlen((string) config('services.seap_worker.token')) < 32) {
            throw new RuntimeException('Configurați URL HTTPS și token dedicat de minimum 32 caractere pe worker; modul server trebuie dezactivat.');
        }
        $http = fn () => Http::acceptJson()->asJson()->withToken(config('services.seap_worker.token'))
            ->connectTimeout(10)->timeout(120)->withOptions(['allow_redirects' => false]);
        if ($check) {
            $response = $http()->get($url.'/api/seap-worker/ping');
            if (! $response->successful() || $response->json('protocol') !== 1) {
                throw new RuntimeException('Conexiunea autentificată cu serverul nu a fost confirmată.');
            }

            return 0;
        }

        $directory = storage_path('app/private/seap-worker');
        File::ensureDirectoryExists($directory, 0700);
        $lock = fopen($directory.'/worker.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Blocarea workerului nu este disponibilă.');
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                return 0;
            }
            $path = $directory.'/'.hash('sha256', $url).'.json';
            $accepted = 0;
            for ($operation = 0; $operation < min(100, max(1, (int) config('services.seap_worker.request_limit'))); $operation++) {
                if (File::exists($path)) {
                    $outbox = json_decode(File::get($path), true, 64, JSON_THROW_ON_ERROR);
                } else {
                    $response = $http()->post($url.'/api/seap-worker/claim');
                    if ($response->status() === 204) {
                        break;
                    }
                    if (! $response->successful()) {
                        throw new RuntimeException('Serverul nu a oferit un lot. Nicio cerere SEAP nouă.');
                    }
                    $outbox = $response->json();
                    validator($outbox, ['batch_id' => 'required|uuid', 'request' => 'required|array',
                        'request.family' => 'required|in:PI,CN,SCN,ADV',
                        'request.kind' => 'required|in:listing,pi_detail,adv_detail,procedure_details'])->validate();
                    File::replace($path, json_encode($outbox, JSON_THROW_ON_ERROR));
                }
                if (! isset($outbox['response'])) {
                    try {
                        $data = SeapWorkerClient::execute(app(SeapClient::class), $outbox['request']);
                        if ($outbox['request']['kind'] === 'listing') {
                            $data = array_intersect_key($data, array_flip(['total', 'items', 'searchTooLong']));
                        }
                        $outbox['response'] = ['family' => $outbox['request']['family'], 'status' => 'ok', 'data' => $data];
                    } catch (SeapAccessRestricted $exception) {
                        $outbox['response'] = ['family' => $outbox['request']['family'], 'status' => str_contains($exception->getMessage(), '429') ? '429' : '403', 'data' => []];
                    } catch (Throwable) {
                        $outbox['response'] = ['family' => $outbox['request']['family'], 'status' => 'unavailable', 'data' => []];
                    }
                    File::replace($path, json_encode($outbox, JSON_THROW_ON_ERROR));
                }
                $payload = json_encode($outbox['response'], JSON_THROW_ON_ERROR);
                if (strlen($payload) > max(1024, (int) config('services.seap_worker.max_bytes'))) {
                    throw new RuntimeException('Lotul depășește limita de transfer. Rămâne salvat local; nu se continuă scanarea.');
                }
                $response = $http()->withBody($payload, 'application/json')->post($url.'/api/seap-worker/batches/'.$outbox['batch_id']);
                if (! $response->successful() || $response->json('accepted') !== true || $response->json('batch_id') !== $outbox['batch_id']) {
                    throw new RuntimeException('Transfer neconfirmat; lotul rămâne salvat local pentru retransmitere.');
                }
                if (! File::delete($path)) {
                    throw new RuntimeException('Confirmare primită, dar lotul local nu a putut fi închis.');
                }
                $accepted++;
                if ($outbox['response']['status'] !== 'ok') {
                    throw new RuntimeException('SEAP a oprit lotul; serverul a păstrat progresul.');
                }
            }

            return $accepted;
        } finally {
            fclose($lock);
        }
    }
}
