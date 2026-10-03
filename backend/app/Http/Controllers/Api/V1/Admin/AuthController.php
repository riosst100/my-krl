<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Admin sign-in uses its own session guard ("admin") and never "remembers"
 * the browser: the admin session ends after SESSION_LIFETIME of inactivity.
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function login(LoginRequest $request): UserResource
    {
        $user = $this->auth->login($request, 'admin', $request->credentials(), remember: false, requireAdmin: true);

        return new UserResource($user);
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout($request, 'admin');

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
