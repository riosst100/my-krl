<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{email: string, password: string}
     */
    public function credentials(): array
    {
        return [
            'email' => strtolower(trim((string) $this->input('email'))),
            'password' => (string) $this->input('password'),
        ];
    }

    public function remember(): bool
    {
        // Website users stay signed in across browser restarts by default.
        return $this->boolean('remember', true);
    }
}
