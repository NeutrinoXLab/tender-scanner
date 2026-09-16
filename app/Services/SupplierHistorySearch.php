<?php

namespace App\Services;

use App\Models\Notice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupplierHistorySearch
{
    /** @return array<int, array<string, mixed>> */
    public function search(Notice $notice, string $keywords): array
    {
        preg_match('/\b(\d{8})(?:-\d)?\b/', $notice->cpv_code ?? '', $match);
        $cpv = $match[1] ?? null;
        $tokens = array_slice(array_values(array_filter(preg_split('/[^\pL\pN]+/u', $keywords) ?: [], fn (string $word): bool => mb_strlen($word) >= 4)), 0, 4);
        if ($cpv === null && $tokens === []) {
            return [];
        }
        $query = DB::table('supplier_history')->where(function ($query) use ($cpv, $tokens): void {
            if ($cpv !== null) {
                $query->where('cpv_code', 'like', substr($cpv, 0, 5).'%');
            } else {
                foreach ($tokens as $token) {
                    $term = '%'.str_replace(['%', '_'], '', $token).'%';
                    $query->where(function ($query) use ($term): void {
                        $query->where('cpv_name', 'like', $term)->orWhere('title', 'like', $term)->orWhereExists(function ($notices) use ($term): void {
                            $notices->selectRaw('1')->from('notices')->where('source', 'seap')
                                ->whereColumn('notices.notice_number', 'supplier_history.notice_number')->where('title', 'like', $term);
                        });
                    });
                }
            }
        });
        $groups = (clone $query)->select('supplier_key')->selectRaw('COUNT(DISTINCT contract_key) as contract_count, MAX(contract_date) as latest_contract')
            ->groupBy('supplier_key')->orderByDesc('latest_contract')->limit(20)->get();
        $result = [];
        foreach ($groups as $group) {
            $contracts = (clone $query)->where('supplier_key', $group->supplier_key)->orderByDesc('contract_date')->limit(5)->get();
            $first = $contracts->first();
            $result[] = ['name' => $first->supplier_name, 'cui' => $first->supplier_cui, 'count' => (int) $group->contract_count,
                'latest' => $group->latest_contract, 'contracts' => $contracts->map(function ($contract) use ($cpv): array {
                    $title = $contract->title ?? ($contract->notice_number !== '' ? Notice::where('source', 'seap')->where('notice_number', $contract->notice_number)->value('title') : null);

                    return ['number' => $contract->official_number ?? $contract->contract_number, 'date' => $contract->contract_date,
                        'type' => match ($contract->history_type) {
                            'direct_purchases' => 'Achiziție directă',
                            'direct_awards' => 'Notificare de atribuire directă',
                            default => 'Contract',
                        },
                        'cpv' => $contract->cpv_code, 'cpv_name' => $contract->cpv_name,
                        'match' => $cpv === null ? 'Cuvinte-cheie CPV / obiect' : (Str::startsWith($contract->cpv_code, $cpv) ? 'CPV identic' : 'Aceeași categorie CPV (5 cifre)'),
                        'title' => $title, 'notice' => $contract->notice_number, 'award' => $contract->award_number,
                        'value' => $contract->value, 'currency' => $contract->currency,
                        'url' => $contract->resource_url, 'row' => $contract->resource_row];
                })->all()];
        }

        return $result;
    }
}
