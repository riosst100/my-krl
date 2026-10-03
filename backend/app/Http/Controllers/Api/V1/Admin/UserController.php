<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'role' => ['sometimes', 'nullable', Rule::enum(UserRole::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->search($request->input('search'))
            ->when($request->input('role'), fn ($q, $role) => $q->where('role', $role))
            ->latest('id')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return UserResource::collection($users);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    public function updateRole(UpdateUserRoleRequest $request, User $user): UserResource
    {
        $user->forceFill(['role' => $request->validated('role')])->save();

        Log::info('User role changed', [
            'user_id' => $user->id,
            'role' => $user->role->value,
            'by' => $request->user()->id,
        ]);

        return new UserResource($user);
    }
}
