<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\User;

class ApplicationPolicy
{
    public function viewAny(?User $user): bool
    {
        return (bool) $user;
    }

    public function view(?User $user, Application $application): bool
    {
        if (!$application->user_id) {
            return false;
        }
        return $user && ($user->id === $application->user_id || $user->is_admin);
    }
}
