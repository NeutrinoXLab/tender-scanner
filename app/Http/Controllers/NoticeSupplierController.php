<?php

namespace App\Http\Controllers;

use App\Models\Notice;
use App\Services\NoticeSupplierQueries;
use App\Services\SupplierSearchProvider;
use App\Services\SupplierSearchResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class NoticeSupplierController extends Controller
{
    public function index(Request $request, Notice $notice, NoticeSupplierQueries $queries, SupplierSearchProvider $provider): View|RedirectResponse
    {
        if ($notice->canonical_notice_id !== null) {
            return redirect()->route('notices.suppliers', ['notice' => $notice->canonical_notice_id] + $request->only(['q', 'term']));
        }
        $validated = $request->validate([
            'q' => [$request->has('q') ? 'required' : 'nullable', 'string', 'max:240'],
            'term' => ['nullable', 'integer', 'min:0', 'max:11'],
        ]);
        $suggestions = $queries->suggest($notice);
        $term = (int) ($validated['term'] ?? 0);
        if ($term > 0 && ! isset($suggestions[$term])) {
            throw ValidationException::withMessages(['term' => 'Termenul selectat nu mai este disponibil.']);
        }
        $suggestion = $suggestions[$term] ?? null;
        $query = $validated['q'] ?? $suggestion['query'] ?? '';
        $context = $queries->context($notice, $suggestion, $query);
        $result = null;
        $configured = $provider->isConfigured();
        if ($request->has('q')) {
            try {
                $result = $provider->search($query, $context);
            } catch (Throwable) {
                $result = new SupplierSearchResult('error', message: 'Sursa de furnizori este momentan indisponibilă.');
            }
        }

        return view('notices.suppliers', compact('notice', 'suggestions', 'term', 'query', 'context', 'result', 'configured'));
    }
}
