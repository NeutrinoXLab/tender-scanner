<?php

namespace App\Http\Controllers;

use App\Models\Notice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $catalog = Notice::query()->whereNull('canonical_notice_id')->seapOpportunities();
        $ownEntries = fn (Builder|Relation $query): Builder|Relation => $query->where('user_id', $request->user()->id);
        $personalCatalog = Notice::query()->whereNull('canonical_notice_id')->seapOpportunities(true);
        $saved = (clone $personalCatalog)->whereHas('personalEntries', fn (Builder $query) => $ownEntries($query)->where('is_saved', true));
        $analyzing = (clone $personalCatalog)->whereHas('personalEntries', fn (Builder $query) => $ownEntries($query)->where('personal_status', 'analyzing'));

        return view('dashboard', [
            'sourceCounts' => (clone $catalog)->selectRaw('source, COUNT(*) as total')->groupBy('source')->pluck('total', 'source'),
            'savedCount' => (clone $saved)->count(),
            'analyzingCount' => (clone $analyzing)->count(),
            'recentNotices' => (clone $catalog)->orderByDesc('publication_date')->orderByDesc('id')->limit(5)->get(),
            'savedNotices' => $saved->with(['personalEntries' => $ownEntries])->orderByDesc('publication_date')->orderByDesc('id')->limit(4)->get(),
        ]);
    }
}
