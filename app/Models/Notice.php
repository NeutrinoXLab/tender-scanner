<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notice extends Model
{
    protected $fillable = [
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
        'estimated_value' => 'decimal:2',

        'publication_date' => 'datetime',
        'submission_deadline' => 'datetime',

        'is_electronic_submission' => 'boolean',
        'is_eu_funded' => 'boolean',

        'raw_data' => 'array',
    ];
}