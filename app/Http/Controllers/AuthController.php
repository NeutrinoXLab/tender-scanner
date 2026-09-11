<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
        $credentials = $request->validate(['email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:72']]);
        $key = 'login:'.hash('sha256', $credentials['email'].'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Prea multe încercări. Reîncearcă peste '.RateLimiter::availableIn($key).' secunde.']);
        }
        if (! Auth::guard('web')->attempt($credentials + ['is_active' => true])) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'Date de autentificare incorecte sau cont inactiv.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', $request->user()->getAuthPassword());

        return redirect()->intended(route('notices.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
