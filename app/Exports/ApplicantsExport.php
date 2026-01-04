<?php

namespace App\Exports;

use Horsefly\Applicant;
use Horsefly\Sale;
use App\Traits\HasDistanceCalculation;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;


class ApplicantsExport implements FromCollection, WithHeadings
{
    use HasDistanceCalculation; // Add the trait here

    protected $type;
    protected $radius;
    protected $model_type;
    protected $model_id;

    public function __construct(
        string $type = 'all',
        ?float $radius = null,
        ?string $model_type = null,
        ?int $model_id = null
    ) {
        $this->type = $type;
        $this->radius = $radius;
        $this->model_type = $model_type;
        $this->model_id = $model_id;
    }

    public function collection()
    {
        switch ($this->type) {
            case 'emails':
                return Applicant::select(
                    'applicants.id',
                    'applicants.applicant_name',
                    'applicants.applicant_email',
                    'applicants.applicant_email_secondary',
                    'job_categories.name as job_category',
                    'applicants.job_type',
                    'job_titles.name as job_title',
                    'applicants.created_at'
                )
                    ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? $item->created_at->format('d M Y, h:i A') : 'N/A',
                            'applicant_name' => ucwords(strtolower($item->applicant_name)),
                            'applicant_email' => $item->applicant_email,
                            'applicant_email_secondary' => $item->applicant_email_secondary,
                            'job_category' => strtoupper($item->job_category),
                            'job_type' => strtoupper($item->job_type),
                            'job_title' => strtoupper($item->job_title),
                        ];
                    });

            case 'noLatLong':
                return Applicant::select(
                    'applicants.id',
                    'applicants.applicant_name',
                    'applicants.applicant_postcode',
                    'applicants.lat',
                    'applicants.lng',
                    'job_categories.name as job_category',
                    'applicants.job_type',
                    'job_titles.name as job_title',
                    'applicants.created_at'
                )
                    ->whereIn('applicants.lat', ['0', '', null])
                    ->whereIn('applicants.lng', ['0', '', null])
                    ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? $item->created_at->format('d M Y, h:i A') : 'N/A',
                            'applicant_name' => ucwords(strtolower($item->applicant_name)),
                            'applicant_postcode' => strtoupper($item->applicant_postcode),
                            'lat' => $item->lat,
                            'lng' => $item->lng,
                            'job_category' => strtoupper($item->job_category),
                            'job_type' => strtoupper($item->job_type),
                            'job_title' => strtoupper($item->job_title),
                        ];
                    });

            case 'all':
                return Applicant::select(
                    'applicants.id',
                    'applicants.applicant_name',
                    'applicants.applicant_email',
                    'applicants.applicant_email_secondary',
                    'applicants.applicant_postcode',
                    'applicants.applicant_phone',
                    'applicants.applicant_landline',
                    'job_categories.name as job_category',
                    'applicants.job_type',
                    'job_titles.name as job_title',
                    'applicants.created_at'
                )
                    ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? $item->created_at->format('d M Y, h:i A') : 'N/A',
                            'applicant_name' => ucwords(strtolower($item->applicant_name)),
                            'applicant_email' => $item->applicant_email,
                            'applicant_email_secondary' => $item->applicant_email_secondary,
                            'applicant_postcode' => strtoupper($item->applicant_postcode),
                            'applicant_phone' => $item->applicant_phone,
                            'applicant_landline' => $item->applicant_landline,
                            'job_category' => strtoupper($item->job_category),
                            'job_type' => strtoupper($item->job_type),
                            'job_title' => strtoupper($item->job_title),
                        ];
                    });

            case 'withinRadius':
                $model = $this->model_type::find($this->model_id);
                $lat = $model->lat ?? 0;
                $lon = $model->lng ?? 0;
                $radius = $this->radius ?? 15; // Default radius of 10 km if not provided

                $query = Applicant::query()
                    ->select([
                        'applicants.id',
                        'applicants.applicant_name',
                        'applicants.applicant_email',
                        'applicants.applicant_email_secondary',
                        'applicants.applicant_postcode',
                        'applicants.applicant_phone',
                        'applicants.applicant_landline',
                        'job_categories.name as job_category',
                        'applicants.job_type',
                        'job_titles.name as job_title',
                        'applicants.created_at',
                        DB::raw("(ACOS(SIN($lat * PI() / 180) * SIN(lat * PI() / 180) + 
                                COS($lat * PI() / 180) * COS(lat * PI() / 180) * 
                                COS(($lon - lng) * PI() / 180)) * 180 / PI() * 60 * 1.852) AS distance")
                    ])
                    ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
                    ->where('applicants.status', '1')
                    ->where('applicants.job_title_id', $model->job_title_id)
                    ->having('distance', '<', $radius)
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? $item->created_at->format('d M Y, h:i A') : 'N/A',
                            'applicant_name' => ucwords(strtolower($item->applicant_name)),
                            'applicant_email' => $item->applicant_email,
                            'applicant_email_secondary' => $item->applicant_email_secondary,
                            'applicant_postcode' => strtoupper($item->applicant_postcode),
                            'applicant_phone' => $item->applicant_phone,
                            'applicant_landline' => $item->applicant_landline,
                            'job_category' => strtoupper($item->job_category),
                            'job_type' => strtoupper($item->job_type),
                            'job_title' => strtoupper($item->job_title)
                        ];
                    });

                return $query;
            case 'allRejected':
                $radius = 15; // Default radius of 10 km if not provided

                // Get all active sales locations
                $salesLocations = Sale::select('id', 'job_title_id', 'lat', 'lng', 'sale_postcode')
                    ->where('status', 1)
                    ->where('is_on_hold', 0)
                    ->whereNotNull('lat')
                    ->whereNotNull('lng')
                    ->get();

                // Build the main query
                $latestNotes = DB::table('crm_notes as cn1')
                    ->select('cn1.*')
                    ->join(DB::raw('(SELECT MAX(id) as id FROM crm_notes GROUP BY applicant_id, sale_id) as cn2'), 'cn1.id', '=', 'cn2.id');

                $latestHistory = DB::table('history as h1')
                    ->select('h1.*')
                    ->join(DB::raw('(SELECT MAX(id) as id FROM history GROUP BY applicant_id, sale_id) as h2'), 'h1.id', '=', 'h2.id');

                $query = Applicant::query()
                    ->select([
                        'applicants.id',
                        'crm_notes.created_at as crm_notes_created',
                        'applicants.applicant_name',
                        'applicants.applicant_email',
                        'applicants.applicant_email_secondary',
                        'applicants.applicant_postcode',
                        'applicants.applicant_phone',
                        'applicants.applicant_landline',
                        'job_categories.name as job_category',
                        'applicants.job_type as job_type',
                        'job_titles.name as job_title',
                        'job_sources.name as job_source',
                        'history.sub_stage as sub_stage',
                        'applicants.applicant_experience',
                        'crm_notes.details',
                        DB::raw(
                            '
                            CASE 
                                WHEN history.sub_stage = "crm_reject" THEN "Rejected CV" 
                                WHEN history.sub_stage = "crm_request_reject" THEN "Rejected By Request"
                                WHEN history.sub_stage = "crm_interview_not_attended" THEN "Not Attended"
                                WHEN history.sub_stage IN ("crm_start_date_hold", "crm_start_date_hold_save") THEN "Start Date Hold"
                                ELSE "Unknown Status"
                            END AS sub_stage'
                        )
                    ])
                    ->joinSub($latestNotes, 'crm_notes', function ($join) {
                        $join->on('applicants.id', '=', 'crm_notes.applicant_id');
                    })
                    ->joinSub($latestHistory, 'history', function ($join) {
                        $join->on('applicants.id', '=', 'history.applicant_id')
                            ->on('crm_notes.sale_id', '=', 'history.sale_id');
                    })
                    ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_sources', 'applicants.job_source_id', '=', 'job_sources.id')
                    ->whereIn('history.sub_stage', [
                        'crm_interview_not_attended',
                        'crm_request_reject',
                        'crm_reject',
                        'crm_start_date_hold',
                        'crm_start_date_hold_save'
                    ])
                    ->whereIn('crm_notes.moved_tab_to', [
                        'interview_not_attended',
                        'request_reject',
                        'cv_sent_reject',
                        'start_date_hold',
                        'start_date_hold_save'
                    ])
                    ->where([
                        'applicants.status' => 1,
                        'history.status' => 1,
                        'applicants.is_in_nurse_home' => false,
                        'applicants.is_blocked' => false,
                        'applicants.is_callback_enable' => false,
                        'applicants.is_no_job' => false
                    ])
                    ->with(['jobTitle', 'jobCategory', 'jobSource'])
                    ->get();

                if ($salesLocations->isNotEmpty()) {
                    $query->where(function ($query) use ($salesLocations, $radius) {
                        foreach ($salesLocations as $sale) {
                            // Distance-based matching
                            $query->orWhereRaw(
                                "
                                    (6371 * ACOS(
                                        COS(RADIANS(?)) * COS(RADIANS(applicants.lat)) * 
                                        COS(RADIANS(applicants.lng) - RADIANS(?)) + 
                                        SIN(RADIANS(?)) * SIN(RADIANS(applicants.lat))
                                    )) <= ?",
                                [$sale->lat, $sale->lng, $sale->lat, $radius]
                            );
                            // Optional: Add postcode matching
                            if ($sale->sale_postcode) {
                                $query->orWhere('applicants.applicant_postcode', $sale->sale_postcode);
                            }
                        }
                    });
                }
                $query->map(function ($item) {
                    return [
                        'date' => $item->crm_notes_created ? Carbon::parse($item->crm_notes_created)->format('d M Y, h:i A') : 'N/A',
                        'applicant_name' => ucwords(strtolower($item->applicant_name)),
                        'applicant_email' => $item->applicant_email,
                        'applicant_email_secondary' => $item->applicant_email_secondary,
                        'applicant_postcode' => strtoupper($item->applicant_postcode),
                        'applicant_phone' => $item->applicant_phone,
                        'applicant_landline' => $item->applicant_landline,
                        'job_category' => ucwords($item->job_category),
                        'job_type' => ucwords($item->job_type),
                        'job_title' => strtoupper($item->job_title),
                        'job_source' => ucwords($item->job_source),
                        'rejection_type' => ucwords($item->sub_stage),
                        'experience' => $item->applicant_experience,
                        'note' => $item->details,
                    ];
                });

                return $query;

            case 'allBlocked':
                // Build the main query
                $query = Applicant::query()
                    ->select([
                        'applicants.id',
                        'applicants.updated_at',
                        'applicants.applicant_name',
                        'applicants.applicant_email',
                        'applicants.applicant_email_secondary',
                        'applicants.applicant_postcode',
                        'applicants.applicant_phone',
                        'applicants.applicant_landline',
                        'job_categories.name as job_category',
                        'applicants.job_type as job_type',
                        'job_titles.name as job_title',
                        'job_sources.name as job_source',
                        'applicants.applicant_experience',
                    ])
                    ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('job_sources', 'applicants.job_source_id', '=', 'job_sources.id')
                    ->leftJoin('applicants_pivot_sales', 'applicants.id', '=', 'applicants_pivot_sales.applicant_id')
                    ->with(['cv_notes' => function($query) {
                        $query->select('status', 'applicant_id', 'sale_id', 'user_id')
                            ->with(['user:id,name'])->latest();
                    }])
                    ->whereNull('applicants_pivot_sales.applicant_id')
                    ->where([
                        'applicants.status' => 1,
                        'applicants.is_blocked' => true
                    ])
                    ->get()
                    ->map(function ($item) {
                        return [
                            'date' => $item->updated_at ? Carbon::parse($item->updated_at)->format('d M Y, h:i A') : 'N/A',
                            'applicant_name' => ucwords(strtolower($item->applicant_name)),
                            'applicant_email' => $item->applicant_email,
                            'applicant_email_secondary' => $item->applicant_email_secondary,
                            'applicant_postcode' => strtoupper($item->applicant_postcode),
                            'applicant_phone' => $item->applicant_phone,
                            'applicant_landline' => $item->applicant_landline,
                            'job_category' => strtoupper($item->job_category),
                            'job_type' => strtoupper($item->job_type),
                            'job_title' => strtoupper($item->job_title),
                            'job_source' => strtoupper($item->job_source),
                            'status' => 'Blocked',
                            'experience' => $item->applicant_experience
                        ];
                    });

                return $query;
            case 'allPaid':
                // Build the main query
                $query = Applicant::query()
                    ->select([
                        'applicants.id',
                        'crm_notes.created_at as crm_notes_created',
                        'applicants.applicant_name',
                        'applicants.applicant_email',
                        'applicants.applicant_email_secondary',
                        'applicants.applicant_postcode',
                        'applicants.applicant_phone',
                        'applicants.applicant_landline',
                        'job_categories.name as job_category',
                        'applicants.job_type as job_type',
                        'job_titles.name as job_title',
                        'job_sources.name as job_source',
                        'crm_notes.moved_tab_to',
                        'crm_notes.details',
                        'applicants.applicant_experience'
                    ])
                    ->where('applicants.is_no_job', false)
                    ->where('applicants.status', 1)
                    ->join('crm_notes', 'applicants.id', '=', 'crm_notes.applicant_id')
                    ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('job_sources', 'applicants.job_source_id', '=', 'job_sources.id')
                    ->with(['cv_notes' => function($query) {
                    $query->select('status', 'applicant_id', 'sale_id', 'user_id')
                        ->with(['user:id,name'])->latest();
                    }])
                    ->whereIn('applicants.paid_status', ['open', 'pending'])
                    ->whereIn('crm_notes.moved_tab_to', ['paid', 'dispute', 'start_date_hold', 'declined', 'start_date'])
                    ->whereIn('crm_notes.id', function ($query) {
                        $query->select(DB::raw('MAX(id) FROM crm_notes'))
                            ->whereIn('moved_tab_to', ['paid', 'dispute', 'start_date_hold', 'declined', 'start_date'])
                            ->where('applicants.id', '=', DB::raw('applicant_id'));
                    })
                    ->get()
                    ->map(function ($item) {
                        return [
                            'date' => $item->crm_notes_created ? Carbon::parse($item->crm_notes_created)->format('d M Y, h:i A') : 'N/A',
                            'applicant_name' => ucwords(strtolower($item->applicant_name)),
                            'applicant_email' => $item->applicant_email,
                            'applicant_email_secondary' => $item->applicant_email_secondary,
                            'applicant_postcode' => strtoupper($item->applicant_postcode),
                            'applicant_phone' => $item->applicant_phone,
                            'applicant_landline' => $item->applicant_landline,
                            'job_category' => strtoupper($item->job_category),
                            'job_type' => strtoupper($item->job_type),
                            'job_title' => strtoupper($item->job_title),
                            'job_source' => strtoupper($item->job_source),
                            'status' => strtoupper($item->moved_tab_to),
                            'experience' => $item->applicant_experience,
                            'notes' => $item->details
                        ];
                    });

                return $query;

            case 'allNoJob':
                // Subquery for the latest module_notes per applicant
                $latestNotesSub = DB::table('module_notes as mn')
                    ->select([
                        'mn.id',
                        'mn.module_noteable_id',
                        'mn.user_id',
                        'mn.details',
                        'mn.created_at', //Alias created_at
                    ])
                    ->join(
                        DB::raw('(
                            SELECT MAX(id) AS id
                            FROM module_notes
                            WHERE module_noteable_type = "Horsefly\\\\Applicant"
                            GROUP BY module_noteable_id
                        ) latest'),
                        'latest.id',
                        '=',
                        'mn.id'
                    )
                    ->where('mn.module_noteable_type', 'Horsefly\\Applicant');

                // Main query
                $query = Applicant::query()
                    ->select([
                        'applicants.id',
                        'applicants.applicant_name',
                        'applicants.applicant_email',
                        'applicants.applicant_email_secondary',
                        'applicants.applicant_postcode',
                        'applicants.applicant_phone',
                        'applicants.applicant_landline',
                        'applicants.job_type',
                        'applicants.applicant_experience',
                        'job_titles.name as job_title_name',
                        'job_categories.name as job_category_name',
                        'job_sources.name as job_source_name',
                        'users.name as user_name',
                        'module_notes.details as module_notes_details',
                        'module_notes.created_at as note_created_at', // ✅ use the alias from subquery
                    ])
                    ->where('applicants.is_no_job', true)
                    ->where('applicants.status', 1)
                    ->joinSub($latestNotesSub, 'module_notes', function ($join) {
                        $join->on('applicants.id', '=', 'module_notes.module_noteable_id');
                    })
                    ->leftJoin('users', 'module_notes.user_id', '=', 'users.id')
                    ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_sources', 'applicants.job_source_id', '=', 'job_sources.id')
                    ->distinct()
                    ->get()
                    ->map(function ($item) {
                        return [
                            'date' => $item->note_created_at
                                ? Carbon::parse($item->note_created_at)->format('d M Y, h:i A')
                                : 'N/A',
                            'user' => $item->user_name ?? '-',
                            'applicant_name' => ucwords(strtolower($item->applicant_name)),
                            'applicant_email' => $item->applicant_email ?: '-',
                            'applicant_email_secondary' => $item->applicant_email_secondary ?: '-',
                            'applicant_postcode' => strtoupper($item->applicant_postcode ?? '-'),
                            'applicant_phone' => $item->applicant_phone ?: '-',
                            'applicant_landline' => $item->applicant_landline ?: '-',
                            'job_category' => strtoupper($item->job_category_name ?? '-'),
                            'job_type' => strtoupper($item->job_type ?? '-'),
                            'job_title' => strtoupper($item->job_title_name ?? '-'),
                            'job_source' => strtoupper($item->job_source_name ?? '-'),
                            'experience' => $item->applicant_experience ?: '-',
                            'notes' => $item->module_notes_details ?: '-',
                        ];
                    });

                return $query;

            default:
                return collect(); // Return empty collection instead of null
        }
    }

    public function headings(): array
    {
        switch ($this->type) {
            case 'emails':
                return ['Created At', 'Applicant Name', 'Email (Primary)', 'Email (Secondary)', 'Job Category', 'Job Type', 'Job Title'];
            case 'noLatLong':
                return ['Created At', 'Applicant Name', 'Postcode', 'Latitude', 'Longitude', 'Job Category', 'Job Type', 'Job Title'];
            case 'all':
                return ['Created At', 'Applicant Name', 'Email (Primary)', 'Email (Secondary)', 'Postcode', 'Phone', 'Landline', 'Job Category', 'Job Type', 'Job Title'];
            case 'withinRadius':
                return ['Created At', 'Applicant Name', 'Email (Primary)', 'Email (Secondary)', 'Postcode', 'Phone', 'Landline', 'Job Category', 'Job Type', 'Job Title'];
            case 'allRejected':
                return ['Date', 'Applicant Name', 'Email (Primary)', 'Email (Secondary)', 'Postcode', 'Phone', 'Landline', 'Job Category', 'Job Type', 'Job Title', 'Job Source', 'Rejection Type', 'Experience', 'Notes'];
            case 'allBlocked':
                return ['Date', 'Applicant Name', 'Email (Primary)', 'Email (Secondary)', 'Postcode', 'Phone', 'Landline', 'Job Category', 'Job Type', 'Job Title', 'Job Source', 'Status', 'Experience'];
            case 'allPaid':
                return ['Date', 'Applicant Name', 'Email (Primary)', 'Email (Secondary)', 'Postcode', 'Phone', 'Landline', 'Job Category', 'Job Type', 'Job Title', 'Job Source', 'Status', 'Experience', 'Notes'];
            case 'allNoJob':
                return ['Date', 'Agent', 'Applicant Name', 'Email (Primary)', 'Email (Secondary)', 'Postcode', 'Phone', 'Landline', 'Job Category', 'Job Type', 'Job Title', 'Job Source', 'Experience', 'Notes'];
            default:
                return [];
        }
    }
}
