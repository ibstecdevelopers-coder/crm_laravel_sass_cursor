<?php

namespace App\Observers;

use Horsefly\Office;
use Horsefly\Audit;
use Illuminate\Support\Facades\Auth;

class HeadOfficeObserver
{
    /**
     * Handle the HeadOffice "created" event.
     */
    public function created(Office $headOffice): void
    {
        // Create the audit log entry
        $headOffice->audits()->create([
            "user_id" => Auth::id(),
            "data" => $headOffice->toJson(),
            "message" => "Head Office '{$headOffice->office_name}' has been created successfully at {$headOffice->created_at}",
        ]);
    }

    /**
     * Handle the HeadOffice "updated" event.
     */
    public function updated(Office $headOffice): void
    {
        // Skip update logging if it's part of creation (created_at == updated_at)
        if ($headOffice->created_at && $headOffice->created_at->eq($headOffice->updated_at)) {
            return;
        }

        $columns = $headOffice->getDirty();
        if (empty($columns)) {
            return; // No real changes
        }

        // Check the dirty columns
        $columns = $headOffice->getDirty();

        $headOffice['changes_made'] = $columns;  // You may want to use a model method to store changes

        // Create the audit log entry
        $headOffice->audits()->create([
            "user_id" => Auth::id(),
            "data" => $headOffice->toJson(),
            "message" => "Head Office '{$headOffice->office_name}' has been updated successfully at {$headOffice->updated_at}",
        ]);
    }

    /**
     * Handle the HeadOffice "deleted" event.
     */
    public function deleted(Office $headOffice): void
    {
        //
    }

    /**
     * Handle the HeadOffice "restored" event.
     */
    public function restored(Office $headOffice): void
    {
        //
    }

    /**
     * Handle the HeadOffice "force deleted" event.
     */
    public function forceDeleted(Office $headOffice): void
    {
        //
    }
}
