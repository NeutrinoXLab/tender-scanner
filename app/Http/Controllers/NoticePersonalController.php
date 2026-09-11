<?php

namespace App\Http\Controllers;

use App\Models\Notice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NoticePersonalController extends Controller
{
    public function update(Request $request, Notice $notice): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['save', 'unsave', 'ignore', 'restore', 'update'])],
            'personal_status' => ['required_if:action,update', 'string', Rule::in(array_keys(Notice::PERSONAL_STATUSES))],
            'personal_notes' => ['nullable', 'string', 'max:20000'],
        ]);
        while ($notice->canonical_notice_id !== null) {
            $notice = Notice::findOrFail($notice->canonical_notice_id);
        }
        $attributes = match ($validated['action']) {
            'save' => ['is_saved' => true, 'is_ignored' => false],
            'unsave' => ['is_saved' => false],
            'ignore' => ['is_ignored' => true, 'is_saved' => false],
            'restore' => ['is_ignored' => false],
            'update' => ['personal_status' => $validated['personal_status'], 'personal_notes' => $validated['personal_notes'] ?? null],
        };
        $notice->forceFill($attributes)->save();
        $message = match ($validated['action']) {
            'save' => 'Licitația a fost salvată.',
            'unsave' => 'Licitația a fost eliminată din salvate.',
            'ignore' => 'Licitația a fost trecută în ignorate.',
            'restore' => 'Licitația nu mai este ignorată.',
            'update' => 'Statusul personal și notițele au fost salvate.',
        };

        return redirect()->route('notices.show', $notice)->with('personal_message', $message);
    }
}
