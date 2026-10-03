<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    public function updateRole(User $actor, User $target): Response
    {
        if (! $actor->isAdmin()) {
            return Response::deny('Only admins can change roles.');
        }

        // Prevents admins from locking themselves out (and guarantees one admin remains).
        if ($actor->is($target)) {
            return Response::deny('You cannot change your own role.');
        }

        return Response::allow();
    }
}
