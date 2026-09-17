<?php

namespace App\Services;

use App\Models\Notice;
use App\Models\NoticeRevision;
use App\Models\NoticeSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class NoticeIngestor
{
    public function ingest(string $source, string $id, array $attributes, array $payload, ?string $seapReference = null): Notice
    {
        if (! in_array($source, ['seap', 'ted'], true) || $id === '') {
            throw new InvalidArgumentException('Sursă sau identificator invalid.');
        }

        return DB::transaction(function () use ($source, $id, $attributes, $payload, $seapReference): Notice {
            $entry = NoticeSource::where('source', $source)->where('external_id', $id)->first();
            $own = $entry?->notice ?? Notice::where($source === 'seap' ? 'seap_id' : 'ted_id', $id)->first();
            if ($own === null && $source === 'seap' && ! empty($attributes['notice_number'])) {
                $candidates = Notice::where('source', 'seap')->whereNull('canonical_notice_id')
                    ->where('notice_number', $attributes['notice_number'])->where('seap_metadata->family', data_get($attributes, 'seap_metadata.family'))->get();
                if ($candidates->count() > 1) {
                    throw new InvalidArgumentException('Număr SEAP ambiguu; sunt necesare verificări înainte de unificare.');
                }
                if ($candidates->count() === 1) {
                    $own = $candidates->first();
                }
            }
            $own = $this->canonical($own);
            $cross = null;
            if ($source === 'ted' && $seapReference !== null) {
                $cross = NoticeSource::where('source', 'seap')->where('external_id', $seapReference)->first()?->notice
                    ?? Notice::where('seap_id', $seapReference)->first();
            } elseif ($source === 'seap' && ! isset($attributes['seap_metadata'])) {
                $references = $this->tedReferences($payload);
                $matches = NoticeSource::where('source', 'ted')->whereIn('external_id', $references)->pluck('notice_id')->unique();
                if ($matches->count() === 1) {
                    $cross = Notice::find($matches->first());
                }
                $cross ??= Notice::where('seap_id', $id)->first();
            }
            if ($source === 'ted' && $cross === null) {
                $matches = [];
                foreach (NoticeSource::where('source', 'seap')->where('payload', 'like', '%'.$id.'%')->get() as $candidate) {
                    if (isset($candidate->normalized_data['seap_metadata'])) {
                        continue;
                    }
                    if (in_array($id, $this->tedReferences($candidate->payload ?? []), true)) {
                        $matches[$candidate->notice_id] = $candidate->notice;
                    }
                }
                if (count($matches) === 1) {
                    $cross = reset($matches);
                }
            }
            $cross = $this->canonical($cross);
            $notice = $own ?? $cross ?? new Notice(['source' => $source]);
            $isNew = ! $notice->exists;
            if ($own && $cross && $own->id !== $cross->id) {
                $notice = $source === 'seap' ? $own : $cross;
                $other = $notice->id === $own->id ? $cross : $own;
                $this->merge($notice, $other);
            }
            $normalized = $attributes;
            if ($source === 'seap' && isset($normalized['seap_metadata'])) {
                $normalized['seap_metadata'] = array_replace($notice->seap_metadata ?? [], $normalized['seap_metadata']);
            }
            foreach (['source', 'seap_id', 'ted_id', 'ted_url', 'seap_url', 'raw_data', 'ted_data'] as $key) {
                unset($normalized[$key]);
            }
            $authoritativeAdv = $source === 'seap' && data_get($attributes, 'seap_metadata.family') === 'ADV';
            $normalized = array_filter($normalized, fn ($value, $key) => $value !== null
                || ($authoritativeAdv && in_array($key, ['submission_deadline', 'status'], true)), ARRAY_FILTER_USE_BOTH);
            $before = $this->normalize($entry?->normalized_data ?? []);
            if (isset($normalized['seap_metadata'], $before['seap_metadata'])) {
                $normalized['seap_metadata'] = array_replace($before['seap_metadata'], array_filter($normalized['seap_metadata'], fn ($value) => $value !== null));
                $normalized['seap_metadata']['ted_references'] = array_replace(
                    $before['seap_metadata']['ted_references'] ?? [],
                    $normalized['seap_metadata']['ted_references'] ?? []
                );
            }
            $normalized = $this->normalize($normalized);
            $normalized = array_replace($before, $normalized);
            ksort($normalized);
            $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $fingerprint = hash('sha256', $json);

            $hasSeap = $notice->exists && ($notice->source === 'seap' || $notice->sources()->where('source', 'seap')->exists());
            if ($source === 'seap' || ! $hasSeap) {
                $notice->fill($normalized);
                $notice->source = $source;
            } else {
                foreach ($normalized as $key => $value) {
                    if ($notice->getAttribute($key) === null || $notice->getAttribute($key) === '') {
                        $notice->setAttribute($key, $value);
                    }
                }
            }
            if ($source === 'seap') {
                if (preg_match('/^(?:CN|SCN|ADV)\d+$/', $attributes['notice_number'] ?? '')) {
                    $notice->seap_official_number = $attributes['notice_number'];
                }
                $notice->seap_id = $id;
                $notice->seap_url = $attributes['seap_url'] ?? 'https://e-licitatie.ro/pub/notices/pi-notice/view/v2/'.$id;
                if (! isset($payload['piNoticeV2']) && isset($notice->raw_data['piNoticeV2'])) {
                    $payload = array_replace($notice->raw_data, $payload);
                }
                if (isset($attributes['seap_metadata']) && is_array($notice->raw_data)) {
                    $payload = array_replace($notice->raw_data, $payload);
                }
                $notice->raw_data = $payload;
            } else {
                $notice->ted_id ??= $id;
                $notice->ted_url ??= 'https://ted.europa.eu/ro/notice/-/detail/'.$id;
                $notice->ted_data = $payload;
                if ($notice->seap_id === null && $seapReference !== null && ! Notice::where('seap_id', $seapReference)->exists()) {
                    $notice->seap_id = $seapReference;
                }
            }
            $notice->save();
            $changed = $entry === null || $before !== $normalized;
            $event = $entry === null ? 'source_added' : 'updated';
            $entry ??= new NoticeSource(['source' => $source, 'external_id' => $id, 'first_seen_at' => now()]);
            $entry->fill(['notice_id' => $notice->id, 'url' => $source === 'seap' ? $notice->seap_url : 'https://ted.europa.eu/ro/notice/-/detail/'.$id,
                'payload' => $payload, 'normalized_data' => $normalized, 'fingerprint' => $fingerprint, 'last_seen_at' => now(),
                'matched_by' => $entry->matched_by ?? ($cross ? 'exact_reference' : 'external_id')])->save();
            if ($changed) {
                $fields = array_keys(array_filter($normalized, fn ($value, $key) => ! array_key_exists($key, $before) || $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));
                NoticeRevision::create(['notice_id' => $notice->id, 'notice_source_id' => $entry->id, 'event_type' => $event,
                    'before_data' => $before, 'after_data' => $normalized, 'changed_fields' => $fields]);
            }
            app(ScanContext::class)->record($source, $id, $notice->id, $changed);
            $notice->wasRecentlyCreated = $isNew;

            return $notice;
        }, 5);
    }

    private function normalize(array $data): array
    {
        foreach (['publication_date', 'submission_deadline'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = CarbonImmutable::parse($data[$field])->format('Y-m-d H:i:s');
            }
        }
        if (isset($data['estimated_value']) && is_numeric($data['estimated_value'])) {
            $data['estimated_value'] = number_format((float) $data['estimated_value'], 2, '.', '');
        }
        foreach (['is_eu_funded', 'is_electronic_submission'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (bool) $data[$field];
            }
        }
        ksort($data);

        return $data;
    }

    private function canonical(?Notice $notice): ?Notice
    {
        while ($notice?->canonical_notice_id !== null) {
            $notice = Notice::findOrFail($notice->canonical_notice_id);
        }

        return $notice;
    }

    private function merge(Notice $canonical, Notice $other): void
    {
        $other->forceFill(['canonical_notice_id' => $canonical->id, 'seap_id' => null, 'ted_id' => null, 'seap_official_number' => null])->save();
        NoticeSource::where('notice_id', $other->id)->update(['notice_id' => $canonical->id]);
        NoticeRevision::where('notice_id', $other->id)->update(['notice_id' => $canonical->id]);
        Notice::where('canonical_notice_id', $other->id)->update(['canonical_notice_id' => $canonical->id]);
        foreach ($other->getFillable() as $key) {
            if ($canonical->getAttribute($key) === null && $other->getAttribute($key) !== null) {
                $canonical->setAttribute($key, $other->getAttribute($key));
            }
        }
        $ted = $canonical->sources()->where('source', 'ted')->first();
        if ($canonical->ted_id === null && $ted) {
            $canonical->ted_id = $ted->external_id;
            $canonical->ted_url = $ted->url;
            $canonical->ted_data = $ted->payload;
        }
        NoticeRevision::create(['notice_id' => $canonical->id, 'event_type' => 'merged',
            'before_data' => ['notice_id' => $other->id], 'after_data' => ['notice_id' => $canonical->id], 'changed_fields' => []]);
    }

    private function tedReferences(array $data): array
    {
        $ids = [];
        foreach ($data as $key => $value) {
            if (preg_match('/previous|related|history|change/i', (string) $key)) {
                continue;
            }
            if (is_array($value)) {
                $ids = array_merge($ids, $this->tedReferences($value));
            } elseif (in_array($key, ['tedNoticeNumber', 'tedPublicationNumber', 'tedUrl', 'tedNoticeUrl', 'ojsNoticeNumber'], true)
                && is_string($value) && preg_match('~(?:^|/)(\d{1,7}-\d{4})(?:$|[/?#])~', $value, $match)) {
                $ids[] = $match[1];
            }
        }

        return array_unique($ids);
    }
}
