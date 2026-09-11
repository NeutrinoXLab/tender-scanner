<?php

namespace App\Services;

use App\Models\Notice;

class NoticeSupplierQueries
{
    /** @return array<int, array{query: string, source: string, evidence: string}> */
    public function suggest(Notice $notice): array
    {
        $suggestions = [];
        $add = function (mixed $text, string $source, ?string $evidence = null) use ($notice, &$suggestions): void {
            if (! is_string($text)) {
                return;
            }
            $query = $this->clean($text, $notice->authority_name);
            if ($query === '' || mb_strlen($query) < 3) {
                return;
            }
            $key = mb_strtolower($query);
            $suggestions[$key] ??= ['query' => $query, 'source' => $source, 'evidence' => mb_substr($this->plain($evidence ?? $text), 0, 2000)];
        };
        $sections = data_get($notice->raw_data, 'piNoticeV2.section2', []);
        foreach (is_array($sections) ? $sections : [] as $section) {
            $lots = data_get($section, 'section2_2.lots.items', []);
            foreach (is_array($lots) ? $lots : [] as $index => $lot) {
                $title = data_get($lot, 'title');
                $description = data_get($lot, 'descriptionOfProcurement');
                $add($title, 'Lot '.($index + 1), is_string($title) && is_string($description) ? $title.' '.$description : null);
            }
            $add(data_get($section, 'section2_1.title'), 'Obiectul contractului');
        }
        $add(data_get($notice->raw_data, 'section21.section22.title'), 'Lot / obiect');
        $add(data_get($notice->raw_data, 'section21.title'), 'Obiectul contractului');
        $add($notice->title, 'Titlu');
        $texts = $notice->summaryItems();
        foreach ($this->strings(data_get($notice->ted_data, 'description-lot', [])) as $text) {
            $texts[] = ['text' => $text, 'label' => 'Lot TED'];
        }
        foreach ($texts as $entry) {
            $text = $entry['text'];
            if (preg_match_all('/(?:produse|bunuri|echipamente)\s*(?:solicitate|necesare)?\s*:\s*([^\n.]+)/iu', $text, $lists)) {
                foreach ($lists[1] as $list) {
                    foreach (preg_split('/[,;]+/u', $list) ?: [] as $part) {
                        $add($part, $entry['label']);
                    }
                }
            }
            if (preg_match_all('/\b(?:laptop(?:uri)?|notebook(?:uri)?|imprimant[ăa]|imprimante|multifunc[țt]ionale?|monitoare?|calculatoare?|desktop(?:uri)?|servere?|scanere?|tablete|videoproiectoare?|echipamente\s+IT|scaune|mobilier|autovehicule|anvelope|tractoare|panouri\s+fotovoltaice|pompe|generatoare|consumabile\s+medicale)\b/iu', $text, $matches)) {
                foreach ($matches[0] as $term) {
                    $add($term, $entry['label']);
                }
            }
        }
        if ($suggestions === [] && $notice->cpv_code) {
            $add($notice->cpv_code, 'CPV');
        }

        return array_slice(array_values($suggestions), 0, 12);
    }

    /** @param array{query: string, source: string, evidence: string}|null $suggestion
     * @return array{notice_id: int, cpv: ?string, ean: ?string, quantity: ?array{value: float, unit: string}, specifications: ?string}
     */
    public function context(Notice $notice, ?array $suggestion, string $query): array
    {
        $evidence = $suggestion !== null && $suggestion['query'] === $query ? $suggestion['evidence'] : $query;
        $quantity = null;
        if (preg_match('/\b(\d+(?:[.,]\d+)?)\s*(buc(?:[ăa][țt]i|\.)?|unit[ăa][țt]i)\b/iu', $evidence, $match)) {
            $quantity = ['value' => (float) str_replace(',', '.', $match[1]), 'unit' => 'buc'];
        }

        return ['notice_id' => $notice->id, 'cpv' => $notice->cpv_code, 'ean' => $this->ean($evidence),
            'quantity' => $quantity, 'specifications' => $evidence !== '' ? mb_substr($evidence, 0, 2000) : null];
    }

    private function clean(string $text, ?string $authority): string
    {
        $text = $this->plain($text);
        $text = preg_replace('/^Rom[âa]nia\s*[–—]\s*[^–—]+\s*[–—]\s*/iu', '', $text) ?? $text;
        if ($authority !== null && $authority !== '') {
            $text = preg_replace('/'.preg_quote($authority, '/').'/iu', ' ', $text) ?? $text;
        }
        $text = preg_replace('/\b(?:contract\s+de\s+furnizare|procedur[ăa]\s+de\s+atribuire|achizi[țt]i(?:e|a)(?:\s+public[ăa])?(?:\s+de)?|furnizare(?:a)?(?:\s+de)?)\b\s*/iu', '', $text) ?? $text;
        $text = preg_split('/\b(?:pentru\s+dotarea|[îi]n\s+cadrul|[îi]n\s+vederea|pentru\s+proiectul|finan[țt]at[ăa]?\s+prin)\b/iu', $text, 2)[0];
        $text = preg_replace('/\b(?:EAN(?:-13)?\s*[:#]?\s*\d{8,14}|\d+(?:[.,]\d+)?\s*(?:buc(?:[ăa][țt]i|\.)?|unit[ăa][țt]i))\b/iu', '', $text) ?? $text;
        $text = preg_replace('/\(Rev\.\s*2\)/iu', '', $text) ?? $text;

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? '', " \t\n\r\0\x0B-–—:;,./"), 0, 240);
    }

    private function plain(string $text): string
    {
        $text = preg_replace('/<\s*(?:br\b[^>]*|\/p|\/div|\/li)\s*>/iu', ' ', $text) ?? $text;

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** @return array<string> */
    private function strings(mixed $value): array
    {
        if (is_string($value)) {
            return [$this->plain($value)];
        }
        $result = [];
        foreach (is_array($value) ? $value : [] as $item) {
            $result = array_merge($result, $this->strings($item));
        }

        return $result;
    }

    private function ean(string $text): ?string
    {
        if (! preg_match('/\bEAN(?:-13)?\s*[:#]?\s*(\d{13}|\d{8})\b/iu', $text, $match)) {
            return null;
        }
        $digits = $match[1];
        $sum = 0;
        for ($index = strlen($digits) - 2, $weight = 3; $index >= 0; $index--, $weight = 4 - $weight) {
            $sum += (int) $digits[$index] * $weight;
        }

        return (10 - $sum % 10) % 10 === (int) substr($digits, -1) ? $digits : null;
    }
}
