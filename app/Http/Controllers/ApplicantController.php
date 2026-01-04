<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Horsefly\Sale;
use Horsefly\Office;
use Horsefly\Unit;
use Horsefly\Applicant;
use Horsefly\ApplicantNote;
use Horsefly\ModuleNote;
use Horsefly\EmailTemplate;
use Horsefly\SmsTemplate;
use Horsefly\Message;
use Horsefly\Setting;
use Horsefly\JobTitle;
use Horsefly\JobSource;
use Horsefly\CVNote;
use Horsefly\History;
use Horsefly\JobCategory;
use Horsefly\ApplicantPivotSale;
use Horsefly\NotesForRangeApplicant;
use App\Exports\ApplicantsExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Horsefly\Mail\GenericEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Gate;
use App\Observers\ActionObserver;
use App\Traits\SendEmails;
use App\Traits\SendSMS;
use App\Traits\Geocode;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Str;
use League\Csv\Reader;

class ApplicantController extends Controller
{
    use SendEmails, SendSMS, Geocode;

    public function __construct()
    {
        //
    }
    /**
     * Display a listing of the applicants.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name', 'asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name', 'asc')->get();
        return view('applicants.list', compact('jobCategories', 'jobTitles'));
    }
    public function create()
    {
        $jobSources = JobSource::orderBy('name', 'asc')->get();
        $jobCategories = JobCategory::orderBy('name', 'asc')->get();
        $jobTitles = JobTitle::orderBy('name', 'asc')->get();
        return view('applicants.create', compact('jobSources', 'jobCategories', 'jobTitles'));
    }
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'job_category_id' => 'required|exists:job_categories,id',
            'job_type' => ['required', Rule::in(['specialist', 'regular'])],
            'job_title_id' => 'required|exists:job_titles,id',
            'job_source_id' => 'required|exists:job_sources,id',
            'applicant_name' => 'required|string|max:255',
            'gender' => 'required',
            'applicant_email' => 'required|email|max:255|unique:applicants,applicant_email',
            'applicant_email_secondary' => 'nullable|email|max:255|unique:applicants,applicant_email_secondary',
            'applicant_postcode' => ['required', 'string', 'min:2', 'max:8', 'regex:/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d ]+$/'],
            'applicant_phone' => [
                'required',
                'string',
                'max:11',
                Rule::unique('applicants', 'applicant_phone'),
                Rule::unique('applicants', 'applicant_phone_secondary'),
            ],
            'applicant_phone_secondary' => [
                'nullable',
                'string',
                'max:11',
                Rule::unique('applicants', 'applicant_phone'),
                Rule::unique('applicants', 'applicant_phone_secondary'),
            ],
            'applicant_landline' => 'nullable|string|max:11|unique:applicants,applicant_landline',
            'applicant_experience' => 'nullable|string',
            'applicant_notes' => 'required|string|max:255',
            'applicant_cv' => 'nullable|mimes:docx,doc,csv,pdf,txt|max:5000', // max 5mb
        ]);

        $validator->sometimes('have_nursing_home_experience', 'required|boolean', function ($input) {
            $nurseCategory = JobCategory::where('name', 'nurse')->first();
            return $nurseCategory && $input->job_category_id == $nurseCategory->id;
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $applicantData = $request->only([
                'job_category_id',
                'job_type',
                'job_title_id',
                'job_source_id',
                'applicant_name',
                'applicant_email',
                'applicant_email_secondary',
                'applicant_postcode',
                'applicant_phone',
                'applicant_phone_secondary',
                'applicant_landline',
                'applicant_experience',
                'applicant_notes',
                'have_nursing_home_experience',
                'gender',
            ]);

            $applicantData['applicant_phone'] = preg_replace('/[^0-9]/', '', $applicantData['applicant_phone']);
            $applicantData['applicant_phone_secondary'] = $applicantData['applicant_phone_secondary'] 
                ? preg_replace('/[^0-9]/', '', $applicantData['applicant_phone_secondary'])
                : null;
            $applicantData['applicant_landline'] = $applicantData['applicant_landline']
                ? preg_replace('/[^0-9]/', '', $applicantData['applicant_landline'])
                : null;

            // Sanitize emails (trim spaces and lowercase)
            $applicantData['applicant_email'] = isset($applicantData['applicant_email'])
                ? strtolower(trim($applicantData['applicant_email']))
                : null;

            $applicantData['applicant_email_secondary'] = isset($applicantData['applicant_email_secondary'])
                ? strtolower(trim($applicantData['applicant_email_secondary']))
                : null;

            $applicantData['user_id'] = Auth::id();

            $applicantData['applicant_notes'] = $applicant_notes = $request->applicant_notes . ' --- By: ' . Auth::user()->name . ' Date: ' . Carbon::now()->format('d-m-Y');

            if ($request->hasFile('applicant_cv')) {

                // Get original filename and extension
                $filenameWithExt = $request->file('applicant_cv')->getClientOriginalName();
                $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                $extension = $request->file('applicant_cv')->getClientOriginalExtension();

                // 🧹 Replace all spaces (and multiple spaces) with a single underscore
                $filename = preg_replace('/\s+/', '_', trim($filename));

                // Generate unique filename
                $fileNameToStore = $filename . '_' . time() . '.' . $extension;

                // Build dynamic directory path: uploads/resume/YYYY/MM/DD
                $year = now()->year;
                $month = now()->format('m');
                $day = now()->format('d');
                $directory = "uploads/resume/{$year}/{$month}/{$day}";

                // Store the file in the "public" disk under that directory
                $path = $request->file('applicant_cv')->storeAs($directory, $fileNameToStore, 'public');

                // Save file path in DB
                $applicantData['applicant_cv'] = $path;
            }

            $postcode = $request->applicant_postcode;
            $postcode_query = strlen($postcode) < 6
                ? DB::table('outcodepostcodes')->where('outcode', $postcode)->first()
                : DB::table('postcodes')->where('postcode', $postcode)->first();

            if (!$postcode_query) {
                try {
                    $result = $this->geocode($postcode);
                    // If geocode fails, throw
                    if (!isset($result['lat']) || !isset($result['lng'])) {
                        throw new \Exception('Geolocation failed. Latitude and longitude not found.');
                    }

                    $applicantData['lat'] = $result['lat'];
                    $applicantData['lng'] = $result['lng'];
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unable to locate address: ' . $e->getMessage()
                    ], 400);
                }
            } else {
                $applicantData['lat'] = $postcode_query->lat;
                $applicantData['lng'] = $postcode_query->lng;
            }

            // ✅ Validate lat/lng presence before inserting
            if (empty($applicantData['lat']) || empty($applicantData['lng'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Postcode location is required. Please provide a valid postcode.'
                ], 400);
            }

            $applicant = Applicant::create($applicantData);
            $applicant->update(['applicant_uid' => md5($applicant->id)]);

            $phones = array_filter([
                $applicant->applicant_phone,
                $applicant->applicant_phone_secondary,
            ]);

            if (!empty($phones)) {
                Message::where(function ($q) use ($phones) {
                        foreach ($phones as $phone) {
                            $q->orWhere('phone_number', $phone); // exact match preferred
                        }
                    })
                    ->update([
                        'module_id'   => $applicant->id,
                        'module_type' => Applicant::class,
                    ]);
            }

            // Create new module note
            $moduleNote = ModuleNote::create([
                'details' => $applicant_notes,
                'module_noteable_id' => $applicant->id,
                'module_noteable_type' => 'Horsefly\Applicant',
                'user_id' => Auth::id()
            ]);

            $moduleNote->update([
                'module_note_uid' => md5($moduleNote->id)
            ]);

            $jobCategory = JobCategory::find($request->job_category_id);
            $jobCategoryName = $jobCategory ? $jobCategory->name : '';

            /** Send Email */
            $email_template = EmailTemplate::where('slug', 'applicant_welcome_email')
                ->where('is_active', 1)
                ->first();

            $emailNotification = Setting::where('key', 'email_notifications')->first();

            if (
                $email_template
                && $emailNotification
                && $emailNotification->value == '1'
                && !empty($email_template->template)
                && !empty($applicant->applicant_email)
            ) {
                $email_to = $applicant->applicant_email;
                $email_from = $email_template->from_email;
                $email_subject = $email_template->subject;
                $email_body = $email_template->template;
                $email_title = $email_template->title;

                $replace = [$applicant->applicant_name, 'an Online Portal', $jobCategoryName];
                $prev_val = ['(applicant_name)', '(website_name)', '(job_category)'];

                $newPhrase = str_replace($prev_val, $replace, $email_body);
                $formattedMessage = nl2br($newPhrase);

                // Attempt to send email
                $is_save = $this->saveEmailDB($email_to, $email_from, $email_subject, $formattedMessage, $email_title, $applicant->id);
                if (!$is_save) {
                    // Optional: throw or log
                    Log::warning('Email saved to DB failed for applicant ID: ' . $applicant->id);
                    throw new \Exception('Email is not stored in DB');
                }
            }

            // Fetch SMS template from the database
            $sms_template = SmsTemplate::where('slug', 'applicant_welcome_sms')
                ->where('status', 1)
                ->first();

            $smsNotification = Setting::where('key', 'sms_notifications')->first();

