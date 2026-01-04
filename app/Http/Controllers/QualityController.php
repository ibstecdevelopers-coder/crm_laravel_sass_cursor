<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Horsefly\Sale;
use Horsefly\Unit;
use Horsefly\Office;
use Horsefly\SaleNote;
use Horsefly\QualityNotes;
use Horsefly\History;
use Horsefly\CVNote;
use Horsefly\CrmNote;
use Horsefly\Applicant;
use Horsefly\RevertStage;
use Horsefly\SmsTemplate;
use Horsefly\JobCategory;
use Horsefly\JobTitle;
use Horsefly\ModuleNote;
use Horsefly\User;
use App\Observers\ActionObserver;
use App\Http\Controllers\Controller;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Traits\SendEmails;
use App\Traits\SendSMS;
use Exception;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class QualityController extends Controller
{
    use SendEmails, SendSMS;

    public function __construct()
    {
        //
    }
    public function resourceIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name','asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name','asc')->get();

        return view('quality.resources', compact('jobCategories', 'jobTitles'));
    }
    public function saleIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name','asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name','asc')->get();
        $offices = Office::where('status', 1)->orderBy('office_name','asc')->get();
        $users = User::where('is_active', 1)->orderBy('name','asc')->get();

        return view('quality.sales', compact('jobCategories', 'jobTitles', 'offices', 'users'));
    }
    public function getResourcesByTypeAjaxRequest(Request $request)
    {
        $typeFilter = $request->input('type_filter', ''); // Default is empty (no filter)
        $categoryFilter = $request->input('category_filter', ''); // Default is empty (no filter)
        $titleFilter = $request->input('title_filter', ''); // Default is empty (no filter)
        $statusFilter = $request->input('status_filter', ''); // Default is empty (no filter)

        $model = Applicant::query()
            ->with([
                'jobTitle', 
                'jobCategory', 
                'jobSource',
                'user'
            ])
            ->select([
                'applicants.id',
                'applicants.applicant_name',
                'applicants.applicant_email',
                'applicants.applicant_email_secondary',
                'applicants.applicant_phone',
                'applicants.applicant_postcode',
                'applicants.applicant_landline',
                'applicants.applicant_cv',
                'applicants.updated_cv',
                'applicants.job_category_id',
                'applicants.job_title_id',
                'applicants.job_type',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'job_sources.name as job_source_name',
            ])
            ->where("applicants.status", 1)
            ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
            ->leftJoin('job_sources', 'applicants.job_source_id', '=', 'job_sources.id');
        
        // Filter by status if it's not empty
        switch ($statusFilter) {                
            case 'open cvs':
                $model->join('cv_notes', function ($join) {
                        $join->on('applicants.id', '=', 'cv_notes.applicant_id')
                            ->where("cv_notes.status", 1);
                    })
                    ->join('sales', function ($join) {
                        $join->on('cv_notes.sale_id', '=', 'sales.id')
                            ->whereColumn('cv_notes.sale_id', 'sales.id');
                    })
                    ->join('offices', 'sales.office_id', '=', 'offices.id')
                    ->join('units', 'sales.unit_id', '=', 'units.id')
                    ->join('history', function ($join) {
                        $join->on('cv_notes.applicant_id', '=', 'history.applicant_id');
                        $join->on('cv_notes.sale_id', '=', 'history.sale_id')
                            ->whereIn("history.sub_stage", ["quality_cvs_hold"])
                            ->where("history.status", 1);
                    })
                    ->join('revert_stages', function ($join) {
                        $join->on('applicants.id', '=', 'revert_stages.applicant_id')
                            ->on('sales.id', '=', 'revert_stages.sale_id')
                            ->whereIn('revert_stages.id', function ($query) {
                                $query->select(DB::raw('MAX(id)'))
                                    ->from('revert_stages')
                                    ->whereColumn('applicant_id', 'applicants.id')
                                    ->whereColumn('sale_id', 'sales.id')
                                    ->whereIn('stage', ['quality_note', 'cv_hold', 'no_job_quality_cvs']);
                            });
                    })
                    ->join('users', 'users.id', '=', 'revert_stages.user_id')
                    ->addSelect(
                        'revert_stages.notes as notes_detail',
                        'revert_stages.stage as revert_stage',
                        'revert_stages.updated_at as notes_created_at',
                        'offices.office_name as office_name',

                        // sale
                        'sales.id as sale_id', 
                        'sales.job_category_id as sale_category_id', 
                        'sales.job_title_id as sale_title_id', 
                        'sales.sale_postcode', 
                        'sales.job_type as sale_job_type',
                        'sales.timing', 
                        'sales.salary', 
                        'sales.experience as sale_experience', 
                        'sales.qualification as sale_qualification', 
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',

                        // units
                        'units.unit_name', 
                        'units.unit_postcode', 
                        'units.unit_website',

                        'users.name as user_name',
                    );
                break;
                
            case 'no job cvs':
                $model->join('cv_notes', function ($join) {
                        $join->on('applicants.id', '=', 'cv_notes.applicant_id')
                            ->where("cv_notes.status", 1);
                    })
                    ->join('sales', function ($join) {
                        $join->on('cv_notes.sale_id', '=', 'sales.id')
                            ->whereColumn('cv_notes.sale_id', 'sales.id');
                    })
                    ->join('offices', 'sales.office_id', '=', 'offices.id')
                    ->join('units', 'sales.unit_id', '=', 'units.id')
                    ->join('history', function ($join) {
                        $join->on('cv_notes.applicant_id', '=', 'history.applicant_id');
                        $join->on('cv_notes.sale_id', '=', 'history.sale_id')
                            ->whereIn("history.sub_stage", ["no_job_quality_cvs"])
                            ->where("history.status", 1);
                    })
                    ->join('users', 'users.id', '=', 'cv_notes.user_id')
                    ->addSelect([
                        'cv_notes.details as notes_detail',
                        'cv_notes.created_at as notes_created_at',
                        'offices.office_name as office_name',

                        // sale
                        'sales.id as sale_id', 
                        'sales.job_category_id as sale_category_id', 
                        'sales.job_title_id as sale_title_id', 
                        'sales.sale_postcode', 
                        'sales.job_type as sale_job_type',
                        'sales.timing', 
                        'sales.salary', 
                        'sales.experience as sale_experience', 
                        'sales.qualification as sale_qualification', 
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',

                        // units
                        'units.unit_name', 
                        'units.unit_postcode', 
                        'units.unit_website',

                        'users.name as user_name'
                    ]);
                break;
                
            case 'rejected cvs':
                $model->join('quality_notes', function ($join) {
                        $join->on('applicants.id', '=', 'quality_notes.applicant_id')
                            ->where("quality_notes.moved_tab_to", "rejected");
                            // ->where("quality_notes.status", 1);
                    })
                    ->join('sales', function ($join) {
                        $join->on('quality_notes.sale_id', '=', 'sales.id')
                            ->whereColumn('quality_notes.sale_id', 'sales.id');
                    })
                    ->join('offices', 'sales.office_id', '=', 'offices.id')
                    ->join('units', 'sales.unit_id', '=', 'units.id')
                    ->join('users', 'users.id', '=', 'quality_notes.user_id')
                    ->addSelect(
                        'users.name as user_name',
                        'quality_notes.details as notes_detail',
                        'quality_notes.created_at as notes_created_at',
                        'offices.office_name as office_name',

                        // sale
                        'sales.id as sale_id', 
                        'sales.job_category_id as sale_category_id', 
                        'sales.job_title_id as sale_title_id', 
                        'sales.sale_postcode', 
                        'sales.job_type as sale_job_type',
                        'sales.timing', 
                        'sales.salary', 
                        'sales.experience as sale_experience', 
                        'sales.qualification as sale_qualification', 
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',

                        // units
                        'units.unit_name', 
                        'units.unit_postcode', 
                        'units.unit_website',
                    )
                    ->groupBy(
                        'quality_notes.created_at', 
                        'quality_notes.applicant_id', 
                        'quality_notes.sale_id', 
                        'quality_notes.id',
                        'quality_notes.details',
                        'users.name',

                        // applicant
                        'applicants.id',
                        'applicants.applicant_name',
                        'applicants.applicant_email',
                        'applicants.applicant_email_secondary',
                        'applicants.applicant_phone',
                        'applicants.applicant_postcode',
                        'applicants.applicant_landline',
                        'applicants.updated_at',
                        'applicants.applicant_cv',
                        'applicants.updated_cv',
                        'applicants.applicant_notes',
                        'applicants.job_category_id',
                        'applicants.job_title_id',
                        'applicants.job_type',

                        // sale
                        'sales.id', 
                        'sales.job_category_id', 
                        'sales.job_title_id', 
                        'sales.sale_postcode', 
                        'sales.job_type',
                        'sales.timing', 
                        'sales.salary', 
                        'sales.experience', 
                        'sales.qualification', 
                        'sales.benefits',
                        'sales.office_id',
                        'sales.unit_id',
                        'sales.position_type',
                        'sales.status',

                        // units
                        'units.unit_name', 
                        'units.unit_postcode', 
                        'units.unit_website',

                        'job_titles.name',
                        'job_categories.name',
                        'job_sources.name',
                        'offices.office_name',
                    );
                break;

            case 'cleared cvs':
                $model->join('quality_notes', function ($join) {
                        $join->on('applicants.id', '=', 'quality_notes.applicant_id')
                            ->whereIn("quality_notes.moved_tab_to" , ["cleared", "cleared_no_job"]);
                            // ->where("quality_notes.status", 1);
                    })
                    ->join('sales', function ($join) {
                        $join->on('quality_notes.sale_id', '=', 'sales.id')
                            ->whereColumn('quality_notes.sale_id', 'sales.id');
                    })
                    ->join('offices', 'sales.office_id', '=', 'offices.id')
                    ->join('units', 'sales.unit_id', '=', 'units.id')
                    ->join('users', 'users.id', '=', 'quality_notes.user_id')
                    ->addSelect(
                        'users.name as user_name',
                        'quality_notes.details as notes_detail',
                        'quality_notes.created_at as notes_created_at',
                        'offices.office_name as office_name',

                        // sale
                        'sales.id as sale_id', 
                        'sales.job_category_id as sale_category_id', 
                        'sales.job_title_id as sale_title_id', 
                        'sales.sale_postcode', 
                        'sales.job_type as sale_job_type',
                        'sales.timing', 
                        'sales.salary', 
                        'sales.experience as sale_experience', 
                        'sales.qualification as sale_qualification', 
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',

                        // units
                        'units.unit_name', 
                        'units.unit_postcode', 
                        'units.unit_website',
                    )
                    ->groupBy(
                            'quality_notes.created_at', 
                            'quality_notes.applicant_id', 
                            'quality_notes.sale_id', 
                            'quality_notes.id',
                            'quality_notes.details',
                            'users.name',

                            // applicant
                            'applicants.id',
                            'applicants.applicant_name',
                            'applicants.applicant_email',
                            'applicants.applicant_email_secondary',
                            'applicants.applicant_phone',
                            'applicants.applicant_postcode',
                            'applicants.applicant_landline',
                            'applicants.updated_at',
                            'applicants.applicant_cv',
                            'applicants.updated_cv',
                            'applicants.applicant_notes',
                            'applicants.job_category_id',
                            'applicants.job_title_id',
                            'applicants.job_type',

                            // sale
                            'sales.id', 
                            'sales.job_category_id', 
                            'sales.job_title_id', 
                            'sales.sale_postcode', 
                            'sales.job_type',
                            'sales.timing', 
                            'sales.salary', 
                            'sales.experience', 
                            'sales.qualification', 
                            'sales.benefits',
                            'sales.office_id',
                            'sales.unit_id',
                            'sales.position_type',
                            'sales.status',

                            // units
                            'units.unit_name', 
                            'units.unit_postcode', 
                            'units.unit_website',

                            'job_titles.name',
                            'job_categories.name',
                            'job_sources.name',
                            'offices.office_name',
                        );
                break;
            case 'requested cvs':
                default:
                $model->join('cv_notes', function ($join) {
                        $join->on('applicants.id', '=', 'cv_notes.applicant_id')
                            ->where("cv_notes.status", 1);
                    })
                    ->join('sales', function ($join) {
                        $join->on('cv_notes.sale_id', '=', 'sales.id')
                            ->whereColumn('cv_notes.sale_id', 'sales.id');
                    })
                    ->join('offices', 'sales.office_id', '=', 'offices.id')
                    ->join('units', 'sales.unit_id', '=', 'units.id')
                    ->join('history', function ($join) {
                        $join->on('cv_notes.applicant_id', '=', 'history.applicant_id');
                        $join->on('cv_notes.sale_id', '=', 'history.sale_id')
                            ->whereIn("history.sub_stage", ["quality_cvs"])
                            ->where("history.status", 1);
                    })
                    ->join('users', 'users.id', '=', 'cv_notes.user_id')
                    ->addSelect([
                        'cv_notes.details as notes_detail',
                        'cv_notes.created_at as notes_created_at',
                        'offices.office_name as office_name',

                        // sale
                        'sales.id as sale_id', 
                        'sales.job_category_id as sale_category_id', 
                        'sales.job_title_id as sale_title_id', 
                        'sales.sale_postcode', 
                        'sales.job_type as sale_job_type',
                        'sales.timing', 
                        'sales.salary', 
                        'sales.experience as sale_experience', 
                        'sales.qualification as sale_qualification', 
                        'sales.benefits',
                        'sales.office_id as sale_office_id',
                        'sales.unit_id as sale_unit_id',
                        'sales.position_type',
                        'sales.status as sale_status',

                        // units
                        'units.unit_name', 
                        'units.unit_postcode', 
                        'units.unit_website',

                        'users.name as user_name'
                    ]);
                break;
        }

        // Filter by type if it's not empty
        if ($categoryFilter) {
            $model->whereIn('applicants.job_category_id', $categoryFilter);
        }

        // Filter by type if it's not empty
        if ($titleFilter) {
            $model->whereIn('applicants.job_title_id', $titleFilter);
        }
        
        // Sorting logic
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            if ($orderColumn === 'job_source') {
                $model->orderBy('applicants.job_source_id', $orderDirection);
            } elseif ($orderColumn === 'job_category') {
                $model->orderBy('applicants.job_category_id', $orderDirection);
            } elseif ($orderColumn === 'job_title') {
                $model->orderBy('applicants.job_title_id', $orderDirection);
            } elseif ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $model->orderBy($orderColumn, $orderDirection);
            } else {
                $model->orderBy('notes_created_at', 'desc');
            }
        } else {
            $model->orderBy('notes_created_at', 'desc');
        }

        if ($request->has('search.value')) {
            $searchTerm = (string) $request->input('search.value');

            if (!empty($searchTerm)) {
                $model->where(function ($query) use ($searchTerm) {
                    // Direct column searches
                    $query->where('applicants.applicant_name', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('applicants.applicant_email', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('applicants.applicant_postcode', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('applicants.applicant_phone', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('applicants.applicant_experience', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('applicants.applicant_landline', 'LIKE', "%{$searchTerm}%");

                    // Relationship searches with explicit table names
                    $query->orWhereHas('jobTitle', function ($q) use ($searchTerm) {
                        $q->where('job_titles.name', 'LIKE', "%{$searchTerm}%");
                    });

                    $query->orWhereHas('jobCategory', function ($q) use ($searchTerm) {
                        $q->where('job_categories.name', 'LIKE', "%{$searchTerm}%");
                    });

                    $query->orWhereHas('jobSource', function ($q) use ($searchTerm) {
                        $q->where('job_sources.name', 'LIKE', "%{$searchTerm}%");
                    });
                   
                    $query->orWhereHas('user', function ($q) use ($searchTerm) {
                        $q->where('users.name', 'LIKE', "%{$searchTerm}%");
                    });
                });
            }
        }

        // Filter by type if it's not empty
        switch($typeFilter){
            case 'specialist':
                $model->where('applicants.job_type', 'specialist');
                break;
            case 'regular':
                $model->where('applicants.job_type', 'regular');
                break;
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn() // This will automatically add a serial number to the rows
                ->addColumn("user_name", function ($applicant) {
                    return ucwords($applicant->user_name) ?? '-';
                })
                ->addColumn('job_title', function ($applicant) {
                    return $applicant->jobTitle ? strtoupper($applicant->jobTitle->name) : '-';
                })
                ->addColumn('job_category', function ($sale) {
                    $type = $sale->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    return $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';
                })
                ->addColumn('job_source', function ($applicant) {
                    return $applicant->jobSource ? ucwords($applicant->jobSource->name) : '-';
                })
                ->addColumn('applicant_name', function ($applicant) {
                    return $applicant->formatted_applicant_name; // Using accessor
                })
                ->addColumn('applicant_email', function ($applicant) {
                    $email = '';
                    if ($applicant->applicant_email_secondary) {
                        $email = $applicant->is_blocked ? "<span class='badge bg-dark'>Blocked</span>" : $applicant->applicant_email . '<br>' . $applicant->applicant_email_secondary;
                    } else {
                        $email = $applicant->is_blocked ? "<span class='badge bg-dark'>Blocked</span>" : $applicant->applicant_email;
                    }

                    return $email; // Using accessor
                })
                ->addColumn('applicant_postcode', function ($applicant) {
                    $status_value = 'open';
                    if ($applicant->paid_status == 'close') {
                        $status_value = 'paid';
                    } else {
                        foreach ($applicant->cv_notes as $key => $value) {
                            if ($value->status == 'active') {
                                $status_value = 'sent';
                                break;
                            } elseif ($value->status == 'disable') {
                                $status_value = 'reject';
                            }
                        }
                    }

                    if($applicant->lat != null && $applicant->lng != null && $status_value == 'open' || $status_value == 'reject' && !$applicant->is_blocked){
                        $url = route('applicants.available_job', ['id' => $applicant->id, 'radius' => 15]);
                        $button = '<a href="'. $url .'" style="color:blue;" target="_blank">'. $applicant->formatted_postcode .'</a>'; // Using accessor
                    }else{
                        $button = $applicant->formatted_postcode;
                    }
                    return $button;
                })
                ->addColumn('notes_detail', function ($applicant) {
                    $fullHtml = $applicant->notes_detail; // HTML from Summernote
                    $id = 'qua-' . $applicant->id;

                    // 0. Remove inline styles and <span> tags (to avoid affecting layout)
                    $cleanedHtml = preg_replace('/<(span|[^>]+) style="[^"]*"[^>]*>/i', '<$1>', $fullHtml);
                    $cleanedHtml = preg_replace('/<\/?span[^>]*>/i', '', $cleanedHtml);

                    // 1. Convert block-level and <br> tags into \n
                    $withBreaks = preg_replace(
                        '/<(\/?(p|div|li|br|ul|ol|tr|td|table|h[1-6]))[^>]*>/i',
                        "\n",
                        $cleanedHtml
                    );

                    // 2. Remove all other HTML tags except basic formatting tags
                    $plainText = strip_tags($withBreaks, '<b><strong><i><em><u>');

                    // 3. Decode HTML entities
                    $decodedText = html_entity_decode($plainText);

                    // 4. Normalize multiple newlines
                    $normalizedText = preg_replace("/[\r\n]+/", "\n", $decodedText);

                    // 5. Limit preview characters
                    $preview = Str::limit(trim($normalizedText), 100);

                    // 6. Convert newlines to <br>
                    $shortText = nl2br($preview);

                    return '
                        <a href="#"
                        data-bs-toggle="modal"
                        data-bs-target="#' . $id . '">'
                        . $shortText . '
                        </a>

                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title"  style="color:#5d7186" id="' . $id . '-label">Notes Detail</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body" style="color:#5d7186">
                                        ' . $fullHtml . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>';
                })
                ->addColumn('applicant_phone', function ($applicant) {
                    $str = '';

                    if ($applicant->is_blocked) {
                        $str = "<span class='badge bg-dark'>Blocked</span>";
                    } else {
                        $str = '<strong>P:</strong> ' . $applicant->applicant_phone;

                        if ($applicant->applicant_landline) {
                            $str .= '<br><strong>L:</strong> ' . $applicant->applicant_landline;
                        }
                    }

                    return $str;
                })
                ->addColumn('notes_created_at', function ($applicant) {
                    return Carbon::parse($applicant->notes_created_at)->format('d M Y, h:iA'); // Using accessor
                })
                ->addColumn('applicant_resume', function ($applicant) {
                    $filePath = $applicant->applicant_cv;
                    $fileExists = $applicant->applicant_cv && Storage::disk('public')->exists($filePath);

                    if (!$applicant->is_blocked && $fileExists) {
                        return '<a href="' . asset('storage/' . $filePath) . '" title="Download CV" target="_blank" class="text-decoration-none">' .
                            '<iconify-icon icon="solar:download-square-bold" class="text-success fs-28"></iconify-icon></a>';
                    }

                    return '<button disabled title="CV Not Available" class="border-0 bg-transparent p-0">' .
                        '<iconify-icon icon="solar:download-square-bold" class="text-grey fs-28"></iconify-icon></button>';
                })
                ->addColumn('crm_resume', function ($applicant) {
                    $filePath = $applicant->updated_cv;
                    $fileExists = $applicant->updated_cv && Storage::disk('public')->exists($filePath);

                    if (!$applicant->is_blocked && $fileExists) {
                        return '<a href="' . asset('storage/' . $filePath) . '" title="Download Updated CV" target="_blank" class="text-decoration-none">' .
                            '<iconify-icon icon="solar:download-square-bold" class="text-primary fs-28"></iconify-icon></a>';
                    }

                    return '<button disabled title="CV Not Available" class="border-0 bg-transparent p-0">' .
                        '<iconify-icon icon="solar:download-square-bold" class="text-grey fs-28"></iconify-icon></button>';
                })
                ->addColumn('customStatus', function ($applicant) {
                    $status_value = 'open';
                    $color_class = 'bg-success';
                    if ($applicant->paid_status == 'close') {
                        $status_value = 'paid';
                        $color_class = 'bg-info';
                    } else {
                        foreach ($applicant->cv_notes as $key => $value) {
                            if ($value->status == 'active') {
                                $status_value = 'sent';
                                $color_class = 'bg-success';
                                break;
                            } elseif ($value->status == 'disable') {
                                $status_value = 'reject';
                                $color_class = 'bg-danger';
                            }
                        }
                    }

                    $status = '';
                    $status .= '<span class="badge ' . $color_class . '">';
                    $status .= strtoupper($status_value);
                    $status .= '</span>';
                    return $status;
                })
                ->addColumn('action', function ($applicant) use ($statusFilter) {
                    $position_type = strtoupper(str_replace('-', ' ', $applicant->position_type));
                    $position = '<span class="badge bg-primary">' . htmlspecialchars($position_type, ENT_QUOTES) . '</span>';

                    if ($applicant->sale_status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($applicant->sale_status == 0 && $applicant->is_on_hold == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($applicant->sale_status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($applicant->sale_status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

                    // Escape HTML in $status for JavaScript (to prevent XSS)
                    $escapedStatus = htmlspecialchars($status, ENT_QUOTES);

                    $html = '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">';

                    $office_name = ucwords($applicant->office_name);
                    $unit_name = ucwords($applicant->unit_name);
                    $postcode = strtoupper($applicant->sale_postcode);
                    $job_category = ucwords($applicant->job_category_name);
                    $job_title = strtoupper($applicant->job_title_name);


                    // Job Details Link
                    $html .= '<li><a href="#" class="dropdown-item" onclick="showDetailsModal('
                        . (int)$applicant->sale_id . ','
                        . '\'' . htmlspecialchars($office_name, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars($unit_name, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars($postcode, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars($job_category, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars($job_title, ENT_QUOTES) . '\','
                        . '\'' . $escapedStatus . '\','
                        . '\'' . htmlspecialchars($applicant->timing, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars($applicant->sale_experience, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars($applicant->salary, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars($position, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars($applicant->sale_qualification, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars($applicant->benefits, ENT_QUOTES) . '\''
                        . ')">Job Details</a></li>';

                    // Status-specific actions
                    switch ($statusFilter) {
                        case 'active cvs':
                            $html .= "<li><a class='dropdown-item' href='#' onclick='clearCVModal(".(int)$applicant->id.", ". (int)$applicant->sale_id . ", \"cleared\", \"Mark Clear CV\")'>Mark Clear CV</a></li>";
                            $html .= "<li><a class='dropdown-item' href='#' onclick='clearCVModal(".(int)$applicant->id.", ". (int)$applicant->sale_id . ", \"rejected\", \"Mark Reject CV\")'>Mark Reject CV</a></li>";
                            $html .= "<li><a class='dropdown-item' href='#' onclick='clearCVModal(".(int)$applicant->id.", ". (int)$applicant->sale_id . ", \"opened\", \"Mark Open CV\")'>Mark Open CV</a></li>";
                            break;
                        case 'open cvs':
                            $html .= "<li><a class='dropdown-item' href='#' onclick='clearCVModal(".(int)$applicant->id.", ". (int)$applicant->sale_id . ",\"revert\", \"Mark Revert CV\")'>Mark Revert CV</a></li>";
                            $html .= "<li><a class='dropdown-item' href='#' onclick='clearCVModal(".(int)$applicant->id.", ". (int)$applicant->sale_id . ",\"rejected\", \"Mark Reject CV\")'>Mark Reject CV</a></li>";
                            break;
                        case 'no job cvs': 
                            $html .= "<li><a class='dropdown-item' href='#' onclick='clearCVModal(".(int)$applicant->id.", ". (int)$applicant->sale_id . ", \"cleared_no_job\", \"Mark Clear CV\")'>Mark Clear CV</a></li>";
                            $html .= "<li><a class='dropdown-item' href='#' onclick='clearCVModal(".(int)$applicant->id.", ". (int)$applicant->sale_id . ", \"rejected\", \"Mark Reject CV\")'>Mark Reject CV</a></li>";
                            break;
                        case 'rejected cvs':
                            $html .= "<li><a class='dropdown-item' href='#' onclick='clearCVModal(".(int)$applicant->id.", ". (int)$applicant->sale_id . ", \"revert\", \"Mark Revert As Active\")'>Mark Revert As Active</a></li>";
                            break;
                        case 'cleared cvs':
                            $html .= "";
                            break;
                        default:
                            $html .= "<li><a class='dropdown-item' href='#' onclick='clearCVModal(".(int)$applicant->id.", ". (int)$applicant->sale_id . ", \"cleared\", \"Mark Clear CV\")'>Mark Clear CV</a></li>";
                            $html .= "<li><a class='dropdown-item' href='#' onclick='clearCVModal(".(int)$applicant->id.", ". (int)$applicant->sale_id . ", \"rejected\", \"Mark Reject CV\")'>Mark Reject CV</a></li>";
                            $html .= "<li><a class='dropdown-item' href='#' onclick='clearCVModal(".(int)$applicant->id.", ". (int)$applicant->sale_id . ", \"opened\", \"Mark Open CV\")'>Mark Open CV</a></li>";
                            break;
                    }
                    $html .= '<li>
                                <a class="dropdown-item" href="#" onclick="triggerFileInput(' . (int)$applicant->id . ')">Upload Applicant Resume</a>
                                <!-- Hidden File Input -->
                                <input type="file" id="fileInput" style="display:none" accept=".pdf,.doc,.docx" onchange="uploadFile()">
                            </li>';
                    $html .= '<li>
                                <a class="dropdown-item" href="#" onclick="triggerCrmFileInput(' . (int)$applicant->id . ')">Upload CRM Resume</a>
                                <!-- Hidden File Input -->
                                <input type="file" id="crmfileInput" style="display:none" accept=".pdf,.doc,.docx" onchange="crmuploadFile()">
                            </li>';
                    // Common actions
                    $html .= '<li><hr class="dropdown-divider"></li>';
                    $html .= '<li><a class="dropdown-item" href="#" onclick="viewNotesHistory('.(int)$applicant->id.', '. (int)$applicant->sale_id . ')">Notes History</a></li>';
                    $html .= '<li><a class="dropdown-item" href="#" onclick="viewManagerDetails(' . (int)$applicant->sale_unit_id . ')">Manager Details</a></li>';

                    $html .= '</ul></div>';

                    return $html;
                })
                ->rawColumns(['notes_detail', 'notes_created_at', 'applicant_email', 'applicant_postcode', 'crm_resume', 'applicant_phone', 'job_title', 'applicant_resume', 'customStatus', 'job_category', 'job_source', 'action'])
                ->make(true);
        }
    }
    public function getSalesByTypeAjaxRequest(Request $request)
    {
        $statusFilter = $request->input('status_filter', ''); // Default is empty (no filter)
        $typeFilter = $request->input('type_filter', ''); // Default is empty (no filter)
        $categoryFilter = $request->input('category_filter', ''); // Default is empty (no filter)
        $titleFilter = $request->input('title_filter', ''); // Default is empty (no filter)
        $limitCountFilter = $request->input('cv_limit_filter', ''); // Default is empty (no filter)
        $officeFilter = $request->input('office_filter', ''); // Default is empty (no filter)

        $model = Sale::query()
            ->select([
                'sales.*',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'offices.office_name as office_name',
                'units.unit_name as unit_name',
                'users.name as user_name',

                 // ADD THESE — fields from latest sale note
                'updated_notes.id as latest_note_id',
                'updated_notes.sale_note as latest_note',
                'updated_notes.created_at as latest_note_time',
            ])
            ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
            ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
            ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
            ->leftJoin('users', 'sales.user_id', '=', 'users.id')
            ->with(['jobTitle', 'jobCategory', 'unit', 'office', 'user'])
            // Subquery to get latest sale_note id per sale
            ->leftJoin(DB::raw("
                (SELECT sale_id, MAX(id) AS latest_id
                FROM sale_notes
                GROUP BY sale_id) AS latest_notes
            "), 'sales.id', '=', 'latest_notes.sale_id')

            // Join the actual sale_notes record
            ->leftJoin('sale_notes AS updated_notes', 'updated_notes.id', '=', 'latest_notes.latest_id')

            ->selectRaw(DB::raw("(SELECT COUNT(*) FROM cv_notes WHERE cv_notes.sale_id = sales.id AND cv_notes.status = 1) as no_of_sent_cv"));

        if ($request->has('search.value')) {
            $searchTerm = (string) $request->input('search.value');

            if (!empty($searchTerm)) {
                $model->where(function ($query) use ($searchTerm) {
                    $likeSearch = "%{$searchTerm}%";

                    $query->whereRaw('LOWER(sales.sale_postcode) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.experience) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.timing) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.job_description) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.job_type) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.position_type) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.cv_limit) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.salary) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.benefits) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.qualification) LIKE ?', [$likeSearch]);

                    // Relationship searches with explicit table names
                    $query->orWhereHas('jobTitle', function ($q) use ($likeSearch) {
                        $q->where('job_titles.name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('jobCategory', function ($q) use ($likeSearch) {
                        $q->where('job_categories.name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('unit', function ($q) use ($likeSearch) {
                        $q->where('units.unit_name', 'LIKE', "%{$likeSearch}%");
                    });
                   
                    $query->orWhereHas('user', function ($q) use ($likeSearch) {
                        $q->where('users.name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('office', function ($q) use ($likeSearch) {
                        $q->where('offices.office_name', 'LIKE', "%{$likeSearch}%");
                    });
                });
            }
        }

         // Filter by status if it's not empty
        switch ($statusFilter) {
            case 'requested sales':
                $model->where(function($query) {
                    $query->where('sales.status', 2)/**1=open, 2=pending */
                        ->orWhere('is_re_open', 2);/** re-open requested */
                });
                break;
                
            case 'rejected sales':
                $model->where('sales.status', 3);/**rejected */
                break;
                
            case 'cleared sales':
                $model->whereIn('sales.status', [0, 1]);/**0=disabled,1=active */
                break;
            default:
                $model->where(function($query) {
                    $query->where('sales.status', 2)/**1=open, 2=pending */
                        ->orWhere('is_re_open', 2);/** re-open requested */
                });
                break;
        }

        // Filter by type if it's not empty
        if ($typeFilter == 'specialist') {
            $model->where('sales.job_type', 'specialist');
        } elseif ($typeFilter == 'regular') {
            $model->where('sales.job_type', 'regular');
        }
       
        // Filter by category if it's not empty
        if ($officeFilter) {
            $model->whereIn('sales.office_id', $officeFilter);
        }
        
        // Filter by category if it's not empty
        if ($limitCountFilter) {
            if ($limitCountFilter == 'zero') {
                $model->where('sales.cv_limit', '=', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1'
                    ));
                });
            } elseif ($limitCountFilter == 'not max') {
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count > 0 
                        AND sent_cv_count <> sales.cv_limit'
                    ));
                });
            } elseif ($limitCountFilter == 'max') {
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count = 0'
                    ));
                });
            }
        }
       
        // Filter by category if it's not empty
        if ($categoryFilter) {
            $model->whereIn('sales.job_category_id', $categoryFilter);
        }
       
        // Filter by category if it's not empty
        if ($titleFilter) {
            $model->whereIn('sales.job_title_id', $titleFilter);
        }

        // Sorting logic
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            // Handle special cases first
            if ($orderColumn === 'job_source') {
                $model->orderBy('sales.job_source_id', $orderDirection);
            } elseif ($orderColumn === 'job_category') {
                $model->orderBy('sales.job_category_id', $orderDirection);
            } elseif ($orderColumn === 'job_title') {
                $model->orderBy('sales.job_title_id', $orderDirection);
            }
            // Default case for valid columns
            elseif ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $model->orderBy($orderColumn, $orderDirection);
            }
            // Fallback if no valid order column is found
            else {
                $model->orderBy('sales.updated_at', 'desc');
            }
        } else {
            // Default sorting when no order is specified
            $model->orderBy('sales.updated_at', 'desc');
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn() // This will automatically add a serial number to the rows
                ->addColumn('office_name', function ($sale) {
                    $office_id = $sale->office_id;
                    $office = Office::find($office_id);
                    return $office ? ucwords($office->office_name) : '-';
                })
                ->addColumn('unit_name', function ($sale) {
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    return $unit ? ucwords($unit->unit_name) : '-';
                })
                ->addColumn('job_title', function ($sale) {
                    return $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                })
                ->addColumn('cv_limit', function ($sale) {
                    $status = $sale->no_of_sent_cv == $sale->cv_limit ? '<span class="badge w-100 bg-danger" style="font-size:90%" >' . $sale->no_of_sent_cv . '/' . $sale->cv_limit . '<br>Limit Reached</span>' : "<span class='badge w-100 bg-primary' style='font-size:90%'>" . ((int)$sale->cv_limit - (int)$sale->no_of_sent_cv . '/' . (int)$sale->cv_limit) . "<br>Limit Remains</span>";
                    return $status;
                })
                ->addColumn('qualification', function ($sale) {
                    $fullHtml = $sale->qualification; // HTML from Summernote
                    $id = 'qua-' . $sale->id;

                    // 0. Remove inline styles and <span> tags (to avoid affecting layout)
                    $cleanedHtml = preg_replace('/<(span|[^>]+) style="[^"]*"[^>]*>/i', '<$1>', $fullHtml);
                    $cleanedHtml = preg_replace('/<\/?span[^>]*>/i', '', $cleanedHtml);

                    // 1. Convert block-level and <br> tags into \n
                    $withBreaks = preg_replace(
                        '/<(\/?(p|div|li|br|ul|ol|tr|td|table|h[1-6]))[^>]*>/i',
                        "\n",
                        $cleanedHtml
                    );

                    // 2. Remove all other HTML tags except basic formatting tags
                    $plainText = strip_tags($withBreaks, '<b><strong><i><em><u>');

                    // 3. Decode HTML entities
                    $decodedText = html_entity_decode($plainText);

                    // 4. Normalize multiple newlines
                    $normalizedText = preg_replace("/[\r\n]+/", "\n", $decodedText);

                    // 5. Limit preview characters
                    $preview = Str::limit(trim($normalizedText), 80);

                    // 6. Convert newlines to <br>
                    $shortText = nl2br($preview);

                    return '
                        <a href="#"
                        data-bs-toggle="modal"
                        data-bs-target="#' . $id . '">'
                        . $shortText . '
                        </a>

                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Sale Qualification</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . $fullHtml . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>';
                })
                ->addColumn('experience', function ($sale) {
                    $fullHtml = $sale->experience; // HTML from Summernote
                    $id = 'exp-' . $sale->id;

                    // 0. Remove inline styles and <span> tags (to avoid affecting layout)
                    $cleanedHtml = preg_replace('/<(span|[^>]+) style="[^"]*"[^>]*>/i', '<$1>', $fullHtml);
                    $cleanedHtml = preg_replace('/<\/?span[^>]*>/i', '', $cleanedHtml);

                    // 1. Convert block-level and <br> tags into \n
                    $withBreaks = preg_replace(
                        '/<(\/?(p|div|li|br|ul|ol|tr|td|table|h[1-6]))[^>]*>/i',
                        "\n",
                        $cleanedHtml
                    );

                    // 2. Remove all other HTML tags except basic formatting tags
                    $plainText = strip_tags($withBreaks, '<b><strong><i><em><u>');

                    // 3. Decode HTML entities
                    $decodedText = html_entity_decode($plainText);

                    // 4. Normalize multiple newlines
                    $normalizedText = preg_replace("/[\r\n]+/", "\n", $decodedText);

                    // 5. Limit preview characters
                    $preview = Str::limit(trim($normalizedText), 80);

                    // 6. Convert newlines to <br>
                    $shortText = nl2br($preview);

                    return '
                        <a href="#"
                        data-bs-toggle="modal"
                        data-bs-target="#' . $id . '">'
                        . $shortText . '
                        </a>

                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Sale Experience</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . $fullHtml . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>';
                })
                ->addColumn('salary', function ($sale) {
                    $fullHtml = $sale->salary; // HTML from Summernote
                    $id = 'slry-' . $sale->id;

                    // 0. Remove inline styles and <span> tags (to avoid affecting layout)
                    $cleanedHtml = preg_replace('/<(span|[^>]+) style="[^"]*"[^>]*>/i', '<$1>', $fullHtml);
                    $cleanedHtml = preg_replace('/<\/?span[^>]*>/i', '', $cleanedHtml);

                    // 1. Convert block-level and <br> tags into \n
                    $withBreaks = preg_replace(
                        '/<(\/?(p|div|li|br|ul|ol|tr|td|table|h[1-6]))[^>]*>/i',
                        "\n",
                        $cleanedHtml
                    );

                    // 2. Remove all other HTML tags except basic formatting tags
                    $plainText = strip_tags($withBreaks, '<b><strong><i><em><u>');

                    // 3. Decode HTML entities
                    $decodedText = html_entity_decode($plainText);

                    // 4. Normalize multiple newlines
                    $normalizedText = preg_replace("/[\r\n]+/", "\n", $decodedText);

                    // 5. Limit preview characters
                    $preview = Str::limit(trim($normalizedText), 80);

                    // 6. Convert newlines to <br>
                    $shortText = nl2br($preview);

                    return '
                        <a href="#"
                        data-bs-toggle="modal"
                        data-bs-target="#' . $id . '">'
                        . $shortText . '
                        </a>

                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Sale`s Salary</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . $fullHtml . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>';
                })
                ->addColumn('job_category', function ($sale) {
                    $type = $sale->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    return $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';
                })
                ->addColumn('sale_postcode', function ($sale) {
                    if($sale->lat != null && $sale->lng != null){
                        $url = url('/sales/fetch-applicants-by-radius/'. $sale->id . '/15');
                        $button = '<a target="_blank" href="'. $url .'" style="color:blue;">'. $sale->formatted_postcode .'</a>'; // Using accessor
                    }else{
                        $button = $sale->formatted_postcode;
                    }
                    return $button;
                })
                ->addColumn('created_at', function ($sale) {
                    return $sale->formatted_created_at; // Using accessor
                })
                ->addColumn('updated_at', function ($sale) {
                    return $sale->formatted_updated_at; // Using accessor
                })
                ->addColumn('sale_notes', function ($sale) {
                    $notesIndex = '-'; 
                    if(!empty($sale->sale_notes)){
                        $notesIndex = $sale->sale_notes;
                    }else{
                        $notesIndex = $sale->latest_note;
                    }

                    $id = 'note-' . $sale->id;

                    // 0. Remove inline styles and <span> tags (to avoid affecting layout)
                    $cleanedHtml = preg_replace('/<(span|[^>]+) style="[^"]*"[^>]*>/i', '<$1>', $notesIndex);
                    $cleanedHtml = preg_replace('/<\/?span[^>]*>/i', '', $cleanedHtml);

                    // 1. Convert block-level and <br> tags into \n
                    $withBreaks = preg_replace(
                        '/<(\/?(p|div|li|br|ul|ol|tr|td|table|h[1-6]))[^>]*>/i',
                        "\n",
                        $cleanedHtml
                    );

                    // 2. Remove all other HTML tags except basic formatting tags
                    $plainText = strip_tags($withBreaks, '<b><strong><i><em><u>');

                    // 3. Decode HTML entities
                    $decodedText = html_entity_decode($plainText);

                    // 4. Normalize multiple newlines
                    $normalizedText = preg_replace("/[\r\n]+/", "\n", $decodedText);

                    // 5. Limit preview characters
                    $preview = Str::limit(trim($normalizedText), 200);

                    // 6. Convert newlines to <br>
                    $shortText = nl2br($preview);

                    return '
                        <a href="#"
                        data-bs-toggle="modal"
                        data-bs-target="#' . $id . '">'
                        . $shortText . '
                        </a>

                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Notes Detail</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . $notesIndex . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>';
                })
                ->addColumn('status', function ($sale) {
                    $status = '';
                    if ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status = '<span class="badge bg-dark">Re-Open</span>';
                    } elseif ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 1) {
                        $status = '<span class="badge bg-success">Open</span>';
                    } elseif ($sale->status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

                    return $status;
                })
                ->addColumn('action', function ($sale) use ($statusFilter) {
                    $postcode = $sale->formatted_postcode;
                    $posted_date = $sale->formatted_created_at;
                    $office_id = $sale->office_id;
                    $office = Office::find($office_id);
                    $office_name = $office ? ucwords($office->office_name) : '-';
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    $unit_name = $unit ? ucwords($unit->unit_name) : '-';
                    $status = '';
                    $jobTitle = $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                    $type = $sale->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    $jobCategory = $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';

                    if ($sale->status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 0 && $sale->is_on_hold == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

                    $position_type = strtoupper(str_replace('-',' ',$sale->position_type));
                    $position = '<span class="badge bg-primary">'. $position_type .'</span>';
                    
                    $action = '';
                    $action = '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#" onclick="showDetailsModal(
                                    ' . (int)$sale->id . ',
                                    \'' . addslashes($posted_date) . '\',
                                    \'' . addslashes(htmlspecialchars($office_name)) . '\',
                                    \'' . addslashes(htmlspecialchars($unit_name)) . '\',
                                    \'' . addslashes(htmlspecialchars($postcode)) . '\',
                                    \'' . addslashes(htmlspecialchars($jobCategory)) . '\',
                                    \'' . addslashes(htmlspecialchars($jobTitle)) . '\',
                                    \'' . addslashes(htmlspecialchars($status)) . '\',
                                    \'' . addslashes(htmlspecialchars($sale->timing)) . '\',
                                    \'' . addslashes(htmlspecialchars($sale->experience)) . '\',
                                    \'' . addslashes(htmlspecialchars($sale->salary)) . '\',
                                    \'' . addslashes(htmlspecialchars($position)) . '\',
                                    \'' . addslashes(htmlspecialchars($sale->qualification)) . '\',
                                    \'' . addslashes(htmlspecialchars($sale->benefits)) . '\',
                                    )">View</a></li>';
                     // Filter by status if it's not empty
                    switch ($statusFilter) {
                        case 'active sales':
                           // Filter by status if it's not empty
                            if (in_array($sale->status, [1, 2]) || $sale->is_re_open == true) {
                                $action .= '<li><a class="dropdown-item" href="#" onclick="changeSaleStatus('.$sale->id.', \'clear\')">Mark Clear Sale</a></li>';
                                $action .= '<li><a class="dropdown-item" href="#" onclick="changeSaleStatus('.$sale->id.', \'reject\')">Mark Reject Sale</a></li>';
                            }
                            break;
                            
                        case 'rejected sales':
                            $action .= '';
                            break;
                            
                        case 'cleared sales':
                             $action .= '';
                            break;
                        default:
                            // Filter by status if it's not empty
                            if (in_array($sale->status, [1, 2]) || $sale->is_re_open == true) {
                                $action .= '<li><a class="dropdown-item" href="#" onclick="changeSaleStatus('.$sale->id.', \'clear\')">Mark Clear Sale</a></li>';
                                $action .= '<li><a class="dropdown-item" href="#" onclick="changeSaleStatus('.$sale->id.', \'reject\')">Mark Reject Sale</a></li>';
                            }
                            break;
                    }
                    
                    $action .= '<li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="#" onclick="viewSaleDocuments(' . $sale->id . ')">View Documents</a></li>
                                <li><a class="dropdown-item" href="#" onclick="viewNotesHistory(' . $sale->id . ')">Notes History</a></li>
                                <li><a class="dropdown-item" href="#" onclick="viewManagerDetails(' . $sale->unit_id . ')">Manager Details</a></li>
                            </ul>
                        </div>';

                    return $action;
                })
                ->rawColumns(['sale_notes', 'sale_postcode', 'experience', 'salary', 'qualification', 'cv_limit', 'open_date', 'job_title', 'job_category', 'office_name', 'unit_name', 'status', 'action', 'statusFilter'])
                ->make(true);
        }
    }
    public function clearRejectSale(Request $request)
    {
        $user = Auth::user();

        $id = $request->input('sale_id');
        $notes = $request->input('details') . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y');
        $status = $request->input('status');

        try {
            $sale = Sale::findOrFail($id);

            // Validate and determine new status value
            $status_value = null;
            if ($status === 'clear') {
                $status_value = 1;
            } elseif ($status === 'reject') {
                $status_value = 3;
            }

            // Update sale based on status
            if ($sale->status == 1) {
                if ($status === 'reject') {
                    $sale->update(['status' => 3]);
                } else {
                    $sale->update(['is_re_open' => 1]);
                }
            } else {
                $sale->update([
                    'status' => ($status == 'clear') ? 1 : 3
                ]);
            }

            // Disable previous module note
            ModuleNote::where([
                    'module_noteable_id' => $id,
                    'module_noteable_type' => 'Horsefly\Sale'
                ])
                ->where('status', 1)
                ->update(['status' => 0]);

            // Create new module note
            $moduleNote = ModuleNote::create([
                'details' => $notes,
                'module_noteable_id' => $id,
                'module_noteable_type' => 'Horsefly\Sale',
                'user_id' => $user->id,
            ]);

            $moduleNote->update(['module_note_uid' => md5($moduleNote->id)]);

            // Log audit
            $audit = new ActionObserver();
            $audit->changeSaleStatus($sale, ['status' => $status_value]);

            // Invalidate existing notes
            SaleNote::where('sale_id', $sale->id)
                ->where('status', 1)
                ->update(['status' => 0]);

            // Create new note and update UID
            $sale_note = new SaleNote([
                'sale_id' => $id,
                'user_id' => $user->id,
                'sale_note' => $notes,
            ]);
            $sale_note->save();

            $sale_note->sales_notes_uid = md5($sale_note->id);
            $sale_note->save();

            return response()->json([
                'success' => true,
                'message' => 'Sale status changed successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => config('app.debug') 
                    ? $e->getMessage() 
                    : 'An error occurred while updating the sale. Please try again.'
            ], 500);
        }
    }
    public function updateApplicantStatusByQuality(Request $request)
    {
        $applicant_id = $request->input('applicant_id');
        $sale_id = $request->input('sale_id');
        $notes = $request->input('details');
        $status = $request->input('status');

        $user = Auth::user();
        $details = $notes . " --- ". ucwords($status) ." By: " . $user->name;

        try{
            if($status == 'rejected'){
                Applicant::where("id", $applicant_id)
                    ->update([
                        'is_cv_in_quality_reject' => true, 
                        'is_cv_in_quality' => false
                    ]);

                CVNote::where([
                    'sale_id' => $sale_id, 
                    'applicant_id' => $applicant_id
                    ])->update(['status' => 0]);

            }elseif($status == 'cleared'){
                Applicant::where("id", $applicant_id)
                    ->update([
                        'is_interview_confirm' => true,
                        'is_cv_in_quality_clear' => true,
                        'is_cv_in_quality' => false,
                        'is_cv_in_quality_reject' => false, 
                    ]);

                CrmNote::where([
                    'applicant_id' => $applicant_id,
                    'sale_id' => $sale_id
                    ])->update(['status' => 0]);

                $crm_notes = new CrmNote();
                $crm_notes->applicant_id = $applicant_id;
                $crm_notes->user_id = $user->id;
                $crm_notes->sale_id = $sale_id;
                $crm_notes->details = $details;
                $crm_notes->moved_tab_to = "cv_sent";
                $crm_notes->save();

                /** Update UID */
                $crm_notes->crm_notes_uid = md5($crm_notes->id);
                $crm_notes->save();

                $formattedMessage = '';
                // Fetch SMS template from the database
                $sms_template = SmsTemplate::where('slug', 'quality_cleared')
                    ->where('status', 1)
                    ->first();

                if ($sms_template && !empty($sms_template->template)) {
                    $sms_template_text = $sms_template->template;
                    $applicant = Applicant::find($applicant_id);
                    $sale = Sale::find($sale_id);
                    $unit = $sale ? Unit::find($sale->unit_id) : null;

                    $replace = [
                        $applicant ? $applicant->applicant_name : '',
                        $unit ? $unit->unit_name : ''
                    ];
                    $prev_val = ['(applicant_name)', '(unit_name)'];

                    $newPhrase = str_replace($prev_val, $replace, $sms_template_text);
                    $formattedMessage = nl2br($newPhrase);

                    if ($applicant && $applicant->applicant_phone) {
                        $is_save = $this->saveSMSDB($applicant->applicant_phone, $formattedMessage, $applicant->id);
                        if (!$is_save) {
                            Log::warning('SMS saved to DB failed for applicant ID: ' . $applicant->id);
                            throw new \Exception('SMS is not stored in DB');
                        }
                    }
                }
            }elseif($status == 'cleared_no_job'){
                Applicant::where("id", $applicant_id)
                    ->update([
                        'is_interview_confirm' => true,
                        'is_cv_in_quality_clear' => true,
                        'is_cv_in_quality' => false,
                        'is_cv_in_quality_reject' => false, 
                    ]);

                $crm_notes = new CrmNote();
                $crm_notes->applicant_id = $applicant_id;
                $crm_notes->user_id = $user->id;
                $crm_notes->sale_id = $sale_id;
                $crm_notes->details = $details;
                $crm_notes->moved_tab_to = "cv_sent_no_job";
                $crm_notes->save();

                /** Update UID */
                $crm_notes->crm_notes_uid = md5($crm_notes->id);
                $crm_notes->save();

            }elseif($status == 'revert'){//Revert from Open Cv
                $cv_count = CvNote::where([
                'cv_notes.sale_id' => $sale_id, 
                'cv_notes.status' => 1
                ])->count();

                $sale_cv_count = Sale::select('cv_limit')
                    ->where('id', $sale_id)->first();

                if ($cv_count >=  $sale_cv_count->cv_limit) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Sale cv limit exceeded',
                    ]);
                }

                CvNote::where([
                    'sale_id' => $sale_id, 
                    'applicant_id' => $applicant_id
                    ])->update(['status' => 0 ]);

                $cv_note = new CvNote();
                $cv_note->sale_id = $sale_id;
                $cv_note->applicant_id = $applicant_id;
                $cv_note->user_id = $user->id;
                $cv_note->details = $details;
                $cv_note->save();

                /** Update UID */
                $cv_note->cv_uid = md5($cv_note->id);
                $cv_note->save();

            }
            
            $audit_data['action'] = $status;
            $audit_data['sale'] = $sale_id;
            $audit_data['details'] = $details;
            $audit_data['applicant'] = $applicant_id;

            $qualityStatus = null;
            if($status == "opened"){
                $qualityStatus = "cv_hold";
            }else{
                $qualityStatus = $status;
            }

            QualityNotes::where([
                    'applicant_id' => $applicant_id,
                    'sale_id' => $sale_id,
                ])->update(['status' => 0]);

            if($status != 'revert'){
                $quality_notes = new QualityNotes();
                $quality_notes->applicant_id = $applicant_id;
                $quality_notes->user_id = $user->id;
                $quality_notes->sale_id = $sale_id;
                $quality_notes->details = $details;
                $quality_notes->moved_tab_to = $qualityStatus;
                $quality_notes->save();

                /** Update UID */
                $quality_notes->quality_notes_uid = md5($quality_notes->id);
                $quality_notes->save();
            }

            History::where([
                "applicant_id" => $applicant_id,
                "sale_id" => $sale_id
            ])
            ->update(["status" => 0]);

            $historyStatus = null;
            if($status == 'rejected'){
                $historyStatus = 'quality_reject';
            }elseif($status == 'cleared'){
                $historyStatus = 'quality_cleared';
            }elseif($status == 'opened'){
                $historyStatus = 'quality_cvs_hold';
            }elseif($status == 'revert'){
                $historyStatus = 'quality_cvs';
            }elseif($status == 'cleared_no_job'){
                $historyStatus = 'quality_cleared_no_job';
            }

            $history = new History();
            $history->applicant_id = $applicant_id;
            $history->user_id = $user->id;
            $history->sale_id = $sale_id;
            $history->stage = 'quality';
            $history->sub_stage = $historyStatus;
            $history->save();

            /** Update UID */
            $history->history_uid = md5($history->id);
            $history->save();
            
            if($status != 'cleared'){
                $revertStatus = null;
                if($status == "opened"){
                    $revertStatus = "cv_hold";
                }elseif($status == "rejected"){
                    $revertStatus = 'quality_note';
                }elseif($status == "revert"){
                    $revertStatus = 'quality_revert';
                }

                RevertStage::create([
                    'applicant_id' => $applicant_id,
                    'sale_id' => $sale_id,
                    'stage' => $revertStatus,
                    'user_id' => $user->id,
                    'notes' => $details,
                ]);
            }

            //send sms
            if($status == 'cleared'){
                // $unit_name = Sale::join('units', 'sales.unit_id', '=', 'units.id')
                //         ->where('sales.id', '=', $sale_id)
                //         ->select('units.unit_name')
                //         ->first();

                // $applicant_number = $applicant_phone;
                // $applicant_message = 'Hi Thank you for your time over the phone. I am sharing your resume details with the manager of ' . $unit_name . ' for the discussed vacancy. If you have any questions, feel free to reach out. Thank you for choosing Kingbury to represent you. Best regards, CRM TEAM T: 01494211220 E: crm@kingsburypersonnel.com';

                // $applicant_message_encoded = urlencode($applicant_message);
                // $query_string = 'http://milkyway.tranzcript.com:1008/sendsms?username=admin&password=admin&phonenumber=' . $applicant_number . '&message=' . $applicant_message_encoded . '&port=1&report=JSON&timeout=0';

                // $sms_res = $this->sendQualityClearSms($query_string);
                // $smsSaveRes = '';
                // if ($sms_res['result'] == 'success') {
                //     $userData = json_decode($sms_res['data'], true);
                //     $message = $userData['message'];
                //     $phone = $userData['report'][0][1][0]['phonenumber'];
                //     $timeString = $userData['report'][0][1][0]['time'];
                //     $sms_response = $this->saveQualityClearSendMessage($message, $phone, $timeString);
                //     if ($sms_response) {
                //         $smsSaveRes = 'success';
                //     } else {
                //         $smsSaveRes = 'error';
                //     }
                //     $smsResult = 'Successfuly!';
                // } else {
                //     $smsResult = 'Error';
                // }
            }

            return response()->json([
                'success' => true,
                'message' => 'Resource '. $status .' successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => config('app.debug') 
                    ? $e->getMessage() 
                    : 'An error occurred while updating the record. Please try again.'
            ], 500);
        }
    }
    public function getQualityNotesHistory(Request $request)
    {
        try {
            // Validate the incoming request to ensure 'id' is provided and is a valid integer
            $request->validate([
                'applicant_id' => 'required',  // Assuming 'module_notes' is the table name and 'id' is the primary key
                'sale_id' => 'required',  // Assuming 'module_notes' is the table name and 'id' is the primary key
            ]);

            // Fetch the module notes by the given ID
            $qualityNotes = QualityNotes::where('applicant_id', $request->applicant_id)
                ->where('sale_id', $request->sale_id)
                ->latest()->get();

            // Check if the module note was found
            if (!$qualityNotes) {
                return response()->json(['error' => 'Quality note not found'], 404);  // Return 404 if not found
            }

            // Return the specific fields you need (e.g., applicant name, notes, etc.)
            return response()->json([
                'data' => $qualityNotes,
                'success' => true
            ]);
        } catch (\Exception $e) {
            // If an error occurs, catch it and return a meaningful error message
            return response()->json([
                'error' => 'An unexpected error occurred. Please try again later.',
                'message' => $e->getMessage(),
                'success' => false
            ], 500); // Internal server error
        }
    }
}
