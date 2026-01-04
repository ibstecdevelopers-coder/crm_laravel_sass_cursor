<?php

namespace App\Observers;

use Horsefly\Unit;
use Horsefly\Audit;
use Illuminate\Support\Facades\Auth;
class UnitObserver
{
    /**
     * Handle the Unit "created" event.
     */
    public function created(Unit $unit): void
    {
        $unit->audits()->create([
            "user_id" => Auth::id(),
            "data" => $unit->toJson(),
            "message" => "Unit '{$unit->unit_name}' has been created successfully at {$unit->created_at}",
        ]);
    }

    /**
     * Handle the Unit "updated" event.
     */
    public function updated(Unit $unit): void
    {
        // Skip update logging if it's part of creation (created_at == updated_at)
        if ($unit->created_at && $unit->created_at->eq($unit->updated_at)) {
            return;
        }

        $columns = $unit->getDirty();
        if (empty($columns)) {
            return; // No real changes
        }

        // Check the dirty columns
        $columns = $unit->getDirty();

        $unit['changes_made'] = $columns;  // You may want to use a model method to store changes

        // Create the audit log entry
        $unit->audits()->create([
            "user_id" => Auth::id(),
            "data" => $unit->toJson(),
            "message" => "Unit '{$unit->unit_name}' has been updated successfully at {$unit->updated_at}",
        ]);
    }

    /**
     * Handle the Unit "deleted" event.
     */
    public function deleted(Unit $unit): void
    {
        //
    }

    /**
     * Handle the Unit "restored" event.
     */
    public function restored(Unit $unit): void
    {
        //
    }

    /**
     * Handle the Unit "force deleted" event.
     */
    public function forceDeleted(Unit $unit): void
    {
        //
    }
}
