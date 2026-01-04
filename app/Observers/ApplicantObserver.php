<?php

namespace App\Observers;

use Horsefly\Applicant;
use Horsefly\Audit;
use Illuminate\Support\Facades\Auth;

class ApplicantObserver
{
    /**
     * Handle the Applicant "created" event.
     */
    public function created(Applicant $applicant): void
    {
        // Create the audit log entry
        $applicant->audits()->create([
            "user_id" => Auth::id(),
            "data" => $applicant->toJson(),
            "message" => "Applicant '{$applicant->applicant_name}' has been created successfully at {$applicant->created_at}",
        ]);
    }

    /**
     * Handle the Applicant "updated" event.
     */
    public function updated(Applicant $applicant): void
    {
        // Skip update logging if it's part of creation (created_at == updated_at)
        if ($applicant->created_at && $applicant->created_at->eq($applicant->updated_at)) {
            return;
        }

        $columns = $applicant->getDirty();
        if (empty($columns)) {
            return; // No real changes
        }

        $applicant['changes_made'] = $columns;  // You may want to use a model method to store changes

        // Create the audit log entry
        $applicant->audits()->create([
            "user_id" => Auth::id(),
            "data" => $applicant->toJson(),
            "message" => "Applicant '{$applicant->applicant_name}' has been updated successfully at {$applicant->updated_at}",
        ]);
    }


    /**
     * Handle the Applicant "deleted" event.
     */
    public function deleted(Applicant $applicant): void
    {
        //
    }

    /**
     * Handle the Applicant "restored" event.
     */
    public function restored(Applicant $applicant): void
    {
        //
    }

    /**
     * Handle the Applicant "force deleted" event.
     */
    public function forceDeleted(Applicant $applicant): void
    {
        //
    }

    public function csvAudit($audit_data)
    {
        $audit = new Audit();
        $audit->user_id = Auth::id();
        $audit->data = json_decode(json_encode($audit_data, JSON_FORCE_OBJECT));
        $audit->message = "Applicants CSV file imported successfully at {$audit_data['created_at']}";
        $audit->auditable_id = $audit_data['id'];
        $audit->auditable_type = "Horsefly\Applicant";
        $audit->save();
    }
}
