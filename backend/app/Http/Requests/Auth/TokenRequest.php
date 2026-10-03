<?php

namespace App\Http\Requests\Auth;

/**
 * Token login for non-browser clients (e.g. the future Flutter app).
 */
class TokenRequest extends LoginRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:100'],
        ];
    }
}
