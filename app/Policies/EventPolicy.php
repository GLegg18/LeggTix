<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Organiser, UserRole::Admin], true);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Event $event): bool
    {
        return $user->role === UserRole::Admin
            || ($user->role === UserRole::Organiser && $event->owner_id === $user->id);
    }

    public function update(User $user, Event $event): bool
    {
        return $this->view($user, $event);
    }

    public function cancel(User $user, Event $event): bool
    {
        return $this->view($user, $event);
    }

    public function viewReservations(User $user, Event $event): bool
    {
        return $this->view($user, $event);
    }

    public function viewWaitlist(User $user, Event $event): bool
    {
        return $this->view($user, $event);
    }
}
