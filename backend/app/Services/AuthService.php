<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * New accounts always get the "user" role; role is never taken from input.
     */
    public function register(array $data): User
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
    }

    /**
     * Session (cookie) login on the given guard.
     *
     * @throws ValidationException
     */
    public function login(Request $request, string $guard, array $credentials, bool $remember, bool $requireAdmin = false): User
    {
        $auth = Auth::guard($guard);

        if (! $auth->attempt($credentials, $remember)) {
            throw $this->failed();
        }

        /** @var User $user */
        $user = $auth->user();

        if ($requireAdmin && ! $user->isAdmin()) {
            $auth->logout();

            // Same message as wrong credentials: do not reveal which accounts exist.
            throw $this->failed();
        }

        $request->session()->regenerate();

        return $user;
    }

    public function startSession(Request $request, User $user): void
    {
        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();
    }

    /**
     * Logs out of a single guard. The session id and CSRF token are rotated,
     * but other guards (e.g. an admin signed in in the same browser) are kept.
     */
    public function logout(Request $request, string $guard): void
    {
        Auth::guard($guard)->logout();

        if (! $request->hasSession()) {
            return;
        }

        $request->session()->regenerate(true);
        $request->session()->regenerateToken();
    }

    /**
     * Personal access token for mobile clients.
     *
     * @throws ValidationException
     */
    public function issueToken(array $credentials, string $deviceName): string
    {
        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw $this->failed();
        }

        return $user->createToken($deviceName)->plainTextToken;
    }

    private function failed(): ValidationException
    {
        return ValidationException::withMessages(['email' => __('auth.failed')]);
    }
}
