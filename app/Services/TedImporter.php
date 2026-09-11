<?php

namespace App\Services;

use App\Models\Notice;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class TedImporter
{
    public function text(mixed $value): ?string
    {
        if (is_array($value)) {
            foreach (['ron', 'ro', 'eng', 'en', 'mul'] as $language) {
                if (isset($value[$language])) {
                    return $this->text($value[$language]);
                }
            }
            $parts = array_filter(array_map($this->text(...), array_values($value)));

            return $parts === [] ? null : implode("\n", array_unique($parts));
        }

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    public function import(array $item): Notice
    {
        $id = $item['publication-number'] ?? '';
        if (! is_string($id) || ! preg_match('/^\d{1,7}-\d{4}$/D', $id)) {
            throw new InvalidArgumentException('Anunț TED fără identificator valid.');
        }
        $title = $this->text($item['notice-title'] ?? null);
        if ($title === null) {
            throw new InvalidArgumentException("Anunț TED {$id} fără titlu.");
        }
        $seapId = null;
        foreach ((array) ($item['submission-url-lot'] ?? []) as $url) {
            if (is_string($url) && preg_match('~^https://(?:www\.)?e-licitatie\.ro/pub/notices/pi-notice/view/v2/(\d+)(?:[/?#]|$)~i', $url, $match)) {
                $seapId = $match[1];
            }
        }

        $dates = (array) ($item['deadline-receipt-tender-date-lot'] ?? []);
        $times = (array) ($item['deadline-receipt-tender-time-lot'] ?? []);
        $deadlines = [];
        foreach ($dates as $index => $date) {
            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
                $deadlines[] = CarbonImmutable::parse(substr($date, 0, 10).'T'.($times[$index] ?? '00:00:00'), 'Europe/Bucharest')
                    ->setTimezone('Europe/Bucharest')->format('Y-m-d H:i:s');
            }
        }
        sort($deadlines);
        $nature = array_values(array_unique((array) ($item['contract-nature'] ?? [])));
        $contract = count($nature) === 1 ? (['supplies' => 'Furnizare', 'services' => 'Servicii', 'works' => 'Lucrări'][$nature[0]] ?? $nature[0]) : null;
        $procedure = $this->text($item['procedure-type'] ?? null);
        $publication = $item['publication-date'] ?? null;
        $description = $this->text($item['description-proc'] ?? null) ?? $this->text($item['description-lot'] ?? null);
        $attributes = [
            'notice_number' => $id, 'title' => $title, 'description' => $description,
            'authority_name' => $this->text($item['buyer-name'] ?? null),
            'cpv_code' => implode(', ', array_unique((array) ($item['classification-cpv'] ?? []))),
            'publication_date' => is_string($publication) ? substr($publication, 0, 10) : null,
            'submission_deadline' => $deadlines[0] ?? null,
            'estimated_value' => is_numeric($item['estimated-value-proc'] ?? null) ? $item['estimated-value-proc'] : null,
            'currency' => $this->text($item['estimated-value-cur-proc'] ?? null),
            'locality' => $this->text($item['buyer-city'] ?? null),
            'nuts_code' => implode(', ', array_values(array_unique(array_filter((array) ($item['place-of-performance'] ?? []), fn ($code) => is_string($code) && preg_match('/^RO\d{3}$/D', $code))))),
            'contract_type' => $contract,
            'procedure_type' => ['open' => 'Licitație deschisă', 'restricted' => 'Licitație restrânsă'][$procedure ?? ''] ?? $procedure,
            'notice_type' => $this->text($item['notice-type'] ?? null),
            'status' => 'Publicat',
        ];

        return app(NoticeIngestor::class)->ingest('ted', $id, $attributes, $item, $seapId);
    }
}
