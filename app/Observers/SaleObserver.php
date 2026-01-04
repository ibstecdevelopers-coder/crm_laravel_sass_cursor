<?php

namespace App\Observers;

use Horsefly\Sale;
use Horsefly\Audit;
use Horsefly\JobTitle;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class SaleObserver
{
    /**
     * Handle the Sale "created" event.
     */
    public function created(Sale $sale): void
    {
        $jobTitle = JobTitle::find($sale->job_title_id);

        $sale->audits()->create([
            "user_id" => Auth::id(),
            "data" => $sale->toJson(),
            "message" => "Sale '{$jobTitle?->name}' has been created successfully at {$sale->created_at}",
        ]);
    }

    /**
     * Handle the Sale "updated" event.
     */
    public function updated(Sale $sale): void
    {
        // Skip update logging if it's part of creation (created_at == updated_at)
        if ($sale->created_at && $sale->created_at->eq($sale->updated_at)) {
            return;
        }

        $updated_at = Carbon::now();

        $columns = $sale->getDirty();
        if (empty($columns)) {
            return; // No real changes
        }
         // Check the dirty columns
        $columns = $sale->getDirty();

        $sale['changes_made'] = $columns;  // You may want to use a model method to store changes
        $jobTitle = JobTitle::find($sale->job_title_id);

        // Create the audit log entry
        $sale->audits()->create([
            "user_id" => Auth::id(),
            "data" => $sale->toJson(),
            "message" => "Sale '{$jobTitle?->name}' has been updated successfully at {$updated_at}",
        ]);
    }

    /**
     * Handle the Sale "deleted" event.
     */
    public function deleted(Sale $sale): void
    {
        //
    }

    /**
     * Handle the Sale "restored" event.
     */
    public function restored(Sale $sale): void
    {
        //
    }

    /**
     * Handle the Sale "force deleted" event.
     */
    public function forceDeleted(Sale $sale): void
    {
        //
    }
}
