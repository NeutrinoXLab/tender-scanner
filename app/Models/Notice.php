<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Notice extends Model
{
    use HasFactory;

    public function personalEntries(): HasMany
    {
        return $this->hasMany(UserNotice::class);
    }

    public const PERSONAL_STATUSES = [
        'new' => 'Nouă', 'analyzing' => 'Analizez', 'requesting_quote' => 'Cer ofertă',
        'participating' => 'Particip', 'declined' => 'Renunț',
    ];

    protected $fillable = [
        'seap_metadata',
        'source', 'ted_id', 'ted_url', 'ted_data',
        'seap_id',
        'notice_number',
        'title',
        'description',

        'notice_type',
        'procedure_type',
        'contract_type',
        'contract_assignment_type',

        'authority_name',
        'authority_seap_id',

        'cpv_code',

        'estimated_value',
        'currency',

        'publication_date',
        'submission_deadline',

        'county',
        'locality',
        'nuts_code',

        'award_criteria',
        'contract_duration_months',

        'is_electronic_submission',
        'is_eu_funded',
        'funding_program',

        'status',

        'seap_url',

        'raw_data',
    ];

    protected $casts = [
        'is_saved' => 'boolean',
        'is_ignored' => 'boolean',
        'seap_metadata' => 'array',
        'estimated_value' => 'decimal:2',

        'publication_date' => 'datetime',
        'submission_deadline' => 'datetime',

        'is_electronic_submission' => 'boolean',
        'is_eu_funded' => 'boolean',

        'raw_data' => 'array',
        'ted_data' => 'array',
    ];

    public function sources(): HasMany
    {
        return $this->hasMany(NoticeSource::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(NoticeRevision::class);
    }

    public function scopeSeapOpportunities(Builder $query, bool $includeHistory = false): void
    {
        $query->where(function (Builder $query) use ($includeHistory): void {
            $query->where('source', '!=', 'seap')->orWhereNull('seap_metadata')
                ->orWhere(function (Builder $query) use ($includeHistory): void {
                    $query->whereIn('seap_metadata->category', ['opportunity', 'planning']);
                    if (! $includeHistory) {
                        $query->where(function (Builder $query): void {
                            $query->whereNull('submission_deadline')->orWhere('submission_deadline', '>=', now('Europe/Bucharest')->format('Y-m-d H:i:s'));
                        })->where(function (Builder $query): void {
                            $query->whereNull('status')->orWhereIn('status', ['Publicat', 'In desfasurare', 'În desfășurare']);
                        });
                    }
                });
        });
    }

    /** @return array<int, array{label: string, text: string}> */
    public function summaryItems(): array
    {
        $items = [];
        $seen = [];
        $add = function (string $label, mixed $value) use (&$items, &$seen): void {
            if (! is_string($value)) {
                return;
            }
            $text = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace('/<\s*(?:br\b[^>]*|\/p|\/div|\/li)\s*>/iu', ' ', $text);
            $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
            if ($text === '' || isset($seen[$text])) {
                return;
            }
            $seen[$text] = true;
            $items[] = ['label' => $label, 'text' => $text];
        };
        $add('Obiectul achiziției', $this->description);
        $add('Cerințe și cantități', data_get($this->raw_data, 'section21.section22.shortDescription'));
        $sections = data_get($this->raw_data, 'piNoticeV2.section2', []);
        foreach (is_array($sections) ? $sections : [] as $section) {
            $add('Obiectul achiziției', data_get($section, 'section2_1.description'));
            $lots = data_get($section, 'section2_2.lots.items', []);
            foreach (is_array($lots) ? $lots : [] as $lot) {
                $add('Cerințe și cantități', data_get($lot, 'descriptionOfProcurement'));
                $add('Informații suplimentare', data_get($lot, 'additionalInformation'));
            }
        }

        return $items;
    }
}
