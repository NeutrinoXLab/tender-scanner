<?php

namespace App\Http\Controllers;

use App\Models\Notice;
use App\Models\ScanRun;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NoticeController extends Controller
{
    public function index(): View
    {
        $validated = request()->validate([
            'source' => ['nullable', 'string', 'in:seap,ted'],
            'is_eu_funded' => ['nullable', 'string', 'in:1,0,unknown'],
            'funding_program' => ['nullable', 'string', 'max:255'],
            'seap_history' => ['nullable', 'boolean'],
            'collection' => ['nullable', 'string', Rule::in(['all', 'saved', 'ignored'])],
            'personal_status' => ['nullable', 'string', Rule::in(array_keys(Notice::PERSONAL_STATUSES))],
        ]);
        $source = $validated['source'] ?? null;
        $euFunded = $validated['is_eu_funded'] ?? null;
        $fundingProgram = $validated['funding_program'] ?? null;
        $collection = $validated['collection'] ?? 'all';
        $personalStatus = $validated['personal_status'] ?? null;

        $search = request('search');
        $county = request('county');
        $cpv = request('cpv');
        $minValue = request('min_value');
        $maxValue = request('max_value');
        $deadlineFrom = request('deadline_from');
        $deadlineTo = request('deadline_to');
        $status = request('status');
        $contractType = request('contract_type');
        $procedureType = request('procedure_type');
        $assignmentType = request('assignment_type');

        $counties = Notice::query()->whereNull('canonical_notice_id')
            ->whereNotNull('county')
            ->where('county', '!=', '')
            ->distinct()
            ->orderBy('county')
            ->pluck('county');

        $statuses = Notice::query()->whereNull('canonical_notice_id')
            ->whereNotNull('status')
            ->where('status', '!=', '')
            ->distinct()
            ->orderBy('status')
            ->pluck('status');

        $contractTypes = Notice::query()->whereNull('canonical_notice_id')
            ->whereNotNull('contract_type')
            ->where('contract_type', '!=', '')
            ->distinct()
            ->orderBy('contract_type')
            ->pluck('contract_type');

        $procedureTypes = Notice::query()->whereNull('canonical_notice_id')
            ->whereNotNull('procedure_type')
            ->where('procedure_type', '!=', '')
            ->distinct()
            ->orderBy('procedure_type')
            ->pluck('procedure_type');

        $assignmentTypes = Notice::query()->whereNull('canonical_notice_id')
            ->whereNotNull('contract_assignment_type')
            ->where('contract_assignment_type', '!=', '')
            ->distinct()
            ->orderBy('contract_assignment_type')
            ->pluck('contract_assignment_type');

        $notices = Notice::query()->whereNull('canonical_notice_id')->with('sources')
            ->seapOpportunities(request()->boolean('seap_history') || $collection !== 'all')
            ->when($collection === 'saved', fn ($query) => $query->where('is_saved', true))
            ->when($collection === 'ignored', fn ($query) => $query->where('is_ignored', true))
            ->when($personalStatus, fn ($query) => $query->where('personal_status', $personalStatus))
            ->when($source === 'seap', fn ($query) => $query->where('source', 'seap'))
            ->when($source === 'ted', fn ($query) => $query->where(function ($query) {
                $query->whereNotNull('ted_id')->orWhereHas('sources', fn ($sources) => $sources->where('source', 'ted'));
            }))
            ->when($search, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('notice_number', 'like', "%{$search}%")
                        ->orWhere('authority_name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('sources', fn ($sources) => $sources->where('external_id', 'like', "%{$search}%"));
                });
            })
            ->when($county, function ($query, $county) {
                $query->where('county', $county);
            })
            ->when($cpv, function ($query, $cpv) {
                $query->where('cpv_code', 'like', "%{$cpv}%");
            })
            ->when($minValue !== null && $minValue !== '', function ($query) use ($minValue) {
                $query->where('estimated_value', '>=', $minValue);
            })
            ->when($maxValue !== null && $maxValue !== '', function ($query) use ($maxValue) {
                $query->where('estimated_value', '<=', $maxValue);
            })
            ->when($deadlineFrom, function ($query, $deadlineFrom) {
                $query->whereDate('submission_deadline', '>=', $deadlineFrom);
            })
            ->when($deadlineTo, function ($query, $deadlineTo) {
                $query->whereDate('submission_deadline', '<=', $deadlineTo);
            })
            ->when($status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($contractType, function ($query, $contractType) {
                $query->where('contract_type', $contractType);
            })
            ->when($procedureType, function ($query, $procedureType) {
                $query->where('procedure_type', $procedureType);
            })
            ->when($assignmentType, function ($query, $assignmentType) {
                $query->where('contract_assignment_type', $assignmentType);
            })
            ->when($euFunded === 'unknown', function ($query) {
                $query->whereNull('is_eu_funded');
            })
            ->when(in_array($euFunded, ['1', '0'], true), function ($query) use ($euFunded) {
                $query->where('is_eu_funded', $euFunded === '1');
            })
            ->when($fundingProgram !== null && $fundingProgram !== '', function ($query) use ($fundingProgram) {
                $query->where('funding_program', 'like', "%{$fundingProgram}%");
            })
            ->orderByDesc('publication_date')
            ->paginate(20)
            ->withQueryString();

        $latestScan = ScanRun::latest('id')->first();

        return view('notices.index', compact(
            'notices',
            'collection',
            'personalStatus',
            'latestScan',
            'source',
            'euFunded',
            'fundingProgram',
            'search',
            'county',
            'cpv',
            'minValue',
            'maxValue',
            'deadlineFrom',
            'deadlineTo',
            'status',
            'contractType',
            'procedureType',
            'assignmentType',
            'counties',
            'statuses',
            'contractTypes',
            'procedureTypes',
            'assignmentTypes'
        ));
    }

    public function show(Notice $notice)
    {
        if ($notice->canonical_notice_id !== null) {
            return redirect()->route('notices.show', $notice->canonical_notice_id);
        }
        $notice->load('sources');
        $revisions = $notice->revisions()->latest('id')->paginate(15);

        return view('notices.show', compact('notice', 'revisions'));
    }
}
