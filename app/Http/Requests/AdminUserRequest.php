<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-users') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($this->route('user'))],
            'password' => [$this->route('user') ? 'nullable' : 'required', 'string', Password::min(12), 'max:72', 'confirmed'],
            'role' => ['required', 'string', Rule::in(['admin', 'user'])],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['email.unique' => 'Există deja un cont cu acest email.', 'password.min' => 'Parola trebuie să aibă minimum 12 caractere.',
            'password.confirmed' => 'Confirmarea parolei nu coincide.'];
    }
}
