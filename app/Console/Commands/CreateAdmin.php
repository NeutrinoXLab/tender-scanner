<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin';

    protected $description = 'Creează primul administrator activ, fără parolă în argumente sau în repository';

    public function handle(): int
    {
        if (User::where('role', 'admin')->where('is_active', true)->exists()) {
            $this->error('Există deja un administrator activ. Folosește administrarea utilizatorilor.');

            return self::FAILURE;
        }
        if (! $this->input->isInteractive()) {
            $this->error('Rulează comanda interactiv, pentru introducerea ascunsă a parolei.');

            return self::FAILURE;
        }
        $data = ['name' => $this->ask('Nume'), 'email' => mb_strtolower(trim($this->ask('Email') ?? '')),
            'password' => $this->secret('Parolă (minimum 12 caractere)', false),
            'password_confirmation' => $this->secret('Confirmă parola', false)];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(12), 'max:72', 'confirmed'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        $created = DB::transaction(function () use ($data): bool {
            $users = User::orderBy('id')->lockForUpdate()->get();
            if ($users->contains(fn (User $user) => $user->isAdmin())) {
                return false;
            }
            User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'],
                'role' => 'admin', 'is_active' => true]);

            return true;
        });
        if (! $created) {
            $this->error('Există deja un administrator activ.');

            return self::FAILURE;
        }
        $this->info('Administrator creat. Datele personale globale nu au fost mutate automat.');
        $this->line('Pentru transferul controlat: php artisan app:assign-legacy-personal-data '.$data['email'].' --dry-run');

        return self::SUCCESS;
    }
}
