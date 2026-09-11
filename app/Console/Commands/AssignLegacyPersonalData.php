<?php

namespace App\Console\Commands;

use App\Models\Notice;
use App\Models\User;
use App\Models\UserNotice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssignLegacyPersonalData extends Command
{
    protected $signature = 'app:assign-legacy-personal-data {email : Emailul administratorului destinatar} {--dry-run : Numără fără modificări}';

    protected $description = 'Copiază datele personale globale unui admin, fără ștergere sau suprascriere';

    public function handle(): int
    {
        $admin = User::where('email', mb_strtolower(trim($this->argument('email'))))->first();
        if (! $admin?->isAdmin()) {
            $this->error('Destinatarul trebuie să fie un administrator activ.');

            return self::FAILURE;
        }
        $copied = 0;
        $conflicts = 0;
        Notice::whereNull('legacy_personal_user_id')->where(function ($query) {
            $query->where('is_saved', true)->orWhere('is_ignored', true)
                ->orWhere('personal_status', '!=', 'new')->orWhereNotNull('personal_notes');
        })->chunkById(100, function ($notices) use ($admin, &$copied, &$conflicts): void {
            foreach ($notices as $notice) {
                DB::transaction(function () use ($notice, $admin, &$copied, &$conflicts): void {
                    $legacy = Notice::whereKey($notice->id)->lockForUpdate()->firstOrFail();
                    if ($legacy->legacy_personal_user_id !== null) {
                        return;
                    }
                    $target = $legacy;
                    $visited = [];
                    while ($target->canonical_notice_id !== null) {
                        if (isset($visited[$target->id])) {
                            $conflicts++;

                            return;
                        }
                        $visited[$target->id] = true;
                        $target = Notice::findOrFail($target->canonical_notice_id);
                    }
                    if (UserNotice::where('user_id', $admin->id)->where('notice_id', $target->id)->exists()) {
                        $conflicts++;

                        return;
                    }
                    if (! $this->option('dry-run')) {
                        $state = UserNotice::firstOrCreate(['user_id' => $admin->id, 'notice_id' => $target->id], [
                            'is_saved' => $legacy->is_saved, 'is_ignored' => $legacy->is_ignored,
                            'personal_status' => $legacy->personal_status, 'personal_notes' => $legacy->personal_notes,
                        ]);
                        if (! $state->wasRecentlyCreated) {
                            $conflicts++;

                            return;
                        }
                        $legacy->forceFill(['legacy_personal_user_id' => $admin->id])->save();
                    }
                    $copied++;
                });
            }
        });
        $this->info(($this->option('dry-run') ? 'Disponibile pentru copiere: ' : 'Copiate: ').$copied);
        $this->line('Conflicte păstrate pentru verificare manuală: '.$conflicts);
        $this->line('Coloanele globale originale rămân păstrate și nu sunt afișate în aplicație.');

        return $conflicts > 0 ? self::FAILURE : self::SUCCESS;
    }
}
