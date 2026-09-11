<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    public function index(): View
    {
        return view('admin.users.index', ['users' => User::orderBy('name')->get()]);
    }

    public function create(): View
    {
        return view('admin.users.form', ['user' => new User(['is_active' => true])]);
    }

    public function store(AdminUserRequest $request): RedirectResponse
    {
        User::create($request->safe()->only(['name', 'email', 'password', 'role', 'is_active']));

        return redirect()->route('admin.users.index')->with('message', 'Utilizatorul a fost creat.');
    }

    public function edit(User $user): View
    {
        return view('admin.users.form', compact('user'));
    }

    public function update(AdminUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->safe()->only(['name', 'email', 'password', 'role', 'is_active']);
        DB::transaction(function () use ($user, $data): void {
            $admins = User::where('role', 'admin')->where('is_active', true)->orderBy('id')->lockForUpdate()->get();
            $user->refresh();
            if ($user->isAdmin() && ($data['role'] !== 'admin' || ! $data['is_active']) && $admins->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'Trebuie să rămână cel puțin un administrator activ.']);
            }
            if (empty($data['password'])) {
                unset($data['password']);
            } else {
                $data['remember_token'] = Str::random(60);
            }
            $user->forceFill($data)->save();
        });

        return redirect()->route('admin.users.index')->with('message', 'Utilizatorul a fost actualizat.');
    }
}
