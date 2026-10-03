<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TokenRequest;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Bearer-token authentication for mobile clients. Browsers use AuthController.
 */
class TokenController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function store(TokenRequest $request): JsonResponse
    {
        $token = $this->auth->issueToken($request->credentials(), $request->validated('device_name'));

        return response()->json([
            'data' => ['token' => $token, 'token_type' => 'Bearer'],
        ], Response::HTTP_CREATED);
    }

    public function destroy(Request $request): Response
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }
}
