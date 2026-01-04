<?php

namespace App\Observers;

use Horsefly\User;
use Horsefly\Audit;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class UserObserver
{
    /**
     * Handle the User "created" event.
     */
    public function created(User $user): void
    {
        $user->audits()->create([
            "user_id" => Auth::user()->id,
            "data" => $user->toJson(),
            "message" => "User '{$user->name}' has been created successfully at {$user->created_at}",
        ]);
    }

    /**
     * Handle the User "updated" event.
     */
    public function updated(User $user)
    {
        // Ensure created_at and updated_at are Carbon instances before calling eq()
        if ($user->created_at && $user->updated_at) {
            $createdAt = Carbon::parse($user->created_at);
            $updatedAt = Carbon::parse($user->updated_at);

            if ($createdAt->eq($updatedAt)) {
                return;
            }
        }

        $columns = $user->getDirty();
        if (empty($columns)) {
            return; // No real changes
        }

        $user['changes_made'] = $columns;

        $user->audits()->create([
            "user_id" => Auth::id(),
            "data" => $user->toJson(),
            "message" => "User '{$user->name}' has been updated successfully at {$user->updated_at}",
        ]);
    }

    /**
     * Handle the User "deleted" event.
     */
    public function deleted(User $user): void
    {
        //
    }

    /**
     * Handle the User "restored" event.
     */
    public function restored(User $user): void
    {
        //
    }

    /**
     * Handle the User "force deleted" event.
     */
    public function forceDeleted(User $user): void
    {
        //
    }
}