            if (
                $sms_template
                && $smsNotification
                && $smsNotification->value == '1'
                && !empty($sms_template->template)
                && !empty($applicant->applicant_email)
            ) {
                $sms_to = $applicant->applicant_phone;
                $sms_template = $sms_template->template;

                $replace = [$applicant->applicant_name, 'an Online Portal', $jobCategoryName];
                $prev_val = ['(applicant_name)', '(website_name)', '(job_category)'];

                $newPhrase = str_replace($prev_val, $replace, $sms_template);
                $formattedMessage = nl2br($newPhrase);

                $is_save = $this->saveSMSDB($sms_to, $formattedMessage, $applicant->id);
                if (!$is_save) {
                    // Optional: throw or log
                    Log::warning('SMS saved to DB failed for applicant ID: ' . $applicant->id);
                    throw new \Exception('SMS is not stored in DB');
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Applicant created successfully.'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating applicant: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred: ' . $e->getMessage()
            ], 500);
        }
    }
    public function getApplicantsAjaxRequest(Request $request)
    {
        $statusFilter = $request->input('status_filter', ''); // Default is empty (no filter)
        $typeFilter = $request->input('type_filter', ''); // Default is empty (no filter)
        $categoryFilter = $request->input('category_filter', ''); // Default is empty (no filter)
        $titleFilters = $request->input('title_filters', ''); // Default is empty (no filter)

        $model = Applicant::query()
            ->select([
                'applicants.*',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'job_sources.name as job_source_name'
            ])
            ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
            ->leftJoin('job_sources', 'applicants.job_source_id', '=', 'job_sources.id')
            ->with(['jobTitle', 'jobCategory', 'jobSource', 'crmHistory']);

        /** Sorting logic */
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            // Handle special cases first
            if ($orderColumn === 'job_source') {
                $model->orderBy('applicants.job_source_id', $orderDirection);
            } elseif ($orderColumn === 'job_category') {
                $model->orderBy('applicants.job_category_id', $orderDirection);
            } elseif ($orderColumn === 'job_title') {
                $model->orderBy('applicants.job_title_id', $orderDirection);
            }
            // Default case for valid columns
            elseif ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $model->orderBy($orderColumn, $orderDirection);
            }
            // Fallback if no valid order column is found
            else {
                $model->orderBy('applicants.created_at', 'desc');
            }
        } else {
            // Default sorting when no order is specified
            $model->orderBy('applicants.created_at', 'desc');
        }

        if ($request->filled('search.value')) {
            $search = trim($request->search['value']);
            
            // Split the search string into individual words (assuming words are space-separated)
            $searchWords = explode(' ', $search);

            // If there are two or more words, we need to search for each word in any field
            if (count($searchWords) > 1) {
                $model->where(function ($q) use ($searchWords) {
                    // Ensure each word is searched for across relevant fields
                    foreach ($searchWords as $word) {
                        $q->orWhere(function ($q) use ($word) {
                            $q->where('applicants.applicant_name', 'LIKE', "%{$word}%")
                                ->orWhere('applicants.applicant_email', 'LIKE', "%{$word}%")
                                ->orWhere('applicants.applicant_postcode', 'LIKE', "%{$word}%")
                                ->orWhere('applicants.applicant_phone', 'LIKE', "%{$word}%")
                                ->orWhere('applicants.applicant_phone_secondary', 'LIKE', "%{$word}%")
                                ->orWhere('applicants.applicant_landline', 'LIKE', "%{$word}%")
                                ->orWhere('applicants.applicant_experience', 'LIKE', "%{$word}%");
                        });
                    }
                });
            } else {
                // If there's only one word, continue with the previous logic
                if (strlen($search) >= 3) {
                    $model->where(function ($q) use ($search) {
                        // Search across multiple fields
                        $q->where('applicants.applicant_name', 'LIKE', "%{$search}%")
                            ->orWhere('applicants.applicant_email', 'LIKE', "%{$search}%")
                            ->orWhere('applicants.applicant_postcode', 'LIKE', "%{$search}%")
                            ->orWhere('applicants.applicant_phone', 'LIKE', "%{$search}%")
                            ->orWhere('applicants.applicant_phone_secondary', 'LIKE', "%{$search}%")
                            ->orWhere('applicants.applicant_landline', 'LIKE', "%{$search}%")
                            ->orWhere('applicants.applicant_experience', 'LIKE', "%{$search}%");

                        // Search related tables
                        $q->orWhereHas('jobTitle', fn($x) => $x->where('job_titles.name', 'LIKE', "%{$search}%"))
                            ->orWhereHas('jobCategory', fn($x) => $x->where('job_categories.name', 'LIKE', "%{$search}%"))
                            ->orWhereHas('jobSource', fn($x) => $x->where('job_sources.name', 'LIKE', "%{$search}%"));
                    });
                } else {
                    // Short search handling
                    $model->where(function ($q) use ($search) {
                        $q->where('applicants.applicant_phone', 'LIKE', "%{$search}%")
                            ->orWhere('applicants.applicant_phone_secondary', 'LIKE', "%{$search}%")
                            ->orWhere('applicants.applicant_landline', 'LIKE', "%{$search}%")
                            ->orWhere('applicants.applicant_postcode', 'LIKE', "%{$search}%");
                    });
                }
            }
        }


        // Filter by status if it's not empty
        switch ($statusFilter) {
            // case 'active':
            //     $model->where('applicants.status', 1)
            //         ->where('applicants.is_no_job', false)
            //         ->where('applicants.is_blocked', false)
            //         ->where('applicants.is_temp_not_interested', false);
            //     break;
            // case 'inactive':
            //     $model->where('applicants.status', 0)
            //         ->where('applicants.is_no_job', false)
            //         ->where('applicants.is_blocked', false)
            //         ->where('applicants.is_temp_not_interested', false);
            //     break;
            case 'crm active':
                $model->whereHas('crmHistory')
                    ->where('applicants.is_blocked', false)
                    ->where('applicants.is_no_job', false)
                    ->where('applicants.is_circuit_busy', false)
                    ->where('applicants.is_temp_not_interested', false)
                    ->where(function ($q) {
                        $q->where('applicants.is_cv_in_quality_clear', 1)
                            ->orWhere('applicants.is_interview_confirm', 1)
                            ->orWhere('applicants.is_interview_attend', 1)
                            ->orWhere('applicants.is_in_crm_request', 1)
                            ->orWhere('applicants.is_crm_request_confirm', 1)
                            ->orWhere('applicants.is_crm_interview_attended', '<>', 0)
                            ->orWhere('applicants.is_in_crm_start_date', 1)
                            ->orWhere('applicants.is_in_crm_invoice', 1)
                            ->orWhere('applicants.is_in_crm_invoice_sent', 1)
                            ->orWhere('applicants.is_in_crm_start_date_hold', 1)
                            ->orWhere('applicants.is_in_crm_paid', 1);
                    });
                break;

            case 'blocked':
                $model->where('applicants.is_blocked', true)
                    ->where('applicants.is_no_job', false)
                    ->where('applicants.is_circuit_busy', false)
                    ->where('applicants.is_temp_not_interested', false);
                break;
            case 'circuit busy':
                $model->where('applicants.is_blocked', false)
                    ->where('applicants.is_no_job', false)
                    ->where('applicants.is_circuit_busy', true)
                    ->where('applicants.is_temp_not_interested', false);
                break;
            case 'not interested':
                $model->where('applicants.is_no_job', false)
                    ->where('applicants.is_blocked', false)
                    ->where('applicants.is_circuit_busy', false)
                    ->where('applicants.is_temp_not_interested', true);
                break;
            case 'no job':
                $model->where('applicants.is_blocked', false)
                    ->where('applicants.is_circuit_busy', false)
                    ->where('applicants.is_no_job', true)
                    ->where('applicants.is_temp_not_interested', false);
                break;
            default:
                $model->where('applicants.status', 1);
                break;
        }

        // Filter by type if it's not empty
        switch ($typeFilter) {
            case 'specialist':
                $model->where('applicants.job_type', 'specialist');
                break;
            case 'regular':
                $model->where('applicants.job_type', 'regular');
                break;
        }

        // Filter by type if it's not empty
        if ($categoryFilter) {
            $model->whereIn('applicants.job_category_id', $categoryFilter);
        }

        // Filter by type if it's not empty
        if ($titleFilters) {
            $model->whereIn('applicants.job_title_id', $titleFilters);
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn() // This will automatically add a serial number to the rows
                ->addColumn('job_title', function ($applicant) {
                    return $applicant->jobTitle ? strtoupper($applicant->jobTitle->name) : '-';
                })
                ->addColumn('job_category', function ($applicant) {
                    $type = $applicant->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    return $applicant->jobCategory ? $applicant->jobCategory->name . $stype : '-';
                })
                ->addColumn('job_source', function ($applicant) {
                    return $applicant->jobSource ? $applicant->jobSource->name : '-';
                })
                ->editColumn('applicant_name', function ($applicant) {
                    return $applicant->formatted_applicant_name; // Using accessor
                })
                ->editColumn('applicant_experience', function ($applicant) {
                    $short = Str::limit(strip_tags($applicant->applicant_experience), 80);
                    $full = e($applicant->applicant_experience);
                    $id = 'exp-' . $applicant->id;

                    return '
                        <a href="#" 
                        data-bs-toggle="modal" 
                        data-bs-target="#' . $id . '">
                            ' . $short . '
                        </a>

                        <!-- Modal -->
                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Applicant Experience</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . nl2br($full) . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ';
                })
                ->editColumn('applicant_email', function ($applicant) {
                    $email = '';
                    if ($applicant->applicant_email_secondary) {
                        $email = $applicant->is_blocked ? "<span class='badge bg-dark'>Blocked</span>" : $applicant->applicant_email . '<br>' . $applicant->applicant_email_secondary;
                    } else {
                        $email = $applicant->is_blocked ? "<span class='badge bg-dark'>Blocked</span>" : $applicant->applicant_email;
                    }

                    return $email; // Using accessor
                })
                ->editColumn('applicant_postcode', function ($applicant) {
                    if ($applicant->lat != null && $applicant->lng != null && !$applicant->is_blocked) {
                        $url = route('applicants.available_job', ['id' => $applicant->id, 'radius' => 15]);
                        $button = '<a href="' . $url . '" target="_blank" style="color:blue;">' . $applicant->formatted_postcode . '</a>'; // Using accessor
                    } else {
                        $button = $applicant->formatted_postcode;
                    }
                    return $button;
                })
                ->editColumn('applicant_notes', function ($applicant) {
                    // Convert new lines to <br> but DO NOT escape HTML tags
                    $notes = nl2br($applicant->applicant_notes);

                    $status_value = 'open';
                    if ($applicant->paid_status == 'close') {
                        $status_value = 'paid';
                    } else {
                        foreach ($applicant->cv_notes as $key => $value) {
                            if ($value->status == 1) {
                                $status_value = 'sent';
                                break;
                            } elseif ($value->status == 0) {
                                $status_value = 'reject';
                            }
                        }
                    }

                    if ($status_value == 'open' || $status_value == 'reject') {
                        return '
                            <a href="#" title="Add Short Note" style="color:blue"
                            onclick="addShortNotesModal(' . (int)$applicant->id . ')">
                                ' . $notes . '
                            </a>
                        ';
                    } else {
                        return $notes;
                    }
                })
                ->addColumn('applicantPhone', function ($applicant) {
                    $str = '';

                    if ($applicant->is_blocked) {
                        $str = "<span class='badge bg-dark'>Blocked</span>";
                    } else {
                        $str = '<strong>P:</strong> ' . $applicant->applicant_phone;

                        if ($applicant->applicant_phone_secondary) {
                            $str .= '<br><strong>P:</strong> ' . $applicant->applicant_phone_secondary;
                        }
                        if ($applicant->applicant_landline) {
                            $str .= '<br><strong>L:</strong> ' . $applicant->applicant_landline;
                        }
                    }

                    return $str;
                })
                // In your DataTable or controller
                ->filterColumn('applicantPhone', function ($query, $keyword) {
                    $clean = preg_replace('/[^0-9]/', '', $keyword); // remove spaces, dashes, etc.

                    $query->where(function ($q) use ($clean) {
                        $q->whereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_phone, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"])
                            ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(applicants.applicant_landline, " ", ""), "-", ""), "(", ""), ")", "") LIKE ?', ["%$clean%"]);
                    });
                })
                ->editColumn('created_at', function ($applicant) {
                    return $applicant->formatted_created_at; // Using accessor
                })
                ->editColumn('updated_at', function ($applicant) {
                    return $applicant->formatted_updated_at; // Using accessor
                })
                ->addColumn('applicant_resume', function ($applicant) {
                    $path = $applicant->applicant_cv;

                    // ✅ Only proceed if path begins with "uploads/"
                    if ($path && str_starts_with($path, 'uploads/')) {
                        // ✅ Check if file exists on public disk
                        if (!$applicant->is_blocked && Storage::disk('public')->exists($path)) {
                            // ✅ Correct URL (storage symlink points to storage/app/public)
                            $url = asset('storage/' . $path);

                            return '<a href="' . $url . '" title="Download CV" target="_blank" class="text-decoration-none">' .
                                '<iconify-icon icon="solar:download-square-bold" class="text-success fs-28"></iconify-icon></a>';
                        }
                    }

                    return '<button disabled title="CV Not Available" class="border-0 bg-transparent p-0">' .
                        '<iconify-icon icon="solar:download-square-bold" class="text-grey fs-28"></iconify-icon></button>';
                })
                ->addColumn('crm_resume', function ($applicant) {
                    $path = $applicant->updated_cv;

                    if ($path && str_starts_with($path, 'uploads/')) {
                        if (!$applicant->is_blocked && Storage::disk('public')->exists($path)) {

                            $url = asset('storage/' . $path);

                            return '<a href="' . $url . '" title="Download Updated CV" target="_blank" class="text-decoration-none">' .
                                '<iconify-icon icon="solar:download-square-bold" class="text-primary fs-28"></iconify-icon></a>';
                        }
                    }

                    return '<button disabled title="CV Not Available" class="border-0 bg-transparent p-0">' .
                        '<iconify-icon icon="solar:download-square-bold" class="text-grey fs-28"></iconify-icon></button>';
                })
                ->addColumn('customStatus', function ($applicant) {
                    $status = '';
                    if ($applicant->is_blocked == 1) {
                        $status = '<span class="badge bg-dark">Blocked</span>';
                    } elseif ($applicant->is_no_response == 1) {
                        $status = '<span class="badge bg-warning">No Response</span>';
                    } elseif ($applicant->is_circuit_busy == 1) {
                        $status = '<span class="badge bg-warning">Circuit Busy</span>';
                    } elseif ($applicant->is_no_job == 1) {
                        $status = '<span class="badge bg-warning">No Job</span>';
                    } elseif ($applicant->is_temp_not_interested == 1) {
                        $status = '<span class="badge bg-danger">Not<br>Interested</span>';
                    } elseif ($applicant->paid_status == 'open' && $applicant->is_in_crm_paid == 0) {
                        $status = '<span class="badge bg-primary">Open</span>';
                    } elseif ($applicant->paid_status == 'close' && $applicant->is_in_crm_paid == 1) {
                        $status = '<span class="badge bg-dark">CRM Paid</span>';
                    } elseif (
                        ($applicant->crmHistory && $applicant->crmHistory->count() > 0) &&
                        (
                            $applicant->is_cv_in_quality_clear == 1 ||
                            $applicant->is_interview_confirm == 1 ||
                            $applicant->is_interview_attend == 1 ||
                            $applicant->is_in_crm_request == 1 ||
                            $applicant->is_crm_request_confirm == 1 ||
                            $applicant->is_crm_interview_attended != 0 ||
                            $applicant->is_in_crm_start_date == 1 ||
                            $applicant->is_in_crm_invoice == 1 ||
                            $applicant->is_in_crm_invoice_sent == 1 ||
                            $applicant->is_in_crm_start_date_hold == 1 || 
                            $applicant->is_in_crm_paid == 0
                        )
                    ) {
                        $status = '<span class="badge bg-primary">CRM Active</span>';
                    } else {
                        $status = '-';
                    }

                    return $status;
                })
                ->addColumn('action', function ($applicant) {
                    $landline = $applicant->is_blocked ? '<span class="badge bg-dark">Blocked</span>' : $applicant->formatted_landline;
                    $phone = $applicant->is_blocked ? '<span class="badge bg-dark">Blocked</span>' : $applicant->formatted_phone;
                    $postcode = $applicant->formatted_postcode;
                    $job_title = $applicant->jobTitle ? strtoupper($applicant->jobTitle->name) : '-';
                    $job_category = $applicant->jobCategory ? ucwords($applicant->jobCategory->name) : '-';
                    $job_source = $applicant->jobSource ? ucwords($applicant->jobSource->name) : '-';
                    $emailstatus = $applicant->is_blocked ? '<span class="badge bg-dark">Blocked</span>' : $applicant->applicant_email;
                    $secondaryemailstatus = $applicant->is_blocked ? '<span class="badge bg-dark">Blocked</span>' : $applicant->applicant_email_secondary;
                    $status = '';

                    if ($applicant->is_blocked) {
                        $status = '<span class="badge bg-dark">Blocked</span>';
                    } elseif ($applicant->status) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($applicant->is_no_response) {
                        $status = '<span class="badge bg-danger">No Response</span>';
                    } elseif ($applicant->is_circuit_busy) {
                        $status = '<span class="badge bg-warning">Circuit Busy</span>';
                    } elseif ($applicant->is_no_job) {
                        $status = '<span class="badge bg-secondary">No Job</span>';
                    } else {
                        $status = '<span class="badge bg-secondary">Inactive</span>';
                    }

                    $html = '';
                    $html .= '<div class="btn-group dropstart">
                            <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                            </button>
                            <ul class="dropdown-menu">';
                    if (Gate::allows('applicant-edit')) {
                        $html .= '<li><a class="dropdown-item" href="' . route('applicants.edit', ['id' => (int)$applicant->id]) . '">Edit</a></li>';
                    }
                    if (Gate::allows('applicant-view')) {
                        $html .= '<li><a class="dropdown-item" href="#" onclick="showDetailsModal(
                                        ' . (int)$applicant->id . ',
                                        \'' . addslashes(htmlspecialchars($applicant->applicant_name)) . '\',
                                        \'' . addslashes(htmlspecialchars($emailstatus)) . '\',
                                        \'' . addslashes(htmlspecialchars($secondaryemailstatus)) . '\',
                                        \'' . addslashes(htmlspecialchars($postcode)) . '\',
                                        \'' . addslashes(htmlspecialchars($landline)) . '\',
                                        \'' . addslashes(htmlspecialchars($phone)) . '\',
                                        \'' . addslashes(htmlspecialchars($job_title)) . '\',
                                        \'' . addslashes(htmlspecialchars($job_category)) . '\',
                                        \'' . addslashes(htmlspecialchars($job_source)) . '\',
                                        \'' . addslashes(htmlspecialchars($status)) . '\'
                                    )">View</a></li>';
                    }
                    if (Gate::allows('applicant-add-note')) {
                        $html .= '<li><a class="dropdown-item" href="#" onclick="addNotesModal(' . (int)$applicant->id . ')">Add Note</a></li>';
                    }
                    if (Gate::allows('applicant-upload-resume')) {
                        $html .= '<li>
                                        <a class="dropdown-item" href="#" onclick="triggerFileInput(' . (int)$applicant->id . ')">Upload Applicant Resume</a>
                                        <!-- Hidden File Input -->
                                        <input type="file" id="fileInput" style="display:none" accept=".pdf,.doc,.docx" onchange="uploadFile()">
                                    </li>';
                    }
                    if (Gate::allows('applicant-upload-crm-resume')) {
                        $html .= '<li>
                                        <a class="dropdown-item" href="#" onclick="triggerCrmFileInput(' . (int)$applicant->id . ')">Upload CRM Resume</a>
                                        <!-- Hidden File Input -->
                                        <input type="file" id="crmfileInput" style="display:none" accept=".pdf,.doc,.docx" onchange="crmuploadFile()">
                                    </li>';
                    }
                    if (Gate::allows('applicant-view-history') || Gate::allows('applicant-view-notes-history')) {
                        $html .= '<li><hr class="dropdown-divider"></li>';
                    }

                    $html .= '<!-- <li><a class="dropdown-item" target="_blank" href="' . route('applicants.available_no_job', ['id' => (int)$applicant->id, 'radius' => 15]) . '">Go to No Job</a></li> -->';
                    if (Gate::allows('applicant-view-history')) {
                        $html .= '<li><a class="dropdown-item" target="_blank" href="' . route('applicants.history', ['id' => (int)$applicant->id]) . '">View History</a></li>';
                    }
                    if (Gate::allows('applicant-view-notes-history')) {
                        $html .= '<li><a class="dropdown-item" href="#" onclick="viewNotesHistory(' . (int)$applicant->id . ')">Notes History</a></li>';
                    }
                    $html .= '</ul>
                        </div>';

                    return $html;
                })
                ->rawColumns(['applicant_notes', 'applicantPhone', 'applicant_postcode', 'job_title', 'applicant_experience', 'applicant_email', 'applicant_resume', 'crm_resume', 'customStatus', 'job_category', 'job_source', 'action'])
                ->make(true);
        }
    }
    public function getJobTitlesByCategory(Request $request)
    {
        $jobTitles = JobTitle::where('job_category_id', $request->input('job_category_id'))
            ->where('type', $request->input('job_type'))->get();

        return response()->json($jobTitles);
    }
    public function storeShortNotes(Request $request)
    {
        $request->validate([
            'applicant_id' => 'required|integer|exists:applicants,id',
            'details' => 'required|string',
            'reason' => 'required|string',
        ]);

        $user = Auth::user();

        try {
            DB::beginTransaction();

            $applicant_id = $request->input('applicant_id');
            $details = $request->input('details');
            $notes_reason = $request->input('reason');
            $applicant_notes = $details . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y');

            $updateData = ['applicant_notes' => $applicant_notes];
            $movedTabTo = '';

            switch ($notes_reason) {
                case 'blocked':
                    Applicant::where('id', $applicant_id)->update(array_merge($updateData, [
                        'is_no_response' => false,
                        'is_blocked' => true,
                        'is_callback_enable' => false,
                        'is_temp_not_interested' => false,
                    ]));
                    $movedTabTo = 'blocked';
                    break;

                case 'casual':
                    Applicant::where('id', $applicant_id)->update(array_merge($updateData, [
                        'is_no_response' => false,
                        'is_blocked' => false,
                        'is_callback_enable' => false,
                        'is_temp_not_interested' => false,
                    ]));
                    $movedTabTo = 'casual';
                    break;

                case 'no_response':
                    Applicant::where('id', $applicant_id)->update(array_merge($updateData, [
                        'is_circuit_busy' => false,
                        'is_no_response' => true,
                        'is_callback_enable' => false,
                        'is_blocked' => false,
                        'is_temp_not_interested' => false,
                    ]));
                    $movedTabTo = 'no response';
                    break;

                case 'no_job':
                    Applicant::where('id', $applicant_id)->update(array_merge($updateData, [
                        'is_no_response' => false,
                        'is_callback_enable' => false,
                        'is_blocked' => false,
                        'is_no_job' => true,
                        'is_temp_not_interested' => false,
                    ]));
                    $movedTabTo = 'no job';
                    break;

                case 'circuit_busy':
                    Applicant::where('id', $applicant_id)->update(array_merge($updateData, [
                        'is_temp_not_interested' => false,
                        'is_callback_enable' => false,
                        'is_no_response' => false,
                        'is_circuit_busy' => true,
                        'is_blocked' => false,
                        'is_no_job' => false,
                    ]));
                    $movedTabTo = 'circuit busy';
                    break;

                case 'not_interested':
                    Applicant::where('id', $applicant_id)->update(array_merge($updateData, [
                        'is_temp_not_interested' => true,
                        'is_no_response' => false,
                        'is_callback_enable' => false,
                        'is_circuit_busy' => false,
                        'is_blocked' => false,
                        'is_no_job' => false,
                    ]));
                    $movedTabTo = 'not interested';
                    break;

                case 'callback':
                    Applicant::where('id', $applicant_id)->update(array_merge($updateData, [
                        'is_temp_not_interested' => false,
                        'is_callback_enable' => true,
                        'is_no_response' => false,
                        'is_circuit_busy' => false,
                        'is_blocked' => false,
                        'is_no_job' => false,
                    ]));
                    $movedTabTo = 'callback';
                    break;
            }

            // Save applicant note
            $applicantNote = ApplicantNote::create([
                'details' => $applicant_notes,
                'applicant_id' => $applicant_id,
                'moved_tab_to' => $movedTabTo,
                'user_id' => $user->id,
            ]);

            $applicantNote->update([
                'note_uid' => md5($applicantNote->id),
            ]);

            // Disable previous module notes
            ModuleNote::where([
                'module_noteable_id' => $applicant_id,
                'module_noteable_type' => 'Horsefly\Applicant',
            ])
                ->where('status', 1)
                ->update(['status' => 0]);

            // Add new module note
            $moduleNote = ModuleNote::create([
                'details' => $applicant_notes,
                'module_noteable_id' => $applicant_id,
                'module_noteable_type' => 'Horsefly\Applicant',
                'user_id' => $user->id,
            ]);

            $moduleNote->update([
                'module_note_uid' => md5($moduleNote->id),
            ]);

            // Log audit
            $applicant = Applicant::where('id', $applicant_id)
                ->select('applicant_name', 'applicant_notes', 'id')
                ->first();

            Log::info('Updated request for applicant', $applicant->toArray());

            $observer = new ActionObserver();
            $observer->customApplicantAudit($applicant, 'applicant_notes');

            DB::commit();

            return redirect()->to(url()->previous());
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to store notes: ' . $e->getMessage());

            return back()->with('error', 'Something went wrong while saving notes.');
        }
    }
    public function downloadCv($id)
    {
        $applicant = Applicant::findOrFail($id);
        $filePath = $applicant->cv_path;

        if (Storage::exists($filePath)) {
            return Storage::download($filePath);
        } else {
            return response()->json(['error' => 'File not found'], 404);
        }
    }
    public function edit($id)
    {
        // Debug the incoming id
        Log::info('Trying to edit applicant with ID: ' . $id);

        $applicant = Applicant::find($id);
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name', 'asc')->get();
        $jobSources = JobSource::where('is_active', 1)->orderBy('name', 'asc')->get();

        // Check if the applicant is found
        if (!$applicant) {
            Log::info('Applicant not found with ID: ' . $id);
        }

        return view('applicants.edit', compact('applicant', 'jobCategories', 'jobSources'));
    }
    public function history($id)
    {
        // Debug the incoming id
        Log::info('Trying to edit applicant with ID: ' . $id);

        $applicant = Applicant::find($id);
        $jobCategory = JobCategory::where('id', $applicant->job_category_id)->select('name')->first();
        $jobTitle = JobTitle::where('id', $applicant->job_title_id)->select('name')->first();
        $jobSource = JobSource::where('id', $applicant->job_source_id)->select('name')->first();
        $jobTypeStr = ucwords(str_replace('-', ' ', $applicant->job_type));
        $jobType = $jobTypeStr == 'Specialist' ? ' (' . $jobTypeStr . ')' : '';
        $postcode = ucwords($applicant->applicant_postcode);

        // Check if the applicant is found
        if (!$applicant) {
            Log::info('Applicant not found with ID: ' . $id);
        }

        return view('applicants.history', compact('applicant', 'jobCategory', 'jobTitle', 'jobSource', 'jobType', 'postcode'));
    }
    public function update(Request $request)
    {
        // Validate the incoming request
        $validator = Validator::make($request->all(), [
            'job_category_id' => 'required|exists:job_categories,id',
            'job_type' => ['required', Rule::in(['specialist', 'regular'])],
            'job_title_id' => 'required|exists:job_titles,id',
            'job_source_id' => 'required|exists:job_sources,id',
            'applicant_name' => 'required|string|max:255',
            'gender' => 'required',
            'applicant_email' => 'required|email|max:255|unique:applicants,applicant_email,' . $request->input('applicant_id'), // Exclude current applicant's email
            'applicant_email_secondary' => 'nullable|email|max:255|unique:applicants,applicant_email_secondary,' . $request->input('applicant_id'),
            'applicant_postcode' => ['required', 'string', 'min:2', 'max:8', 'regex:/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d ]+$/'],
            // 'applicant_landline' => 'nullable|string|max:11|unique:applicants,applicant_landline,' . $request->input('applicant_id'),
            'applicant_phone' => [
                'required',
                'string',
                'max:11',
                Rule::unique('applicants', 'applicant_phone')->ignore($request->input('applicant_id')),
                Rule::unique('applicants', 'applicant_phone_secondary')->ignore($request->input('applicant_id')),
                Rule::unique('applicants', 'applicant_landline')->ignore($request->input('applicant_id')),
            ],
            'applicant_phone_secondary' => [
                'nullable',
                'string',
                'max:11',
                Rule::unique('applicants', 'applicant_phone')->ignore($request->input('applicant_id')),
                Rule::unique('applicants', 'applicant_phone_secondary')->ignore($request->input('applicant_id')),
                Rule::unique('applicants', 'applicant_landline')->ignore($request->input('applicant_id')),
            ],
            'applicant_landline' => [
                'nullable',
                'string',
                'max:11',
                function ($attribute, $value, $fail) use ($request) {
                    if ($value) {
                        $exists = Applicant::where('applicant_landline', $value)
                            ->when($request->input('applicant_id'), function ($q) use ($request) {
                                $q->where('id', '!=', $request->input('applicant_id'));
                            })
                            ->exists();
                        if ($exists) {
                            $fail('This landline already exists.');
                        }
                    }
                },
            ],
            'applicant_experience' => 'nullable|string',
            'applicant_notes' => 'required|string|max:255',
            'applicant_cv' => 'nullable|mimes:docx,doc,csv,pdf,txt|max:5000', // 5mb
        ]);

        // Add conditionally required validation
        $validator->sometimes('have_nursing_home_experience', 'required|boolean', function ($input) {
            $nurseCategory = JobCategory::where('name', 'nurse')->first();
            return $nurseCategory && $input->job_category_id == $nurseCategory->id;
        });

        // If validation fails, return with errors
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Prepare the data to update
            $applicantData = $request->only([
                'job_category_id',
                'job_type',
                'job_title_id',
                'job_source_id',
                'applicant_name',
                'applicant_email',
                'applicant_email_secondary',
                'applicant_postcode',
                'applicant_phone',
                'applicant_phone_secondary',
                'applicant_landline',
                'applicant_experience',
                'applicant_notes',
                'have_nursing_home_experience',
                'gender'
            ]);

            // Handle file upload if a CV is provided
            $path = null;
            if ($request->hasFile('applicant_cv')) {
                // 🧹 Delete the old CV file if it exists
                if (!empty($applicantData['applicant_cv']) && Storage::disk('public')->exists($applicantData['applicant_cv'])) {
                    Storage::disk('public')->delete($applicantData['applicant_cv']);
                }

                // 📅 Build dynamic directory path based on current date
                $year = now()->year;
                $month = now()->format('m');
                $day = now()->format('d');

                $directory = "uploads/resume/{$year}/{$month}/{$day}";

                // 🧾 Get original filename and extension
                $filenameWithExt = $request->file('applicant_cv')->getClientOriginalName();
                $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                $extension = $request->file('applicant_cv')->getClientOriginalExtension();

                // 🔤 Replace all whitespaces (including multiple) with underscores
                $filename = preg_replace('/\s+/', '_', trim($filename));

                // 🕒 Generate unique filename
                $fileNameToStore = $filename . '_' . time() . '.' . $extension;

                // 💾 Store the file in the "public" disk under the dynamic path
                $path = $request->file('applicant_cv')->storeAs($directory, $fileNameToStore, 'public');

                // ✅ Save the new file path in the data array
                $applicantData['applicant_cv'] = $path;
            }

            // Sanitize emails (trim spaces and lowercase)
            $applicantData['applicant_email'] = isset($applicantData['applicant_email'])
                ? strtolower(trim($applicantData['applicant_email']))
                : null;

            $applicantData['applicant_email_secondary'] = isset($applicantData['applicant_email_secondary'])
                ? strtolower(trim($applicantData['applicant_email_secondary']))
                : null;

            // Get the applicant ID from the request
            $id = $request->input('applicant_id');

            // Retrieve the applicant record
            $applicant = Applicant::find($id);

            // If the applicant doesn't exist, throw an exception
            if (!$applicant) {
                throw new Exception("Applicant not found with ID: " . $id);
            }

            $phones = array_filter([
                $applicant->applicant_phone,
                $applicant->applicant_phone_secondary,
            ]);

            if (!empty($phones)) {
                Message::where(function ($q) use ($phones) {
                        foreach ($phones as $phone) {
                            $q->orWhere('phone_number', $phone); // exact match preferred
                        }
                    })
                    ->update([
                        'module_id'   => $applicant->id,
                        'module_type' => Applicant::class,
                    ]);
            }

            $landline = trim((string) $request->input('applicant_landline'));

            // Treat 0, empty, or invalid values as null
            if ($landline == '' || $landline == '0') {
                $applicantData['applicant_landline'] = null;
            }

            $applicantData['applicant_notes'] = $applicant_notes = $request->applicant_notes . ' --- By: ' . Auth::user()->name . ' Date: ' . Carbon::now()->format('d-m-Y');

            $postcode = $request->applicant_postcode;

            if ($postcode != $applicant->applicant_postcode) {
                $postcode_query = strlen($postcode) < 6
                    ? DB::table('outcodepostcodes')->where('outcode', $postcode)->first()
                    : DB::table('postcodes')->where('postcode', $postcode)->first();

                if (!$postcode_query) {
                    try {
                        $result = $this->geocode($postcode);

                        // If geocode fails, throw
                        if (!isset($result['lat']) || !isset($result['lng'])) {
                            throw new Exception('Geolocation failed. Latitude and longitude not found.');
                        }

                        $applicantData['lat'] = $result['lat'];
                        $applicantData['lng'] = $result['lng'];
                    } catch (Exception $e) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Unable to locate address: ' . $e->getMessage()
                        ], 400);
                    }
                } else {
                    $applicantData['lat'] = $postcode_query->lat;
                    $applicantData['lng'] = $postcode_query->lng;
                }
            }

            // Update the applicant with the validated and formatted data
            $applicant->update($applicantData);

            ModuleNote::where([
                'module_noteable_id' => $id,
                'module_noteable_type' => 'Horsefly\Applicant'
            ])
                ->where('status', 1)
                ->update(['status' => 0]);

            $moduleNote = ModuleNote::create([
                'details' => $applicant_notes,
                'module_noteable_id' => $applicant->id,
                'module_noteable_type' => 'Horsefly\Applicant',
                'user_id' => Auth::id()
            ]);

            $moduleNote->update([
                'module_note_uid' => md5($moduleNote->id)
            ]);

            DB::commit();

            // Redirect to the applicants page with a success message
            return response()->json([
                'success' => true,
                'message' => 'Applicant updated successfully',
                'redirect' => route('applicants.list')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function destroy($id)
    {
        $applicant = Applicant::findOrFail($id);
        $applicant->delete();
        return redirect()->route('applicants.list')->with('success', 'Applicant deleted successfully');
    }
    public function show($id)
    {
        $applicant = Applicant::findOrFail($id);
        return view('applicants.show', compact('applicant'));
    }
    public function uploadCv(Request $request)
    {
        // Validate the request
        // $validator = Validator::make($request->all(), [
        //     'resume' => 'required|file|mimes:pdf,doc,docx,txt|max:10240',
        //     'applicant_id' => 'required|integer|exists:applicants,id',
        // ]);

        // if ($validator->fails()) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => $validator->errors()->first(),
        //         'errors'  => $validator->errors(),
        //     ], 422);
        // }
        // Get file and applicant data
        $file = $request->file('resume');
        $applicantId = $request->input('applicant_id');

        // Fetch applicant
        $applicant = Applicant::findOrFail($applicantId);

        // ✅ Delete old CV file if it exists
        if (!empty($applicant->applicant_cv) && Storage::disk('public')->exists($applicant->applicant_cv)) {
            Storage::disk('public')->delete($applicant->applicant_cv);
        }

        // Generate directory structure based on current date
        $year = now()->year;
        $month = now()->month;
        $day = now()->day;

        // Create storage path
        $directory = "uploads/resume/{$year}/{$month}/{$day}";
        $storagePath = "public/{$directory}";

        // Ensure directory exists
        if (!Storage::exists($storagePath)) {
            Storage::makeDirectory($storagePath, 0755, true); // recursive creation
        }

        // Generate unique filename
        $fileName = $applicantId . '_' . now()->timestamp . '.' . $file->getClientOriginalExtension();

        // Store the file
        $filePath = $file->storeAs($directory, $fileName, 'public');

        // Update applicant record
        $applicant->update(['applicant_cv' => $filePath]);

        // Return response
        return response()->json([
            'success' => true,
            'message' => 'File uploaded successfully',
            'file_path' => $filePath,
            'file_url' => Storage::url($filePath),
        ]);
    }
    public function crmuploadCv(Request $request)
    {
        // Validate the request
        $request->validate([
            'resume' => 'required|file|mimes:pdf,doc,docx,txt|max:10240',
            'applicant_id' => 'required|integer|exists:applicants,id',
        ]);

        $file = $request->file('resume');
        $applicantId = $request->input('applicant_id');

        // Retrieve applicant
        $applicant = Applicant::findOrFail($applicantId);

        // ✅ Delete old "updated_cv" file if it exists
        if (!empty($applicant->updated_cv) && Storage::disk('public')->exists($applicant->updated_cv)) {
            Storage::disk('public')->delete($applicant->updated_cv);
        }

        // Generate directory structure based on current date
        $year = now()->year;
        $month = now()->month;
        $day = now()->day;

        // Create storage path
        $directory = "uploads/resume/{$year}/{$month}/{$day}";
        $storagePath = "public/{$directory}";

        // Ensure directory exists
        if (!Storage::exists($storagePath)) {
            Storage::makeDirectory($storagePath, 0755, true);
        }

        // Generate unique filename
        $fileName = $applicantId . '_' . now()->timestamp . '.' . $file->getClientOriginalExtension();

        // Store file in 'public' disk
        $filePath = $file->storeAs($directory, $fileName, 'public');

        // Update applicant record with new "updated_cv" path
        $applicant->update(['updated_cv' => $filePath]);

        // Return response
        return response()->json([
            'success' => true,
            'message' => 'File uploaded successfully',
            'file_path' => $filePath,
            'file_url' => Storage::url($filePath),
        ]);
    }
    public function export(Request $request)
    {
        $type = $request->query('type', 'all'); // Default to 'all' if not provided
        $radius = $request->query('radius', null); // Default to 0 if not provided
        $model_type = $request->query('model_type', null);
        $model_id = $request->query('model_id', null);

        if ($radius != null) {
            $sale = Sale::find($model_id);
            $fileName = "applicants_within_{$radius}km_of_sale_{$sale->sale_postcode}.csv";
        } else {
            $fileName = "applicants_{$type}.csv";
        }

        return Excel::download(new ApplicantsExport($type, $radius, $model_type, $model_id), $fileName);
    }
    public function changeStatus(Request $request)
    {
        $user = Auth::user();

        $applicant_id = $request->input('applicant_id');
        $status = $request->input('status');
        $details = $request->input('details');
        $notes = $details . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y');

        $updateData = [
            'applicant_notes' => $notes,
            'status' => $status,
        ];

        Applicant::where('id', $applicant_id)->update($updateData);

        // Disable previous module note
        ModuleNote::where([
            'module_noteable_id' => $applicant_id,
            'module_noteable_type' => 'Horsefly\Applicant'
        ])
            ->orderBy('id', 'desc')
            ->update(['status' => 0]);

        // Create new module note
        $moduleNote = ModuleNote::create([
            'details' => $notes,
            'module_noteable_id' => $applicant_id,
            'module_noteable_type' => 'Horsefly\Applicant',
            'user_id' => $user->id
        ]);

        $moduleNote->update([
            'module_note_uid' => md5($moduleNote->id)
        ]);

        return redirect()->to(url()->previous());
    }
    public function getApplicantHistoryAjaxRequest(Request $request)
    {
        // Prepare CRM Notes query
        $id = $request->applicant_id;

        $model = Applicant::query();

        // Subquery: get latest CRM note per applicant-sale
        $latestCrmNotes = DB::table('crm_notes')
            ->select('id', 'applicant_id', 'sale_id', 'details', 'created_at')
            ->whereIn('id', function ($query) {
                $query->selectRaw('MAX(id)')
                    ->from('crm_notes')
                    ->groupBy('applicant_id', 'sale_id');
            });

        // Join with the latest CRM notes
        $model->joinSub($latestCrmNotes, 'crm_notes', function ($join) {
            $join->on('crm_notes.applicant_id', '=', 'applicants.id');
        })
            ->join('sales', 'sales.id', '=', 'crm_notes.sale_id')
            ->join('offices', 'offices.id', '=', 'sales.office_id')
            ->join('units', 'units.id', '=', 'sales.unit_id')
            ->join('history', function ($join) {
                $join->on('crm_notes.applicant_id', '=', 'history.applicant_id')
                    ->on('crm_notes.sale_id', '=', 'history.sale_id');
            })
            ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
            ->select([
                'applicants.id as app_id',
                'applicants.applicant_name',

                'crm_notes.id as crm_notes_id',
                'crm_notes.details as crm_note_details',
                'crm_notes.created_at as crm_notes_created_at',

                'sales.id as sale_id',
                'sales.sale_postcode',
                'sales.is_on_hold',
                'sales.status as sale_status',
                'sales.job_type as sale_job_type',
                'sales.position_type',
                'sales.experience as sale_experience',
                'sales.qualification as sale_qualification',
                'sales.salary',
                'sales.timing',
                'sales.created_at as sale_posted_date',
                'sales.benefits',

                'history.sub_stage as history_sub_stage',
                'history.created_at as history_created_at',

                'offices.office_name',
                'units.unit_name',

                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
            ])
            ->where([
                'applicants.id' => $id,
                'history.status' => 1
            ]);

        /*** Sorting */
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $direction = $request->input('order.0.dir', 'asc');

            switch ($orderColumn) {

                case 'job_category':
                    $model->orderBy('job_category_name', $direction);
                    break;

                case 'job_title':
                    $model->orderBy('job_title_name', $direction);
                    break;

                case 'crm_note_details':
                    $model->orderBy('crm_note_details', $direction);
                    break;

                case 'history_sub_stage':
                    $model->orderBy('history_sub_stage', $direction);
                    break;

                case 'sale_postcode':
                    $model->orderBy('sale_postcode', $direction);
                    break;

                case 'crm_notes_created_at':
                    $model->orderBy('crm_notes_created_at', $direction);
                    break;

                case 'history_created_at':
                    $model->orderBy('history.created_at', $direction);
                    break;

                default:
                    if ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                        $model->orderBy($orderColumn, $direction);
                    } else {
                        $model->orderBy('history_created_at', 'desc');
                    }
            }
        } else {
            $model->orderBy('history_created_at', 'desc');
        }

        /*** search */
        if ($request->has('search.value')) {
            $search = $request->input('search.value');

            $model->where(function ($q) use ($search) {
                $q->where('history.sub_stage', 'LIKE', "%{$search}%")
                    ->orWhere('history.created_at', 'LIKE', "%{$search}%")
                    ->orWhere('crm_notes.details', 'LIKE', "%{$search}%")
                    ->orWhere('sale_postcode', 'LIKE', "%{$search}%")
                    ->orWhere('job_titles.name', 'LIKE', "%{$search}%")
                    ->orWhere('job_categories.name', 'LIKE', "%{$search}%")
                    ->orWhere('office_name', 'LIKE', "%{$search}%")
                    ->orWhere('unit_name', 'LIKE', "%{$search}%");
            });
        }

        // Handle AJAX request
        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn()
                ->addColumn('history_created_at', function ($row) {
                    return Carbon::parse($row->history_created_at)->format('d M Y, h:i A');
                })
                ->addColumn('job_title', function ($row) {
                    return $row->job_title_name ? strtoupper($row->job_title_name) : '-';
                })
                ->addColumn('sub_stage', function ($row) {
                    return '<span class="badge bg-primary">' . ucwords(str_replace('_', ' ', $row->history_sub_stage)) . '</span>';
                })
                ->addColumn('details', function ($row) {
                    $short = Str::limit(strip_tags($row->crm_note_details), 100);
                    $full  = e($row->crm_note_details);

                    // Use NOTE ID from your query
                    $id = 'note-' . $row->crm_notes_id;

                    $html = '
                        <a href="#" class="text-primary" 
                        data-bs-toggle="modal" 
                        data-bs-target="#' . $id . '">
                        ' . $short . '
                        </a>

                        <!-- Modal -->
                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Notes</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . nl2br($full) . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ';

                    return $html;
                })
                ->addColumn('job_details', function ($row) {
                    $position_type = strtoupper(str_replace('-', ' ', $row->position_type));
                    $position = '<span class="badge bg-primary">' . htmlspecialchars($position_type, ENT_QUOTES) . '</span>';

                    if ($row->sale_status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($row->sale_status == 0 && $row->is_on_hold == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($row->sale_status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($row->sale_status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

                    // Escape HTML in $status for JavaScript (to prevent XSS)
                    $escapedStatus = htmlspecialchars($status, ENT_QUOTES);

                    // Prepare modal HTML for the "Job Details"
                    $modalHtml = $this->generateJobDetailsModal($row);

                    // Return the action link with a modal trigger and the modal HTML
                    return '<a href="#" class="dropdown-item" style="color: blue;" onclick="showDetailsModal('
                        . (int)$row->sale_id . ','
                        . '\'' . htmlspecialchars(Carbon::parse($row->sale_posted_date)->format('d M Y, h:i A'), ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars($row->office_name, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars($row->unit_name, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars($row->sale_postcode, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars($row->job_category_name, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars($row->job_title_name, ENT_QUOTES) . '\','
                        . '\'' . $escapedStatus . '\','
                        . '\'' . htmlspecialchars($row->timing, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars($row->sale_experience, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars($row->salary, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars($position, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars($row->sale_qualification, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars($row->benefits, ENT_QUOTES) . '\')">
                        <iconify-icon icon="solar:square-arrow-right-up-bold" class="text-info fs-24"></iconify-icon>
                        </a>' . $modalHtml;
                })
                ->addColumn('job_category', function ($row) {
                    $type = $row->sale_job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    return $row->job_category_name ? $row->job_category_name . $stype : '-';
                })
                ->addColumn('action', function ($row) {
                    // Tooltip content with additional data-bs-placement and title
                    return '<a href="#" title="View All Notes" onclick="viewNotesHistory(\'' . (int)$row->app_id . '\',\'' . (int)$row->sale_id . '\')">
                                <iconify-icon icon="solar:clipboard-text-bold" class="text-info fs-24"></iconify-icon>
                            </a>';
                })
                ->rawColumns(['history_created_at', 'details', 'job_category', 'job_title', 'job_details', 'action', 'sub_stage'])
                ->make(true);
        }
    }
    private function generateJobDetailsModal($data)
    {
        $modalId = 'jobDetailsModal_' . $data->sale_id;  // Unique modal ID for each applicant's job details

        return '<div class="modal fade" id="' . $modalId . '" tabindex="-1" aria-labelledby="' . $modalId . 'Label" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-top modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="' . $modalId . 'Label">Job Details</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body modal-body-text-left">
                                <!-- Job details content will be dynamically inserted here -->
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>';
    }
    public function sendCVtoQuality(Request $request)
    {
        try {
            $input = $request->all();
            $request->replace($input);

            $user = Auth::user();

            $validator = Validator::make($request->all(), [
                'applicant_id' => "required|integer|exists:applicants,id",
                'sale_id'      => "required|integer|exists:sales,id",
                'details'      => "required",
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors'  => $validator->errors(),
                    'message' => 'Please fix the errors in the form.'
                ], 422);
            }

            // 🔹 Begin database transaction
            DB::beginTransaction();

            try {
                $details = $request->input('details');

                $applicant = Applicant::findOrFail($request->input('applicant_id'));
                $sale = Sale::findOrFail($request->input('sale_id'));

                // ✅ Check if job titles match
                if ($applicant->job_title_id != $sale->job_title_id) {
                    throw new Exception("CV can't be sent - job titles don't match.");
                }

                // 🔹 Handle special conditions
                $noteDetail = '';
                if ($request->boolean('hangup_call')) {
                    $noteDetail .= $this->handleHangupCall($request, $user, $applicant, $sale, $details);
                } elseif ($request->boolean('no_job')) {
                    $noteDetail .= $this->handleNoJob($request, $user, $applicant);
                } else {
                    $noteDetail .= $this->handleRegularSubmission($request, $user);
                }

                $noteDetail .= $details . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y');

                // ✅ Check CV limits
                $sent_cv_count = CVNote::where([
                    'sale_id' => $sale->id,
                    'status'  => 1
                ])->count();

                $open_cv_count = History::where([
                    'sale_id'   => $sale->id,
                    'status'    => 1,
                    'sub_stage' => 'quality_cvs_hold'
                ])->count();

                $net_sent_cv_count = $sent_cv_count - $open_cv_count;

                if ($net_sent_cv_count >= $sale->cv_limit) {
                    throw new Exception("Sorry, you can't send more CVs for this job. The maximum CV limit has been reached.");
                }

                // ✅ Check if applicant is rejected
                if ($this->checkIfApplicantRejected($applicant, $sale->id)) {
                    throw new Exception("This applicant's CV can't be sent.");
                }

                // 🔹 Update applicant and create related records
                $applicant->update(['is_cv_in_quality' => true]);

                $cv_note = CVNote::create([
                    'sale_id'      => $sale->id,
                    'user_id'      => $user->id,
                    'applicant_id' => $applicant->id,
                    'details'      => $noteDetail,
                ]);

                $cv_note->update(['cv_uid' => md5($cv_note->id)]);

                History::where('applicant_id', $applicant->id)->update(['status' => 0]);

                $history = History::create([
                    'sale_id'      => $sale->id,
                    'applicant_id' => $applicant->id,
                    'user_id'      => $user->id,
                    'stage'        => 'quality',
                    'sub_stage'    => 'quality_cvs',
                ]);

                $history->update(['history_uid' => md5($history->id)]);

                // 🔹 Commit transaction if all went fine
                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'CV successfully sent to quality.'
                ]);
            } catch (Exception $e) {
                // ❌ Rollback the transaction if something fails inside
                DB::rollBack();

                Log::error('Transaction failed in sendCVtoQuality', [
                    'error' => $e->getMessage(),
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                    'input' => $request->all(),
                ]);

                // Return actual error in debug mode
                $debug = config('app.debug');

                return response()->json([
                    'success' => false,
                    'message' => $debug ? $e->getMessage() : 'An error occurred while sending CV to quality.',
                    'file'    => $debug ? $e->getFile() : null,
                    'line'    => $debug ? $e->getLine() : null,
                ], 500);
            }
        } catch (ModelNotFoundException $e) {
            // Handles missing applicant or sale
            return response()->json([
                'success' => false,
                'message' => 'Record not found: ' . $e->getMessage()
            ], 404);
        } catch (Exception $e) {
            // Handles any other outer exception
            Log::error('Outer error in sendCVtoQuality', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            $debug = config('app.debug');

            return response()->json([
                'success' => false,
                'message' => $debug ? $e->getMessage() : 'Unexpected error occurred.',
                'file'    => $debug ? $e->getFile() : null,
                'line'    => $debug ? $e->getLine() : null,
            ], 500);
        }
    }

    // Helper methods
    private function handleHangupCall($request, $user, $applicant, $sale, $notes)
    {
        $noteDetail = '<strong>Date:</strong> ' . Carbon::now()->format('d-m-Y') . '<br>';
        $noteDetail .= '<strong>Call Hung up/Not Interested:</strong> Yes<br>';
        $noteDetail .= '<strong>Details:</strong> ' . nl2br(htmlspecialchars($request->input('details'))) . '<br>';
        $noteDetail .= '<strong>By:</strong> ' . $user->name . '<br>';

        $applicant->update([
            'is_temp_not_interested' => true,
            'is_no_job' => false
        ]);

        $pivotSale = ApplicantPivotSale::create([
            'applicant_id' => $applicant->id,
            'sale_id' => $sale->id,
            'pivot_uid' => null
        ]);
        $pivotSale->update(['pivot_uid' => md5($pivotSale->id)]);

        $notes_for_range = NotesForRangeApplicant::create([
            'applicants_pivot_sales_id' => $pivotSale->id,
            'reason' => $notes,
            'range_uid' => null
        ]);
        $notes_for_range->update(['range_uid' => $notes_for_range->id]);

        return $noteDetail;
    }
    private function handleNoJob($request, $user, $applicant)
    {
        $noteDetail = '<strong>Date:</strong> ' . Carbon::now()->format('d-m-Y') . '<br>';
        $noteDetail .= '<strong>No Job:</strong> Yes<br>';
        $noteDetail .= '<strong>Details:</strong> ' . nl2br(htmlspecialchars($request->input('details'))) . '<br>';
        $noteDetail .= '<strong>By:</strong> ' . $user->name . '<br>';

        $applicant->update([
            'is_no_response' => false,
            'is_temp_not_interested' => false,
            'is_blocked' => false,
            'is_circuit_busy' => false,
            'is_no_job' => true,
            'applicant_notes' => $noteDetail,
            'updated_at' => Carbon::now()
        ]);

        return $noteDetail;
    }
    private function handleRegularSubmission($request, $user)
    {
        $transportType = $request->has('transport_type') ? implode(', ', $request->input('transport_type')) : '';
        $shiftPattern = $request->has('shift_pattern') ? implode(', ', $request->input('shift_pattern')) : '';

        $noteDetail = '<strong>Date:</strong> ' . Carbon::now()->format('d-m-Y') . '<br>';
        $noteDetail .= '<strong>Current Employer Name:</strong> ' . htmlspecialchars($request->input('current_employer_name')) . '<br>';
        $noteDetail .= '<strong>PostCode:</strong> ' . htmlspecialchars($request->input('postcode')) . '<br>';
        $noteDetail .= '<strong>Current/Expected Salary:</strong> ' . htmlspecialchars($request->input('expected_salary')) . '<br>';
        $noteDetail .= '<strong>Qualification:</strong> ' . htmlspecialchars($request->input('qualification')) . '<br>';
        $noteDetail .= '<strong>Transport Type:</strong> ' . htmlspecialchars($transportType) . '<br>';
        $noteDetail .= '<strong>Shift Pattern:</strong> ' . htmlspecialchars($shiftPattern) . '<br>';
        $noteDetail .= '<strong>Nursing Home:</strong> ' . ($request->has('nursing_home') && $request->input('nursing_home') == 'on' ? 'Yes' : 'No') . '<br>';
        $noteDetail .= '<strong>Alternate Weekend:</strong> ' . ($request->has('alternate_weekend') && $request->input('alternate_weekend') == 'on' ? 'Yes' : 'No') . '<br>';
        $noteDetail .= '<strong>Interview Availability:</strong> ' . ($request->has('interview_availability') && $request->input('interview_availability') == 'on' ? 'Available' : 'Not Available') . '<br>';
        $noteDetail .= '<strong>No Job:</strong> ' . ($request->input('no_job') && $request->input('no_job') == 'on' ? 'Yes' : 'No') . '<br>';
        $noteDetail .= '<strong>Details:</strong> ' . nl2br(htmlspecialchars($request->input('details'))) . '<br>';
        $noteDetail .= '<strong>By:</strong> ' . $user->name . '<br>';

        return $noteDetail;
    }
    // private function checkIfApplicantRejected($applicant)
    // {
    //     return Applicant::join('quality_notes', 'applicants.id', '=', 'quality_notes.applicant_id')
    //         ->where(function ($query) {
    //             $query->where('applicants.is_in_crm_reject', true)
    //                 ->orWhere('applicants.is_in_crm_request_reject', true)
    //                 ->orWhere('applicants.is_crm_interview_attended', false)
    //                 ->orWhere('applicants.is_in_crm_start_date_hold', true)
    //                 ->orWhere('applicants.is_in_crm_dispute', true)
    //                 ->orWhere(function ($q) {
    //                     $q->where('applicants.is_cv_in_quality_reject', true)
    //                         ->where('quality_notes.moved_tab_to', 'rejected');
    //                 });
    //         })
    //         ->where('applicants.status', 1)
    //         ->where('applicants.id', $applicant->id)
    //         ->exists();
    // }
    private function checkIfApplicantRejected($applicant, $sale_id)
    {
        return DB::table('quality_notes')
            ->where('applicant_id', $applicant->id)
            ->where('sale_id', $sale_id)
            ->where('moved_tab_to', 'rejected')
            ->exists();
    }
    public function markApplicantNoNursingHome(Request $request)
    {
        $user = Auth::user();

        try {
            $applicant_id = $request->input('applicant_id');
            $sale_id = $request->input('sale_id');
            $details = $request->input('details');
            $notes = $details . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y');

            // Deactivate previous similar notes
            ApplicantNote::where('applicant_id', $applicant_id)
                ->whereIn('moved_tab_to', ['no_nursing_home', 'revert_no_nursing_home'])
                ->where('status', 1)
                ->update(['status' => 0]);

            // Create new note
            $applicant_note = ApplicantNote::create([
                'user_id' => $user->id,
                'applicant_id' => $applicant_id,
                'details' => $notes,
                'moved_tab_to' => 'no_nursing_home'
            ]);

            $applicant_note->update([
                'note_uid' => md5($applicant_note->id)
            ]);

            // Update applicant status
            $applicant = Applicant::where('id', $applicant_id)->first();

            $applicant->update(['is_in_nurse_home' => true]);

            return response()->json([
                'success' => true,
                'message' => 'Marked as no nursing home experience successfully!',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error marking applicant as no nursing home: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong! Please try again.',
            ], 500);
        }
    }
    public function availableJobsIndex($applicant_id, $radius = null)
    {
        $applicant = Applicant::find($applicant_id);
        $jobCategory = JobCategory::where('id', $applicant->job_category_id)->select('name')->first();
        $jobTitle = JobTitle::where('id', $applicant->job_title_id)->select('name')->first();
        $jobSource = JobSource::where('id', $applicant->job_source_id)->select('name')->first();
        $jobType = ucwords(str_replace('-', ' ', $applicant->job_type));
        $jobType = $jobType == 'Specialist' ? ' (' . $jobType . ')' : '';

        // Convert radius to miles if provided in kilometers (1 km ≈ 0.621371 miles)
        $radiusInMiles = round($radius * 0.621371, 1);

        return view('applicants.available-jobs', compact('applicant', 'jobCategory', 'jobTitle', 'jobSource', 'radius', 'radiusInMiles', 'jobType'));
    }
    public function availableNoJobsIndex($applicant_id, $radius = null)
    {
        $applicant = Applicant::find($applicant_id);
        $jobCategory = JobCategory::where('id', $applicant->job_category_id)->select('name')->first();
        $jobTitle = JobTitle::where('id', $applicant->job_title_id)->select('name')->first();
        $jobSource = JobSource::where('id', $applicant->job_source_id)->select('name')->first();
        $jobType = ucwords(str_replace('-', ' ', $applicant->job_type));
        $jobType = $jobType == 'Specialist' ? ' (' . $jobType . ')' : '';

        // Convert radius to miles if provided in kilometers (1 km ≈ 0.621371 miles)
        $radiusInMiles = round($radius * 0.621371, 1);

        return view('applicants.available-no-jobs', compact('applicant', 'jobCategory', 'jobTitle', 'jobSource', 'radius', 'radiusInMiles', 'jobType'));
    }
    public function getAvailableJobs(Request $request)
    {
        $statusFilter = $request->input('status_filter', '');
        $applicant_id = $request->input('applicant_id');
        $radius       = $request->input('radius');

        $applicant = Applicant::with('cv_notes')->findOrFail($applicant_id);

        $lat = $applicant->lat;
        $lon = $applicant->lng;

        $model = Sale::query()
            ->select([
                'sales.*',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'offices.office_name as office_name',
                'units.unit_name as unit_name',
                'users.name as user_name',

                DB::raw("((ACOS(SIN($lat * PI() / 180) * SIN(sales.lat * PI() / 180) + 
                        COS($lat * PI() / 180) * COS(sales.lat * PI() / 180) * COS(($lon - sales.lng) * PI() / 180)) * 180 / PI()) * 60 * 1.1515) AS distance"),

                DB::raw("(SELECT COUNT(*) FROM cv_notes WHERE cv_notes.sale_id = sales.id AND cv_notes.status = 1) AS no_of_sent_cv"),

                // ADD THESE — fields from latest sale note
                'updated_notes.id as latest_note_id',
                'updated_notes.sale_note as latest_note',
                'updated_notes.created_at as latest_note_time',

                'cv_notes.status as cv_notes_status'
            ])
            ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
            ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
            ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
            ->leftJoin('users', 'sales.user_id', '=', 'users.id')
            ->whereNotExists(function ($query) use ($applicant_id) {
                $query->select(DB::raw(1))
                    ->from('applicants_pivot_sales')
                    ->whereColumn('applicants_pivot_sales.sale_id', 'sales.id')
                    ->where('applicants_pivot_sales.applicant_id', $applicant_id);
            })
            ->leftJoin('cv_notes', function ($join) use ($applicant_id) {
                $join->on('cv_notes.sale_id', '=', 'sales.id')
                    ->where('cv_notes.applicant_id', $applicant_id);
            })
            // Subquery to get latest sale_note id per sale
            ->leftJoin(DB::raw("
                (SELECT sale_id, MAX(id) AS latest_id
                FROM sale_notes
                GROUP BY sale_id) AS latest_notes
            "), 'sales.id', '=', 'latest_notes.sale_id')

            // Join the actual sale_notes record
            ->leftJoin('sale_notes AS updated_notes', 'updated_notes.id', '=', 'latest_notes.latest_id')

            ->where('sales.status', 1)
            ->having("distance", "<", $radius)
            ->orderBy("distance");

        /** 🔹 Job Title Filtering */
        $jobTitle = JobTitle::find($applicant->job_title_id);

        $relatedTitles = is_array($jobTitle->related_titles)
            ? $jobTitle->related_titles
            : json_decode($jobTitle->related_titles ?? '[]', true);

        $titles = collect($relatedTitles)
            ->map(fn($item) => strtolower(trim($item)))
            ->push(strtolower(trim($jobTitle->name)))
            ->unique()
            ->values()
            ->toArray();

        $jobTitleIds = JobTitle::whereIn(DB::raw('LOWER(name)'), $titles)->pluck('id')->toArray();

        $model->whereIn('sales.job_title_id', $jobTitleIds);

        /** 🔹 Search */
        if ($request->has('search.value')) {
            $searchTerm = strtolower(trim((string) $request->input('search.value')));

            if (!empty($searchTerm)) {
                $likeSearch = "%{$searchTerm}%";

                $model->where(function ($query) use ($likeSearch, $searchTerm) {
                    $query->whereRaw('LOWER(sales.sale_postcode) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.experience) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.timing) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.job_description) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.job_type) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.position_type) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.cv_limit) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.salary) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.benefits) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(sales.qualification) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(job_titles.name) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(job_categories.name) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(units.unit_name) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(offices.office_name) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(users.name) LIKE ?', [$likeSearch]);
                });
            }
        }

        // Filter by status if it's not empty
        $statusMap = [
            'sent' => 1,
            'reject job' => 0,
            'paid' => 2,
            'open' => 3,
        ];

        $statusValue = $statusMap[strtolower($statusFilter)] ?? null;

        if ($statusValue !== null) {
            $model->where('cv_notes.status', $statusValue);
        }

        /** 🔹 Sorting */
        if ($request->has('order')) {
            $orderColumn    = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            if ($orderColumn === 'job_source') {
                $model->orderBy('sales.job_source_id', $orderDirection);
            } elseif ($orderColumn === 'job_category') {
                $model->orderBy('sales.job_category_id', $orderDirection);
            } elseif ($orderColumn === 'job_title') {
                $model->orderBy('sales.job_title_id', $orderDirection);
            } elseif ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $model->orderBy($orderColumn, $orderDirection);
            } else {
                $model->orderBy('sales.updated_at', 'desc');
            }
        } else {
            $model->orderBy('sales.updated_at', 'desc');
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn() // This will automatically add a serial number to the rows
                ->addColumn('office_name', function ($sale) {
                    $office_id = $sale->office_id;
                    $office = Office::find($office_id);
                    return $office ? $office->office_name : '-';
                })
                ->addColumn('unit_name', function ($sale) {
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    return $unit ? $unit->unit_name : '-';
                })
                ->addColumn('cv_limit', function ($sale) {
                    $status = $sale->no_of_sent_cv == $sale->cv_limit ? '<span class="badge w-100 bg-danger" style="font-size:90%" >' . $sale->no_of_sent_cv . '/' . $sale->cv_limit . '<br>Limit Reached</span>' : "<span class='badge w-100 bg-primary' style='font-size:90%'>" . ((int)$sale->cv_limit - (int)$sale->no_of_sent_cv . '/' . (int)$sale->cv_limit) . "<br>Limit Remains</span>";
                    return $status;
                })
                ->addColumn('job_title', function ($sale) {
                    return $sale->jobTitle ? $sale->jobTitle->name : '-';
                })
                ->addColumn('open_date', function ($sale) {
                    return $sale->open_date ? Carbon::parse($sale->open_date)->format('d M Y, h:i A') : '-'; // Using accessor
                })
                ->addColumn('job_category', function ($sale) {
                    $type = $sale->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    return $sale->jobCategory ? $sale->jobCategory->name . $stype : '-';
                })
                ->addColumn('experience', function ($sale) {
                    $short = Str::limit(strip_tags($sale->experience), 80);
                    $full = e($sale->experience);
                    $id = 'exp-' . $sale->id;

                    return '
                        <a href="#" 
                        data-bs-toggle="modal" 
                        data-bs-target="#' . $id . '">
                            ' . $short . '
                        </a>

                        <!-- Modal -->
                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Sale Experience</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . nl2br($full) . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ';
                })
                ->addColumn('qualification', function ($sale) {
                    $short = Str::limit(strip_tags($sale->qualification), 80);
                    $full = e($sale->qualification);
                    $id = 'qalf-' . $sale->id;

                    return '
                        <a href="#" 
                        data-bs-toggle="modal" 
                        data-bs-target="#' . $id . '">
                            ' . $short . '
                        </a>

                        <!-- Modal -->
                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Sale Qualification</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . nl2br($full) . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ';
                })
                ->addColumn('salary', function ($sale) {
                    $short = Str::limit(strip_tags($sale->salary), 80);
                    $full = e($sale->salary);
                    $id = 'slry-' . $sale->id;

                    return '
                        <a href="#" 
                        data-bs-toggle="modal" 
                        data-bs-target="#' . $id . '">
                            ' . $short . '
                        </a>

                        <!-- Modal -->
                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Sale Salary</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . nl2br($full) . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ';
                })
                ->addColumn('sale_postcode', function ($sale) {
                    return $sale->formatted_postcode; // Using accessor
                })
                ->addColumn('created_at', function ($sale) {
                    return $sale->formatted_created_at; // Using accessor
                })
                ->addColumn('updated_at', function ($sale) {
                    return $sale->formatted_updated_at; // Using accessor
                })
                ->addColumn('sale_notes', function ($sale) {
                    $notes = '';
                    if (!empty($sale->sale_notes)) {
                        $notes = $sale->sale_notes;
                    } else {
                        $notes = $sale->latest_note;
                    }
                    $short = Str::limit(strip_tags($notes), 80);
                    $full = e($notes);
                    $id = 'notes-' . $sale->id;

                    return '
                        <a href="#" class="text-primary" 
                        data-bs-toggle="modal" 
                        data-bs-target="#' . $id . '">
                            ' . $short . '
                        </a>

                        <!-- Modal -->
                        <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="' . $id . '-label">Notes</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        ' . nl2br($full) . '
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ';
                })
                ->addColumn('status', function ($sale)  use ($applicant) {
                    $status_value = 'Open';
                    /***if cv_notes status is 3 then it will be apply on that too***/
                    $status_clr = 'bg-dark';
                    foreach ($applicant->cv_notes as $key => $value) {
                        if ($value->sale_id == $sale->id) {
                            if ($value->status == 1) {
                                $status_value = 'Sent';
                                $status_clr = 'bg-success';
                                break;
                            } elseif ($value->status == 0) {
                                $status_value = 'Reject Job';
                                $status_clr = 'bg-danger';
                                break;
                            } elseif ($value->status == 2) {
                                $status_value = 'Paid';
                                $status_clr = 'bg-success';
                                break;
                            }
                        }
                    }

                    return '<span class="badge ' . $status_clr . '">' . $status_value . '</span>';
                })
                ->addColumn('action', function ($sale) use ($applicant) {
                    $status_value = 'open';
                    foreach ($applicant->cv_notes as $key => $value) {
                        if ($value->sale_id == $sale->id) {
                            if ($value->status == 1) {
                                $status_value = 'sent';
                                break;
                            } elseif ($value->status == 0) {
                                $status_value = 'reject_job';
                                break;
                            } elseif ($value->status == 2) {
                                $status_value = 'paid';
                                break;
                            }
                        }
                    }

                    $html = '<div class="btn-group dropstart">
                            <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                            </button>
                            <ul class="dropdown-menu">';
                    if ($status_value == 'open') {
                        $html .= '<li><a href="#" onclick="markNotInterestedModal(' . $applicant->id . ', ' . $sale->id . ')" 
                                                        class="dropdown-item">
                                                        Mark Not Interested On Sale
                                                    </a></li>';
                        if ($applicant->is_in_nurse_home == false) {
                            $html .= '<li><a href="#" class="dropdown-item" onclick="markNoNursingHomeModal(' . $applicant->id . ')">
                                                        Mark No Nursing Home</a></li>';
                        }

                        $html .= '<li><a href="#" onclick="sendCVModal(' . $applicant->id . ', ' . $sale->id . ')" class="dropdown-item" >
                                                    <span>Send CV</span></a></li>';

                        if ($applicant->is_callback_enable == false) {
                            $html .= '<li><a href="#" class="dropdown-item"  onclick="markApplicantCallbackModal(' . $applicant->id . ', ' . $sale->id . ')">Mark Callback</a></li>';
                        }
                    } elseif ($status_value == 'sent' || $status_value == 'reject_job' || $status_value == 'paid') {
                        $html .= '<button type="button" class="btn btn-light btn-sm disabled d-inline-flex align-items-center">
                                    <iconify-icon icon="solar:lock-bold" class="fs-14 me-1"></iconify-icon> Locked
                                </button>';
                    }

                    $html .= '</ul>
                        </div>';

                    return $html;
                })
                ->rawColumns(['sale_notes', 'paid_status', 'experience', 'qualification', 'salary', 'cv_limit', 'job_title', 'open_date', 'job_category', 'office_name', 'unit_name', 'status', 'action', 'statusFilter'])
                ->make(true);
        }
    }
    public function getAvailableNoJobs(Request $request)
    {
        $statusFilter = $request->input('status_filter', ''); // Default is empty (no filter)
        $typeFilter = $request->input('type_filter', ''); // Default is empty (no filter)
        $categoryFilter = $request->input('category_filter', ''); // Default is empty (no filter)
        $titleFilter = $request->input('title_filter', ''); // Default is empty (no filter)
        $limitCountFilter = $request->input('cv_limit_filter', ''); // Default is empty (no filter)
        $officeFilter = $request->input('office_filter', ''); // Default is empty (no filter)
        $applicant_id = $request->input('applicant_id'); // Default is empty (no filter)
        $radius = $request->input('radius'); // Default is empty (no filter)

        $applicant = Applicant::with('cv_notes')->find($applicant_id);

        if ($applicant->lat == null || $applicant->lng == null) {
            return response()->json([
                'data' => [],
                'draw' => 0,
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
            ]);
        }
        $lat = (float) $applicant->lat;
        $lon = (float) $applicant->lng;

        $model = Sale::query()
            ->select([
                'sales.*',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'offices.office_name as office_name',
                'units.unit_name as unit_name',
                'users.name as user_name',
                DB::raw("((ACOS(SIN($lat * PI() / 180) * SIN(sales.lat * PI() / 180) + 
                        COS($lat * PI() / 180) * COS(sales.lat * PI() / 180) * COS(($lon - sales.lng) * PI() / 180)) * 180 / PI()) * 60 * 1.1515) 
                        AS distance"),
                DB::raw("(SELECT COUNT(*) FROM cv_notes WHERE cv_notes.sale_id = sales.id AND cv_notes.status = 1) as no_of_sent_cv"),

                // ADD THESE — fields from latest sale note
                'updated_notes.id as latest_note_id',
                'updated_notes.sale_note as latest_note',
                'updated_notes.created_at as latest_note_time',

                'cv_notes.status as cv_notes_status'
            ])
            ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
            ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
            ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
            ->leftJoin('users', 'sales.user_id', '=', 'users.id')
            ->having('distance', '<', $radius)
            ->orderBy('distance')
            ->where('sales.status', 1) // Only active sales
            ->whereNotExists(function ($query) use ($applicant_id) {
                $query->select(DB::raw(1))
                    ->from('applicants_pivot_sales')
                    ->whereColumn('applicants_pivot_sales.sale_id', 'sales.id')
                    ->where('applicants_pivot_sales.applicant_id', $applicant_id);
            })
            ->leftJoin('cv_notes', function ($join) use ($applicant_id) {
                $join->on('cv_notes.sale_id', '=', 'sales.id')
                    ->where('cv_notes.applicant_id', $applicant_id);
            })
            // Subquery to get latest sale_note id per sale
            ->leftJoin(DB::raw("
                (SELECT sale_id, MAX(id) AS latest_id
                FROM sale_notes
                GROUP BY sale_id) AS latest_notes
            "), 'sales.id', '=', 'latest_notes.sale_id')

            // Join the actual sale_notes record
            ->leftJoin('sale_notes AS updated_notes', 'updated_notes.id', '=', 'latest_notes.latest_id')
            ->with(['jobTitle', 'jobCategory', 'unit', 'office', 'user']);

        $jobTitle = JobTitle::find($applicant->job_title_id);

        // Decode related_titles safely and normalize
        $relatedTitles = is_array($jobTitle->related_titles)
            ? $jobTitle->related_titles
            : json_decode($jobTitle->related_titles ?? '[]', true);

        // Make sure it's an array, lowercase all, and add main title
        $titles = collect($relatedTitles)
            ->map(fn($item) => strtolower(trim($item)))
            ->push(strtolower(trim($jobTitle->name)))
            ->unique()
            ->values()
            ->toArray();

        $jobTitleIds = JobTitle::whereIn(DB::raw('LOWER(name)'), $titles)->pluck('id')->toArray();

        $model->whereIn('sales.job_title_id', $jobTitleIds);

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

                    $query->orWhereHas('office', function ($q) use ($likeSearch) {
                        $q->where('offices.office_name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('user', function ($q) use ($likeSearch) {
                        $q->where('users.name', 'LIKE', "%{$likeSearch}%");
                    });
                });
            }
        }

        // Filter by status if it's not empty
        switch ($statusFilter) {
            case 'active':
                $model->where('sales.status', 1);
                break;
            case 'closed':
                $model->where('sales.status', 0)
                    ->where('sales.is_on_hold', 0);
                break;
            case 'pending':
                $model->where('sales.status', 2);
                break;
            case 'rejected':
                $model->where('sales.status', 3);
                break;
            case 'on hold':
                $model->where('sales.is_on_hold', true);
                break;
        }

        // Filter by type if it's not empty
        switch ($typeFilter) {
            case 'specialist':
                $model->where('sales.job_type', 'specialist');
                break;
            case 'regular':
                $model->where('sales.job_type', 'regular');
                break;
        }

        // Filter by category if it's not empty
        if ($officeFilter) {
            $model->where('sales.office_id', $officeFilter);
        }

        // Filter by category if it's not empty
        if ($limitCountFilter) {
            $model->where('sales.cv_limit', $limitCountFilter);
        }

        // Filter by category if it's not empty
        if ($categoryFilter) {
            $model->where('sales.job_category_id', $categoryFilter);
        }

        // Filter by category if it's not empty
        if ($titleFilter) {
            $model->where('sales.job_title_id', $titleFilter);
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
                    return $office ? $office->office_name : '-';
                })
                ->addColumn('unit_name', function ($sale) {
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    return $unit ? $unit->unit_name : '-';
                })
                ->addColumn('cv_limit', function ($sale) {
                    $status = $sale->no_of_sent_cv == $sale->cv_limit ? '<span class="badge w-100 bg-danger" style="font-size:90%" >' . $sale->no_of_sent_cv . '/' . $sale->cv_limit . '<br>Limit Reached</span>' : "<span class='badge w-100 bg-primary' style='font-size:90%'>" . ((int)$sale->cv_limit - (int)$sale->no_of_sent_cv . '/' . (int)$sale->cv_limit) . "<br>Limit Remains</span>";
                    return $status;
                })
                ->addColumn('job_title', function ($sale) {
                    return $sale->jobTitle ? $sale->jobTitle->name : '-';
                })
                ->addColumn('open_date', function ($sale) {
                    return $sale->open_date ? Carbon::parse($sale->open_date)->format('d M Y, h:i A') : '-'; // Using accessor
                })
                ->addColumn('job_category', function ($sale) {
                    $type = $sale->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    return $sale->jobCategory ? $sale->jobCategory->name . $stype : '-';
                })
                ->addColumn('sale_postcode', function ($sale) {
                    return $sale->formatted_postcode; // Using accessor
                })
                ->addColumn('created_at', function ($sale) {
                    return $sale->formatted_created_at; // Using accessor
                })
                ->addColumn('updated_at', function ($sale) {
                    return $sale->formatted_updated_at; // Using accessor
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
                ->addColumn('sale_notes', function ($sale) {
                    $notesData = '';
                    if (!empty($sale->sale_notes)) {
                        $notesData = $sale->sale_notes;
                    } else {
                        $notesData = $sale->latest_note;
                    }

                    $notes = nl2br(htmlspecialchars($notesData, ENT_QUOTES, 'UTF-8'));
                    $notes = $notes ? $notes : 'N/A';

                    $shortNotes = Str::limit(trim($notes), 80);
                    $postcode = htmlspecialchars($sale->sale_postcode, ENT_QUOTES, 'UTF-8');
                    $office = Office::find($sale->office_id);
                    $office_name = $office ? ucwords($office->office_name) : '-';
                    $unit = Unit::find($sale->unit_id);
                    $unit_name = $unit ? ucwords($unit->unit_name) : '-';

                    // Tooltip content with additional data-bs-placement and title
                    return '<a href="#" title="View Note" onclick="showNotesModal(\'' . (int)$sale->id . '\',\'' . $notes . '\', \'' . $office_name . '\', \'' . $unit_name . '\', \'' . $postcode . '\')">
                               ' . $shortNotes . '
                            </a>';
                })
                ->addColumn('status', function ($sale) {
                    $status = '';
                    if ($sale->status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 0 && $sale->is_on_hold == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

                    return $status;
                })
                ->addColumn('paid_status', function ($sale) use ($applicant) {
                    $status_value = 'open';
                    $color_class = 'bg-dark';
                    foreach ($applicant->cv_notes as $key => $value) {
                        if ($value['status'] == 1) { //active
                            $status_value = 'sent';
                            $color_class = 'bg-success';
                            break;
                        } elseif (($value['status'] == 0) && ($value['sale_id'] == $sale->id)) { //disable or rejected
                            $status_value = 'reject_job';
                            $color_class = 'bg-danger';
                            break;
                        } elseif (($value['status'] == 2) && //2 for paid
                            ($value['sale_id'] == $sale->id) &&
                            ($applicant->paid_status == 'paid')
                        ) {
                            $status_value = 'paid';
                            $color_class = 'bg-primary';
                            break;
                        } elseif (($value['status'] == 3) && //3 for open
                            ($value['sale_id'] == $sale->id) &&
                            ($applicant->paid_status == 'open')
                        ) {
                            $status_value = 'open';
                            $color_class = 'bg-dark';
                            break;
                        }
                    }
                    $status = '';
                    $status .= '<span class="badge ' . $color_class . '">';
                    $status .= ucwords($status_value);
                    $status .= '</span>';

                    return $status;
                })
                ->addColumn('action', function ($sale) use ($applicant) {
                    $status_value = 'open';
                    foreach ($applicant->cv_notes as $key => $value) {
                        if ($value['status'] == 1) { //active
                            $status_value = 'sent';
                            break;
                        } elseif ($value['status'] == 0) { //disable or rejected
                            $status_value = 'reject_job';
                        } elseif ($value['status'] == 2) { //paid
                            $status_value = 'paid';
                            break;
                        }
                    }

                    $html = '<div class="btn-group dropstart">
                            <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                            </button>
                            <ul class="dropdown-menu">';
                    if ($status_value == 'open') {
                        $html .= '<li><a href="#" onclick="markNotInterestedModal(' . $applicant->id . ', ' . $sale->id . ')" 
                                                        class="dropdown-item">
                                                        Mark Not Interested On Sale
                                                    </a></li>';
                        if ($applicant->is_in_nurse_home == false) {
                            $html .= '<li><a href="#" class="dropdown-item" onclick="markNoNursingHomeModal(' . $applicant->id . ')">
                                                        Mark No Nursing Home</a></li>';
                        }

                        $html .= '<li><a href="#" onclick="sendCVModal(' . $applicant->id . ', ' . $sale->id . ')" class="dropdown-item" >
                                                    <span>Send CV</span></a></li>';

                        if ($applicant->is_callback_enable == false) {
                            $html .= '<li><a href="#" class="dropdown-item"  onclick="markApplicantCallbackModal(' . $applicant->id . ', ' . $sale->id . ')">Mark Callback</a></li>';
                        }
                    } elseif ($status_value == 'sent' || $status_value == 'reject_job' || $status_value == 'paid') {
                        $html .= '<button type="button" class="btn btn-light btn-sm disabled d-inline-flex align-items-center">
                                    <iconify-icon icon="solar:lock-bold" class="fs-14 me-1"></iconify-icon> Locked
                                </button>';
                    }

                    $html .= '</ul>
                        </div>';

                    return $html;
                })
                ->rawColumns(['sale_notes', 'paid_status', 'experience', 'qualification', 'salary', 'cv_limit', 'job_title', 'open_date', 'job_category', 'office_name', 'unit_name', 'status', 'action', 'statusFilter'])
                ->make(true);
        }
    }
    public function getApplicanCallbackNotes(Request $request)
    {
        try {
            // Validate the incoming request to ensure 'id' is provided and is a valid integer
            $request->validate([
                'id' => 'required',  // Assuming 'module_notes' is the table name and 'id' is the primary key
            ]);

            // Fetch the module notes by the given ID
            $applicant_notes = ApplicantNote::whereIn('moved_tab_to', ['callback', 'revert_callback'])
                ->where('applicant_id', $request->id)
                ->orderBy('id', 'desc')
                ->get();

            // Check if the module note was found
            if (!$applicant_notes) {
                return response()->json(['error' => 'Applicant callback notes not found'], 404);  // Return 404 if not found
            }

            // Return the specific fields you need (e.g., applicant name, notes, etc.)
            return response()->json([
                'data' => $applicant_notes,
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
    public function getApplicantNoNursingHomeNotes(Request $request)
    {
        try {
            // Validate the incoming request to ensure 'id' is provided and is a valid integer
            $request->validate([
                'id' => 'required',  // Assuming 'module_notes' is the table name and 'id' is the primary key
            ]);

            // Fetch the module notes by the given ID
            $applicant_notes = ApplicantNote::whereIn('moved_tab_to', ['no_nursing_home', 'revert_no_nursing_home'])
                ->where('applicant_id', $request->id)
                ->orderBy('id', 'desc')
                ->get();

            // Check if the module note was found
            if (!$applicant_notes) {
                return response()->json(['error' => 'Applicant notes not found'], 404);  // Return 404 if not found
            }

            // Return the specific fields you need (e.g., applicant name, notes, etc.)
            return response()->json([
                'data' => $applicant_notes,
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
