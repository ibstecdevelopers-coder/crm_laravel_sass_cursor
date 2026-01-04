<?php

namespace App\Exports;

use Horsefly\Sale;
use Horsefly\Applicant;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SalesExport implements FromCollection, WithHeadings
{
    protected $type;

    public function __construct(string $type = 'all')
    {
        $this->type = $type;
    }

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        switch ($this->type) {
            case 'emails':
                return Sale::select(
                        'sales.id', 
                        'offices.office_name', 
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_email',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at'
                    )
                    ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
                    ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('contacts', 'units.id', '=', 'contacts.contactable_id')
                    ->where('contacts.contactable_type', 'Horsefly\\Unit')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? $item->created_at->format('d M Y, h:i A') : 'N/A',
                            'office_name' => ucwords(strtolower($item->office_name)),
                            'unit_name' => ucwords(strtolower($item->unit_name)),
                            'sale_postcode' => strtoupper($item->sale_postcode),
                            'contact_email' => $item->contact_email,
                            'job_category' => strtoupper($item->job_category),
                            'job_type' => strtoupper($item->job_type),
                            'job_title' => strtoupper($item->job_title),
                        ];
                    });

            case 'rejected_cv':
                return Applicant::query()
                    ->select([
                        'sales.id as sale_id',
                        'offices.office_name',
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_email',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at',
                    ])
                    ->where('applicants.status', 1)
                    ->whereNull('applicants.deleted_at')

                    // ✅ Latest crm_notes per applicant/sale for rejected CVs
                    ->joinSub(
                        DB::table('crm_notes as cn')
                            ->select('cn.applicant_id', 'cn.sale_id', 'cn.created_at')
                            ->whereIn('cn.moved_tab_to', ['cv_sent_reject', 'cv_sent_reject_no_job'])
                            ->whereIn('cn.id', function ($sub) {
                                $sub->select(DB::raw('MAX(id)'))
                                    ->from('crm_notes')
                                    ->groupBy('applicant_id', 'sale_id');
                            }),
                        'latest_crm',
                        function ($join) {
                            $join->on('applicants.id', '=', 'latest_crm.applicant_id');
                        }
                    )

                    // ✅ Related data joins
                    ->join('sales', 'latest_crm.sale_id', '=', 'sales.id')
                    ->join('offices', 'sales.office_id', '=', 'offices.id')
                    ->join('units', 'sales.unit_id', '=', 'units.id')

                    // ✅ History join for rejected entries
                    ->join('history', function ($join) {
                        $join->on('latest_crm.applicant_id', '=', 'history.applicant_id')
                            ->on('latest_crm.sale_id', '=', 'history.sale_id')
                            ->whereIn('history.sub_stage', ['crm_reject', 'crm_no_job_reject'])
                            ->where('history.status', 1);
                    })

                    // ✅ Latest CV notes (optional but kept)
                    ->leftJoinSub(
                        DB::table('cv_notes as cv')
                            ->select('cv.applicant_id', 'cv.sale_id', 'cv.status')
                            ->whereIn('cv.id', function ($sub) {
                                $sub->select(DB::raw('MAX(id)'))
                                    ->from('cv_notes')
                                    ->groupBy('applicant_id', 'sale_id');
                            }),
                        'latest_cv',
                        function ($join) {
                            $join->on('latest_crm.applicant_id', '=', 'latest_cv.applicant_id')
                                ->on('latest_crm.sale_id', '=', 'latest_cv.sale_id');
                        }
                    )

                    // ✅ Supporting joins
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('contacts', function ($join) {
                        $join->on('units.id', '=', 'contacts.contactable_id')
                            ->where('contacts.contactable_type', 'Horsefly\\Unit');
                    })

                    // ✅ Prevent duplicates
                    ->groupBy([
                        'sales.id',
                        'offices.office_name',
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_email',
                        'job_categories.name',
                        'sales.job_type',
                        'job_titles.name',
                        'sales.created_at',
                    ])
                    // ✅ Map exactly in your heading order
                    ->get()
                    ->map(function ($item) {
                        return [
                            'Created At'         => $item->created_at ? \Carbon\Carbon::parse($item->created_at)->format('d M Y, h:i A') : 'N/A',
                            'Head Office Name'   => ucwords(strtolower($item->office_name)),
                            'Unit Name'          => ucwords(strtolower($item->unit_name)),
                            'Sale Postcode'      => strtoupper($item->sale_postcode),
                            'Contact Email'      => $item->contact_email ?? 'N/A',
                            'Job Category'       => ucwords($item->job_category ?? ''),
                            'Job Type'           => ucwords(str_replace('-', ' ', $item->job_type ?? '')),
                            'Job Title'          => strtoupper($item->job_title ?? ''),
                        ];
                    });

            case 'declined':
                return Applicant::query()
                ->select([
                    'sales.id as sale_id',
                    'offices.office_name',
                    'units.unit_name',
                    'sales.sale_postcode',
                    'contacts.contact_email',
                    'job_categories.name as job_category',
                    'sales.job_type',
                    'job_titles.name as job_title',
                    'sales.created_at',
                ])
                ->where('applicants.status', 1)
                ->whereNull('applicants.deleted_at')

                // joinSub to get latest crm_notes with "declined"
                ->joinSub(
                    DB::table('crm_notes')
                        ->select('applicant_id', 'sale_id', 'details', 'created_at')
                        ->where('moved_tab_to', 'declined')
                        ->whereIn('id', function ($subQuery) {
                            $subQuery->select(DB::raw('MAX(id)'))
                                ->from('crm_notes')
                                ->groupBy('applicant_id', 'sale_id');
                        }),
                    'crm_notes',
                    function ($join) {
                        $join->on('applicants.id', '=', 'crm_notes.applicant_id');
                    }
                )

                // joins same as parent
                ->join('sales', 'crm_notes.sale_id', '=', 'sales.id')
                ->join('offices', 'sales.office_id', '=', 'offices.id')
                ->join('units', 'sales.unit_id', '=', 'units.id')

                // join history for crm_declined
                ->join('history', function ($join) {
                    $join->on('crm_notes.applicant_id', '=', 'history.applicant_id')
                        ->on('crm_notes.sale_id', '=', 'history.sale_id')
                        ->where('history.sub_stage', 'crm_declined')
                        ->where('history.status', 1);
                })

                // left join cv_notes subquery to get latest note per applicant/sale
                ->leftJoinSub(
                    DB::table('cv_notes')
                        ->select('applicant_id', 'sale_id', 'user_id', 'status')
                        ->whereIn('id', function ($subQuery) {
                            $subQuery->select(DB::raw('MAX(id)'))
                                ->from('cv_notes')
                                ->groupBy('applicant_id', 'sale_id');
                        }),
                    'cv_notes',
                    function ($join) {
                        $join->on('crm_notes.applicant_id', '=', 'cv_notes.applicant_id')
                            ->on('crm_notes.sale_id', '=', 'cv_notes.sale_id');
                    }
                )

                // same supporting joins as parent
                ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                ->leftJoin('contacts', function ($join) {
                    $join->on('units.id', '=', 'contacts.contactable_id')
                        ->where('contacts.contactable_type', 'Horsefly\\Unit');
                })

                ->distinct('applicants.id')
                ->get()
                ->map(function ($item) {
                    return [
                        'created_at'   => $item->created_at ? $item->created_at->format('d M Y, h:i A') : 'N/A',
                        'office_name'  => ucwords(strtolower($item->office_name)),
                        'unit_name'    => ucwords(strtolower($item->unit_name)),
                        'sale_postcode'=> strtoupper($item->sale_postcode),
                        'contact_email'=> $item->contact_email,
                        'job_category' => ucwords($item->job_category),
                        'job_type'     => ucwords(str_replace('-', ' ', $item->job_type)),
                        'job_title'    => strtoupper($item->job_title),
                    ];
                });


            case 'not_attended':
                return Applicant::query()
                    ->with([
                        'jobTitle',
                        'jobCategory',
                        'jobSource'
                    ])
                    ->select([
                        'sales.id', 
                        'offices.office_name', 
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_email',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at'
                    ])
                    ->where('applicants.status', 1)
                    ->distinct('applicants.id')
                    ->join('crm_notes', function ($join) {
                        $join->on('applicants.id', '=', 'crm_notes.applicant_id')
                            ->whereIn("crm_notes.moved_tab_to", ["interview_not_attended"])
                            ->where('crm_notes.status', 1);
                    })
                    ->join('sales', 'crm_notes.sale_id', '=', 'sales.id')
                    ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
                    ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('contacts', 'units.id', '=', 'contacts.contactable_id')
                    ->join('history', function ($join) {
                        $join->on('crm_notes.applicant_id', '=', 'history.applicant_id');
                        $join->on('crm_notes.sale_id', '=', 'history.sale_id')
                            ->whereIn("history.sub_stage", ["crm_interview_not_attended"])
                            ->where("history.status", 1);
                    })
                    ->join('cv_notes', function ($join) {
                        $join->on('applicants.id', '=', 'cv_notes.applicant_id')
                            ->whereColumn('cv_notes.sale_id', 'sales.id') // Fixed: Compare columns, not strings
                            ->latest();
                    })
                    ->where('contacts.contactable_type', 'Horsefly\\Unit')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? $item->created_at->format('d M Y, h:i A') : 'N/A',
                            'office_name' => ucwords(strtolower($item->office_name)),
                            'unit_name' => ucwords(strtolower($item->unit_name)),
                            'sale_postcode' => strtoupper($item->sale_postcode),
                            'contact_email' => $item->contact_email,
                            'job_category' => ucwords($item->job_category),
                            'job_type' => ucwords(str_replace('-',' ',$item->job_type)),
                            'job_title' => strtoupper($item->job_title),
                        ];
                    });
            
            case 'start_date_hold':
                return Applicant::query()
                    ->with([
                        'jobTitle',
                        'jobCategory',
                        'jobSource'
                    ])
                    ->select([
                        'sales.id', 
                        'offices.office_name', 
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_email',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at'
                    ])
                    ->where('applicants.status', 1)
                    ->distinct('applicants.id')
                    ->join('crm_notes', function ($join) {
                        $join->on('applicants.id', '=', 'crm_notes.applicant_id')
                            ->whereIn("crm_notes.moved_tab_to", ["start_date_hold", "start_date_hold_save"])
                            ->where('crm_notes.status', 1);
                    })
                    ->join('sales', 'crm_notes.sale_id', '=', 'sales.id')
                    ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
                    ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('contacts', 'units.id', '=', 'contacts.contactable_id')
                    ->join('history', function ($join) {
                        $join->on('crm_notes.applicant_id', '=', 'history.applicant_id');
                        $join->on('crm_notes.sale_id', '=', 'history.sale_id')
                            ->whereIn("history.sub_stage", ["crm_start_date_hold", "crm_start_date_hold_save"])
                            ->where("history.status", 1);
                    })
                    ->join('cv_notes', function ($join) {
                        $join->on('applicants.id', '=', 'cv_notes.applicant_id')
                            ->whereColumn('cv_notes.sale_id', 'sales.id') // Fixed: Compare columns, not strings
                            ->latest();
                    })
                    ->where('contacts.contactable_type', 'Horsefly\\Unit')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? $item->created_at->format('d M Y, h:i A') : 'N/A',
                            'office_name' => ucwords(strtolower($item->office_name)),
                            'unit_name' => ucwords(strtolower($item->unit_name)),
                            'sale_postcode' => strtoupper($item->sale_postcode),
                            'contact_email' => $item->contact_email,
                            'job_category' => ucwords($item->job_category),
                            'job_type' => ucwords(str_replace('-',' ',$item->job_type)),
                            'job_title' => strtoupper($item->job_title),
                        ];
                    });
            case 'dispute':
                return Applicant::query()
                    ->with([
                        'jobTitle',
                        'jobCategory',
                        'jobSource'
                    ])
                    ->select([
                        'sales.id', 
                        'offices.office_name', 
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_email',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at'
                    ])
                    ->where('applicants.status', 1)
                    ->distinct('applicants.id')
                    ->join('crm_notes', function ($join) {
                        $join->on('applicants.id', '=', 'crm_notes.applicant_id')
                            ->whereIn("crm_notes.moved_tab_to", ["dispute"])
                            ->where('crm_notes.status', 1);
                    })
                    ->join('sales', 'crm_notes.sale_id', '=', 'sales.id')
                    ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
                    ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('contacts', 'units.id', '=', 'contacts.contactable_id')
                    ->join('history', function ($join) {
                        $join->on('crm_notes.applicant_id', '=', 'history.applicant_id');
                        $join->on('crm_notes.sale_id', '=', 'history.sale_id')
                            ->whereIn("history.sub_stage", ["crm_dispute"])
                            ->where("history.status", 1);
                    })
                    ->join('cv_notes', function ($join) {
                        $join->on('applicants.id', '=', 'cv_notes.applicant_id')
                            ->whereColumn('cv_notes.sale_id', 'sales.id') // Fixed: Compare columns, not strings
                            ->latest();
                    })
                    ->where('contacts.contactable_type', 'Horsefly\\Unit')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? $item->created_at->format('d M Y, h:i A') : 'N/A',
                            'office_name' => ucwords(strtolower($item->office_name)),
                            'unit_name' => ucwords(strtolower($item->unit_name)),
                            'sale_postcode' => strtoupper($item->sale_postcode),
                            'contact_email' => $item->contact_email,
                            'job_category' => ucwords($item->job_category),
                            'job_type' => ucwords(str_replace('-',' ',$item->job_type)),
                            'job_title' => strtoupper($item->job_title),
                        ];
                    });
            
            case 'paid':
                // Subquery: latest cv_note per applicant + sale
                $latestCvNotes = DB::table('cv_notes as cv1')
                    ->select('cv1.*')
                    ->whereIn('cv1.id', function ($q) {
                        $q->select(DB::raw('MAX(id)'))
                            ->from('cv_notes')
                            ->groupBy('applicant_id', 'sale_id');
                    });

                return Applicant::query()
                    ->with(['jobTitle', 'jobCategory', 'jobSource'])
                    ->select([
                        'applicants.id',
                        'sales.id as sale_id',
                        'offices.office_name',
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_email',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at'
                    ])
                    ->join('crm_notes', function ($join) {
                        $join->on('applicants.id', '=', 'crm_notes.applicant_id')
                            ->whereIn('crm_notes.moved_tab_to', ['paid'])
                            ->where('crm_notes.status', 1);
                    })
                    ->join('sales', 'crm_notes.sale_id', '=', 'sales.id')
                    ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
                    ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('contacts', 'units.id', '=', 'contacts.contactable_id')
                    ->join('history', function ($join) {
                        $join->on('crm_notes.applicant_id', '=', 'history.applicant_id')
                            ->on('crm_notes.sale_id', '=', 'history.sale_id')
                            ->whereIn('history.sub_stage', ['crm_paid'])
                            ->where('history.status', 1);
                    })
                    ->leftJoinSub($latestCvNotes, 'latest_cv', function ($join) {
                        $join->on('applicants.id', '=', 'latest_cv.applicant_id')
                            ->on('sales.id', '=', 'latest_cv.sale_id');
                    })
                    ->where('contacts.contactable_type', 'Horsefly\\Unit')
                    ->where('applicants.status', 1)
                    ->distinct()
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at'    => $item->created_at ? $item->created_at->format('d M Y, h:i A') : 'N/A',
                            'office_name'   => ucwords(strtolower($item->office_name)),
                            'unit_name'     => ucwords(strtolower($item->unit_name)),
                            'sale_postcode' => strtoupper($item->sale_postcode),
                            'contact_email' => $item->contact_email,
                            'job_category'  => ucwords($item->job_category),
                            'job_type'      => ucwords(str_replace('-',' ',$item->job_type)),
                            'job_title'     => strtoupper($item->job_title),
                        ];
                    });

            case 'emailsOpen':
                $latestAuditSub = DB::table('audits')
                    ->select(DB::raw('MAX(id) as id'))
                    ->where('auditable_type', 'Horsefly\Sale')
                    ->where('message', 'like', '%sale-opened%')
                    ->whereIn('auditable_id', function($query) {
                        $query->select('id')
                            ->from('sales')
                            ->where('status', 1); // Ensure we only consider closed sales
                    })
                    ->groupBy('auditable_id');

                return Sale::select(
                        'sales.id', 
                        'offices.office_name', 
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_email',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at',
                        'audits.created_at as open_date'
                    )
                    ->where('sales.status', 1)
                    ->where('sales.is_on_hold', 0)
                    ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
                    ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('contacts', 'units.id', '=', 'contacts.contactable_id')
                    ->where('contacts.contactable_type', 'Horsefly\\Unit')
                    ->leftJoin('audits', function ($join) use ($latestAuditSub) {
                        $join->on('audits.auditable_id', '=', 'sales.id')
                            ->where('audits.auditable_type', '=', 'Horsefly\Sale')
                            ->where('audits.message', 'like', '%sale-opened%')
                            ->whereIn('audits.id', $latestAuditSub);
                    })
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? $item->created_at->format('d M Y, h:i A') : 'N/A',
                            'office_name' => ucwords(strtolower($item->office_name)),
                            'unit_name' => ucwords(strtolower($item->unit_name)),
                            'sale_postcode' => strtoupper($item->sale_postcode),
                            'contact_email' => $item->contact_email,
                            'job_category' => strtoupper($item->job_category),
                            'job_type' => strtoupper($item->job_type),
                            'job_title' => strtoupper($item->job_title),
                            'open_date' => $item->open_date ? Carbon::parse($item->open_date)->format('d M Y, h:i A') : 'N/A',
                        ];
                    });

            case 'emailsClose':
                $latestAuditSub = DB::table('audits')
                    ->select(DB::raw('MAX(id) as id'))
                    ->where('auditable_type', 'Horsefly\Sale')
                    ->where('message', 'like', '%sale-closed%')
                    ->whereIn('auditable_id', function($query) {
                        $query->select('id')
                            ->from('sales')
                            ->where('status', 0); // Ensure we only consider closed sales
                    })
                    ->groupBy('auditable_id');

                return Sale::select(
                        'sales.id', 
                        'offices.office_name', 
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_email',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at',
                        'audits.created_at as closed_date'
                    )
                    ->where('sales.status', 0)
                    ->where('sales.is_on_hold', 0)
                    ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
                    ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('contacts', 'units.id', '=', 'contacts.contactable_id')
                    ->where('contacts.contactable_type', 'Horsefly\\Unit')
                    ->leftJoin('audits', function ($join) use ($latestAuditSub) {
                        $join->on('audits.auditable_id', '=', 'sales.id')
                            ->where('audits.auditable_type', '=', 'Horsefly\Sale')
                            ->where('audits.message', 'like', '%sale-closed%')
                            ->whereIn('audits.id', $latestAuditSub);
                    })
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? $item->created_at->format('d M Y, h:i A') : 'N/A',
                            'office_name' => ucwords(strtolower($item->office_name)),
                            'unit_name' => ucwords(strtolower($item->unit_name)),
                            'sale_postcode' => strtoupper($item->sale_postcode),
                            'contact_email' => $item->contact_email,
                            'job_category' => strtoupper($item->job_category),
                            'job_type' => strtoupper($item->job_type),
                            'job_title' => strtoupper($item->job_title),
                            'closed_date' => $item->closed_date ? Carbon::parse($item->closed_date)->format('d M Y, h:i A') : 'N/A',
                        ];
                    });
                
            case 'noLatLong':
                return Sale::select(
                        'sales.id',
                        'offices.office_name',
                        'units.unit_name',
                        'sales.sale_postcode',
                        'sales.lat as sale_lat',
                        'sales.lng as sale_lng',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at'
                    )
                    ->whereIn('sales.lat', ['0', '', null])
                    ->whereIn('sales.lng', ['0', '', null])
                    ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
                    ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? $item->created_at->format('d M Y, h:i A') : 'N/A',
                            'office_name' => ucwords(strtolower($item->office_name)),
                            'unit_name' => ucwords(strtolower($item->unit_name)),
                            'sale_postcode' => strtoupper($item->sale_postcode),
                            'sale_lat' => $item->sale_lat,
                            'sale_lng' => $item->sale_lng,
                            'job_category' => strtoupper($item->job_category),
                            'job_type' => strtoupper($item->job_type),
                            'job_title' => strtoupper($item->job_title),
                        ];
                    });

                
            case 'all':
                return Sale::select(
                        'sales.id', 
                        'offices.office_name', 
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_name',
                        'contacts.contact_email',
                        'contacts.contact_phone',
                        'contacts.contact_landline',
                        'contacts.contact_note',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at'
                    )
                    ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
                    ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('contacts', 'units.id', '=', 'contacts.contactable_id')
                    ->where('contacts.contactable_type', 'Horsefly\\Unit')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? $item->created_at->format('d M Y, h:i A') : 'N/A',
                            'office_name' => ucwords(strtolower($item->office_name)),
                            'unit_name' => ucwords(strtolower($item->unit_name)),
                            'sale_postcode' => strtoupper($item->sale_postcode),
                            'contact_name' => ucwords(strtolower($item->contact_name)),
                            'contact_email' => $item->contact_email,
                            'contact_phone' => $item->contact_phone,
                            'contact_landline' => $item->contact_landline,
                            'contact_note' => $item->contact_note,
                            'job_category' => strtoupper($item->job_category),
                            'job_type' => strtoupper($item->job_type),
                            'job_title' => strtoupper($item->job_title),
                        ];
                    });

            case 'allOpen':
                $latestAuditSub = DB::table('audits')
                    ->select(DB::raw('MAX(id) as id'))
                    ->where('auditable_type', 'Horsefly\Sale')
                    ->where('message', 'like', '%sale-opened%')
                    ->whereIn('auditable_id', function($query) {
                        $query->select('id')
                            ->from('sales')
                            ->where('status', 1); // Ensure we only consider closed sales
                    })
                    ->groupBy('auditable_id');

                return Sale::select(
                        'sales.id', 
                        'offices.office_name', 
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_name',
                        'contacts.contact_email',
                        'contacts.contact_phone',
                        'contacts.contact_landline',
                        'contacts.contact_note',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at',
                        'audits.created_at as open_date'
                    )
                    ->where('sales.status', 1)
                    ->where('sales.is_on_hold', 0)
                    ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
                    ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('contacts', 'units.id', '=', 'contacts.contactable_id')
                    ->where('contacts.contactable_type', 'Horsefly\\Unit')
                    ->leftJoin('audits', function ($join) use ($latestAuditSub) {
                        $join->on('audits.auditable_id', '=', 'sales.id')
                            ->where('audits.auditable_type', '=', 'Horsefly\Sale')
                            ->where('audits.message', 'like', '%sale-opened%')
                            ->whereIn('audits.id', $latestAuditSub);
                    })
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? $item->created_at->format('d M Y, h:i A') : 'N/A',
                            'office_name' => ucwords(strtolower($item->office_name)),
                            'unit_name' => ucwords(strtolower($item->unit_name)),
                            'sale_postcode' => strtoupper($item->sale_postcode),
                            'contact_name' => ucwords(strtolower($item->contact_name)),
                            'contact_email' => $item->contact_email,
                            'contact_phone' => $item->contact_phone,
                            'contact_landline' => $item->contact_landline,
                            'contact_note' => $item->contact_note,
                            'job_category' => strtoupper($item->job_category),
                            'job_type' => strtoupper($item->job_type),
                            'job_title' => strtoupper($item->job_title),
                            'open_date' => $item->open_date ? Carbon::parse($item->open_date)->format('d M Y, h:i A') : 'N/A',
                        ];
                    });

            case 'allClose':
                $latestAuditSub = DB::table('audits')
                    ->select(DB::raw('MAX(id) as id'))
                    ->where('auditable_type', 'Horsefly\Sale')
                    ->where('message', 'like', '%sale-closed%')
                    ->whereIn('auditable_id', function($query) {
                        $query->select('id')
                            ->from('sales')
                            ->where('status', 0); // Ensure we only consider closed sales
                    })
                    ->groupBy('auditable_id');

                return Sale::select(
                        'sales.id', 
                        'offices.office_name', 
                        'units.unit_name',
                        'sales.sale_postcode',
                        'contacts.contact_name',
                        'contacts.contact_email',
                        'contacts.contact_phone',
                        'contacts.contact_landline',
                        'contacts.contact_note',
                        'job_categories.name as job_category',
                        'sales.job_type',
                        'job_titles.name as job_title',
                        'sales.created_at',
                        'audits.created_at as closed_date'
                    )
                    ->where('sales.status', 0)
                    ->where('sales.is_on_hold', 0)
                    ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
                    ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
                    ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
                    ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
                    ->leftJoin('contacts', 'units.id', '=', 'contacts.contactable_id')
                    ->where('contacts.contactable_type', 'Horsefly\\Unit')
                     ->leftJoin('audits', function ($join) use ($latestAuditSub) {
                        $join->on('audits.auditable_id', '=', 'sales.id')
                            ->where('audits.auditable_type', '=', 'Horsefly\Sale')
                            ->where('audits.message', 'like', '%sale-closed%')
                            ->whereIn('audits.id', $latestAuditSub);
                    })
                    ->get()
                    ->map(function ($item) {
                        return [
                            'created_at' => $item->created_at ? $item->created_at->format('d M Y, h:i A') : 'N/A',
                            'office_name' => ucwords(strtolower($item->office_name)),
                            'unit_name' => ucwords(strtolower($item->unit_name)),
                            'sale_postcode' => strtoupper($item->sale_postcode),
                            'contact_name' => ucwords(strtolower($item->contact_name)),
                            'contact_email' => $item->contact_email,
                            'contact_phone' => $item->contact_phone,
                            'contact_landline' => $item->contact_landline,
                            'contact_note' => $item->contact_note,
                            'job_category' => strtoupper($item->job_category),
                            'job_type' => strtoupper($item->job_type),
                            'job_title' => strtoupper($item->job_title),
                            'closed_date' => $item->closed_date ? Carbon::parse($item->closed_date)->format('d M Y, h:i A') : 'N/A',
                        ];
                    });
                
            default:
            return collect(); // Return empty collection instead of null
        }
    }

    public function headings(): array
    {
        switch ($this->type) {
            case 'emails':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Email', 'Job Category', 'Job Type', 'Job Title'];
            case 'rejected_cv':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Email', 'Job Category', 'Job Type', 'Job Title'];
            case 'declined':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Email', 'Job Category', 'Job Type', 'Job Title'];
            case 'not_attended':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Email', 'Job Category', 'Job Type', 'Job Title'];
            case 'start_date_hold':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Email', 'Job Category', 'Job Type', 'Job Title'];
            case 'dispute':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Email', 'Job Category', 'Job Type', 'Job Title'];
            case 'paid':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Email', 'Job Category', 'Job Type', 'Job Title'];
            case 'emailsOpen':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Email', 'Job Category', 'Job Type', 'Job Title', 'Open Date'];
            case 'emailsClose':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Email', 'Job Category', 'Job Type', 'Job Title', 'Close Date'];
            case 'noLatLong':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Latitude', 'Longitude', 'Job Category', 'Job Type', 'Job Title'];
            case 'all':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Name', 'Contact Email', 'Contact Phone', 'Contact Landline', 'Contact Note', 'Job Category', 'Job Type', 'Job Title'];
            case 'allOpen':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Name', 'Contact Email', 'Contact Phone', 'Contact Landline', 'Contact Note', 'Job Category', 'Job Type', 'Job Title', 'Open Date'];
            case 'allClose':
                return ['Created At', 'Head Office Name', 'Unit Name', 'Sale Postcode', 'Contact Name', 'Contact Email', 'Contact Phone', 'Contact Landline', 'Contact Note', 'Job Category', 'Job Type', 'Job Title', 'Close Date'];
            default:
                return [];
        }
    }
}
