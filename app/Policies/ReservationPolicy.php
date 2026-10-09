<?php

namespace App\Policies;

use App\Models\User;

class ReservationPolicy
{
    public function create(User $user): bool
    {
        // All authenticated roles share the customer booking capability.
        return $user->exists;
    }
}
