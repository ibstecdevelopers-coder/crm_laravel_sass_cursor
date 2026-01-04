<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Horsefly\Unit;
use Horsefly\Office;
use Horsefly\User;
use Horsefly\Sale;
use Horsefly\CvNote;
use Horsefly\SaleNote;
use Horsefly\Applicant;
use Horsefly\JobCategory;
use Horsefly\JobTitle;
use Horsefly\SaleDocument;
use Horsefly\ModuleNote;
use App\Observers\ActionObserver;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Exports\SalesExport;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;
use App\Traits\Geocode;
use Illuminate\Support\Str;
use League\Csv\Reader;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Gate;

class SaleController extends Controller
{
    use Geocode;

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
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name','asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name','asc')->get();
        $offices = Office::where('status', 1)->orderBy('office_name','asc')->get();
        $users = User::where('is_active', 1)->orderBy('name','asc')->get();

        return view('sales.list', compact('jobCategories', 'jobTitles', 'offices', 'users'));
    }
    public function directSaleIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name','asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name','asc')->get();
        $offices = Office::where('status', 1)->orderBy('office_name','asc')->get();
        $users = User::where('is_active', 1)->orderBy('name','asc')->get();

        return view('sales.direct', compact('jobCategories', 'jobTitles', 'offices', 'users'));
    }
    public function openSaleIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name','asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name','asc')->get();
        $offices = Office::where('status', 1)->orderBy('office_name','asc')->get();
        $users = User::where('is_active', 1)->orderBy('name','asc')->get();

        return view('sales.open', compact('jobCategories', 'jobTitles', 'offices', 'users'));
    }
    public function fetchApplicantsWithinSaleRadiusIndex($id, $radius = null)
    {
        $radius = $radius ?: 15; // Default radius to 15 kilometers if not provided

        $sale = Sale::FindOrfail($id);   
        $office = Office::where('id', $sale->office_id)->select('office_name')->first();
        $unit = Unit::where('id', $sale->unit_id)->select('unit_name')->first();
        $jobCategory = JobCategory::where('id', $sale->job_category_id)->select('name')->first();
        $jobTitle = JobTitle::where('id', $sale->job_title_id)->select('name')->first();
        $jobType = ucwords(str_replace('-', ' ', $sale->job_type));
        $jobType = $jobType == 'Specialist' ? ' (' . $jobType . ')' : '';
        // Convert radius to miles if provided in kilometers (1 km ≈ 0.621371 miles)
        $radiusInMiles = round($radius * 0.621371, 1);

        return view('sales.fetch-applicants-by-radius', compact('sale', 'radiusInMiles','jobCategory', 'jobTitle', 'jobType', 'office', 'unit', 'radius'));
    }
    public function rejectedSaleIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name','asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name','asc')->get();
        $offices = Office::where('status', 1)->orderBy('office_name','asc')->get();
        $users = User::where('is_active', 1)->orderBy('name','asc')->get();

        return view('sales.rejected', compact('jobCategories', 'jobTitles', 'offices', 'users'));
    }
    public function closeSaleIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name','asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name','asc')->get();
        $offices = Office::where('status', 1)->orderBy('office_name','asc')->get();
        $users = User::where('is_active', 1)->orderBy('name','asc')->get();
        
        return view('sales.closed', compact('jobCategories', 'jobTitles', 'offices', 'users'));
    }
    public function onHoldSaleIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name','asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name','asc')->get();
        $offices = Office::where('status', 1)->orderBy('office_name','asc')->get();
        $users = User::where('is_active', 1)->orderBy('name','asc')->get();

        return view('sales.on-hold', compact('jobCategories', 'jobTitles', 'offices', 'users'));
    }
    public function pendingOnHoldSaleIndex()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name','asc')->get();
        $jobTitles = JobTitle::where('is_active', 1)->orderBy('name','asc')->get();
        $offices = Office::where('status', 1)->orderBy('office_name','asc')->get();
        $users = User::where('is_active', 1)->orderBy('name','asc')->get();

        return view('sales.pending-on-hold', compact('jobCategories', 'jobTitles', 'offices', 'users'));
    }
    public function create()
    {
        $offices = Office::where('status', 1)->select('id','office_name')->orderBy('office_name', 'asc')->get();
        $units = Unit::where('status', 1)->select('id','unit_name')->get();

        $jobCategories = JobCategory::where('is_active', 1)->get();
        $jobTitles = JobTitle::where('is_active', 1)->get();

        return view('sales.create', compact('offices', 'units', 'jobCategories', 'jobTitles'));
    }
    public function store(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'office_id' => 'required',
            'unit_id' => 'required',
            'sale_postcode' => [
                'required',
                'string',
                'min:3',
                'max:8',
                'regex:/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d ]+$/',
                Rule::unique('sales')->where(function ($q) use ($request) {
                    return $q->where('office_id', $request->office_id)
                        ->where('unit_id', $request->unit_id)
                        ->where('sale_postcode', $request->sale_postcode)
                        ->where('job_category_id', $request->job_category_id)
                        ->where('job_title_id', $request->job_title_id)
                        ->where('status',1);
                }),
            ],
            'job_category_id' => 'required',
            'job_title_id' => 'required',
            'job_type' => 'required',
            'position_type' => 'required',
            'cv_limit' => 'required',
            'timing' => 'required',
            'experience' => 'required',
            'salary' => 'required',
            'benefits' => 'required',
            'qualification' => 'required',
            'sale_notes' => 'required',
            'job_description' => 'nullable|string',
            'attachments.*' => 'file|mimes:pdf,doc,docx,csv|max:5120', // max 5MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        $user = Auth::user();

        try {
            // Get office data
            $saleData = $request->only([
                'office_id',
                'unit_id',
                'job_category_id',
                'job_title_id',
                'job_type',
                'position_type',
                'sale_postcode',
                'cv_limit',
                'timing',
                'experience',
                'salary',
                'benefits',
                'qualification',
                'sale_notes',
                'job_description',
            ]);

            $postcode = $request->sale_postcode;
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

                    $saleData['lat'] = $result['lat'];
                    $saleData['lng'] = $result['lng'];
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unable to locate address: ' . $e->getMessage()
                    ], 400);
                }
            } else {
                $saleData['lat'] = $postcode_query->lat;
                $saleData['lng'] = $postcode_query->lng;
            }

            $sale_add_note = $request->input('sale_notes') . ' --- By: ' . $user->name . ' Date: ' . Carbon::now()->format('d-m-Y') . '  Time: ' . Carbon::now()->format("h:iA");

            // Format data for office
            $saleData['user_id'] = $user->id;
            $saleData['sale_note'] = $sale_add_note;
            $sale = Sale::create($saleData);

            $sale_note = SaleNote::create([
                'sale_id' => $sale->id,
                'user_id' => $user->id,
                'sale_note' => $sale_add_note,
            ]);

            // Generate UID
            $sale->update(['sale_uid' => md5($sale->id)]);
            $sale_note->update(['sales_notes_uid' => md5($sale_note->id)]);

            // Create new module note
            $moduleNote = ModuleNote::create([
                'details' => $sale_add_note,
                'module_noteable_id' => $sale->id,
                'module_noteable_type' => 'Horsefly\Sale',
                'user_id' => Auth::id()
            ]);

            $moduleNote->update([
                'module_note_uid' => md5($moduleNote->id)
            ]);

            // Handle attachments if provided
            if ($request->hasFile('attachments')) {
                $attachments = $request->file('attachments');

                foreach ($attachments as $attachment) {
                    // Get the original filename
                    $filenameWithExt = $attachment->getClientOriginalName();
                    $size = $attachment->getSize();

                    // Get just the filename without extension
                    $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);

                    // Get just the extension
                    $extension = $attachment->getClientOriginalExtension();

                    // Create a new filename with timestamp
                    $fileNameToStore = $filename . '_' . time() . '.' . $extension;

                    // Upload file to public/uploads directory
                    $path = $attachment->storeAs('uploads/docs/', $fileNameToStore, 'public');

                    // Save document details in sale_documents table
                    SaleDocument::create([
                        'sale_id' => $sale->id,
                        'document_name' => $fileNameToStore,
                        'document_path' => $path,
                        'document_extension' => $extension,
                        'document_size' => $size
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Sale created successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating sale: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'An error occurred while creating the sale. Please try again.'
            ], 500);
        }
    }
    public function edit($id)
    {
        $offices = Office::where('status', 1)->select('id','office_name')->get();
        $jobCategories = JobCategory::where('is_active', 1)->get();
        $jobTitles = JobTitle::where('is_active', 1)->get();

        $sale = Sale::with('documents')->find($id);
        return view('sales.edit', compact('sale', 'offices', 'jobCategories', 'jobTitles'));
    }
    public function update(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'office_id' => ['required'],
            'unit_id' => ['required'],
            'sale_postcode' => [
                'required',
                'string',
                'min:3',
                'max:8',
                'regex:/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d ]+$/',
                Rule::unique('sales')->where(function ($q) use ($request) {
                    return $q->where('office_id', $request->office_id)
                        ->where('unit_id', $request->unit_id)
                        ->where('sale_postcode', $request->sale_postcode)
                        ->where('job_category_id', $request->job_category_id)
                        ->where('job_title_id', $request->job_title_id)->where('status',1);
                })->ignore($request->sale_id),
            ],
            'job_category_id' => 'required',
            'job_title_id' => 'required',
            'job_type' => 'required',
            'position_type' => 'required',
            'cv_limit' => 'required',
            'timing' => 'required',
            'experience' => 'required',
            'salary' => 'required',
            'benefits' => 'required',
            'qualification' => 'required',
            'sale_notes' => 'required',
            'job_description' => 'nullable',
            'attachments.*' => 'file|mimes:pdf,doc,docx,csv|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        $user = Auth::user();

        try {
            // Get office data
            $saleData = $request->only([
                'office_id',
                'unit_id',
                'job_category_id',
                'job_title_id',
                'job_type',
                'position_type',
                'sale_postcode',
                'cv_limit',
                'timing',
                'experience',
                'salary',
                'benefits',
                'qualification',
                'sale_notes',
                'job_description',
            ]);

            $id = $request->input('sale_id');

            // Check for existing sale with the same critical fields (e.g., office_id, unit_id, sale_postcode, job_title_id)
            // $exists = Sale::where('office_id', $saleData['office_id'])
            //     ->where('unit_id', $saleData['unit_id'])
            //     ->where('sale_postcode', $saleData['sale_postcode'])
            //     ->where('job_category_id', $saleData['job_category_id'])
            //     ->where('job_title_id', $saleData['job_title_id'])
            //     ->when($id, fn($q) => $q->where('id', '!=', $id)) // safely exclude current sale
            //     ->first();

            // if ($exists) {
            //     return response()->json([
            //         'success' => false,
            //         'message' => 'A sale with the same details already exists.'
            //     ], 409);
            // }

            // Retrieve the office record
            $sale = Sale::find($id);

            // If the applicant doesn't exist, throw an exception
            if (!$sale) {
                throw new \Exception("Sale not found with ID: " . $id);
            }

            $postcode = $request->sale_postcode;
            if ($postcode != $sale->sale_postcode) {
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

                        $saleData['lat'] = $result['lat'];
                        $saleData['lng'] = $result['lng'];
                    } catch (\Exception $e) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Unable to locate address: ' . $e->getMessage()
                        ], 400);
                    }
                } else {
                    $saleData['lat'] = $postcode_query->lat;
                    $saleData['lng'] = $postcode_query->lng;
                }
            }

            $sale_add_note = $request->input('sale_notes') . ' --- By: ' . $user->name . ' Date: ' . Carbon::now()->format('d-m-Y') . '  Time: ' . Carbon::now()->format("h:iA");

            $saleData['sale_notes'] = $sale_add_note;
            // Update the applicant with the validated and formatted data
            $sale->update($saleData);

            ModuleNote::where([
                'module_noteable_id' => $id,
                'module_noteable_type' => 'Horsefly\Sale'
            ])
                ->where('status', 1)
                ->update(['status' => 0]);

            $moduleNote = ModuleNote::create([
                'details' => $sale_add_note,
                'module_noteable_id' => $sale->id,
                'module_noteable_type' => 'Horsefly\Sale',
                'user_id' => Auth::id()
            ]);

            $moduleNote->update([
                'module_note_uid' => md5($moduleNote->id)
            ]);

            // Handle attachments if provided
            if ($request->hasFile('attachments')) {
                $attachments = $request->file('attachments');

                foreach ($attachments as $attachment) {
                    // Get the original filename
                    $filenameWithExt = $attachment->getClientOriginalName();
                    $size = $attachment->getSize();

                    // Get just the filename without extension
                    $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);

                    // Get just the extension
                    $extension = $attachment->getClientOriginalExtension();

                    // Create a new filename with timestamp
                    $fileNameToStore = $filename . '_' . time() . '.' . $extension;

                    // Upload file to public/uploads directory
                    $path = $attachment->storeAs('sale_docs', $fileNameToStore, 'public');

                    // Save document details in sale_documents table
                    SaleDocument::create([
                        'sale_id' => $id,
                        'user_id' => $user->id,
                        'document_name' => $fileNameToStore,
                        'document_path' => $path,
                        'document_extension' => $extension,
                        'document_size' => $size
                    ]);
                }
            }

            if($request->has('sale_notes')){
                 $sale_note = SaleNote::create([
                    'sale_id' => $sale->id,
                    'user_id' => $user->id,
                    'sale_note' => $sale_add_note,
                ]);

                $sale_note->update(['sales_notes_uid' => md5(uniqid($sale_note->id, true))]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Sale updated successfully',
                'redirect' => route('sales.list')
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating sale: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the sale. Please try again.'
            ], 500);
        }
    }
    public function destroy($id)
    {
        $sale = Sale::findOrFail($id);
        $sale->delete();
        return redirect()->route('sales.list')->with('success', 'Sale deleted successfully');
    }
    public function show($id)
    {
        $sale = Sale::findOrFail($id);
        return view('sales.show', compact('sale'));
    }
    public function getSales(Request $request)
    {
        $statusFilter = $request->input('status_filter', ''); // Default is empty (no filter)
        $typeFilter = $request->input('type_filter', ''); // Default is empty (no filter)
        $categoryFilter = $request->input('category_filter', ''); // Default is empty (no filter)
        $titleFilter = $request->input('title_filter', ''); // Default is empty (no filter)
        $limitCountFilter = $request->input('cv_limit_filter', ''); // Default is empty (no filter)
        $officeFilter = $request->input('office_filter', ''); // Default is empty (no filter)
        $userFilter = $request->input('user_filter', ''); // Default is empty (no filter)

        // Subquery to get the latest audit (open_date) for each sale
        $latestAuditSub = DB::table('audits')
            ->select(DB::raw('MAX(id) as id'))
            ->where('auditable_type', 'Horsefly\\Sale')
            ->where('message', 'like', '%sale-opened%')
            ->groupBy('auditable_id');

        $model = Sale::query()
            ->select([
                'sales.*',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'offices.office_name as office_name',
                'units.unit_name as unit_name',
                'users.name as user_name',
                'audits.created_at as open_date',
                
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
             // Join only the latest audit for each sale
            ->leftJoin('audits', function ($join) use ($latestAuditSub) {
            $join->on('audits.auditable_id', '=', 'sales.id')
                ->where('audits.auditable_type', '=', 'Horsefly\\Sale')
                ->where('audits.message', 'like', '%sale-opened%')
                ->whereIn('audits.id', $latestAuditSub);
            })
            ->with(['jobTitle', 'jobCategory', 'unit', 'office', 'user', 'saleNotes'])
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
            case 'open':
                $model->where('sales.status', 1)
                    ->where('sales.is_on_hold', 0);
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
                
            // Optional: default case if none match
            default:
                $model->where('sales.status', 1)
                    ->where('sales.is_on_hold', 0);
                break;
        }

        // Filter by type if it's not empty
        switch($typeFilter){
            case 'specialist':
                $model->where('sales.job_type', 'specialist');
                break;
            case 'regular':
                $model->where('sales.job_type', 'regular');
                break;
        }
       
        // Filter by category if it's not empty
        if ($officeFilter) {
            $model->whereIn('sales.office_id', $officeFilter);
        }
        
        // Filter by category if it's not empty
        switch($limitCountFilter){
            case 'zero':
                $model->where('sales.cv_limit', '=', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1'
                    ));
                });
                break;
            case 'not max':
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count > 0 
                        AND sent_cv_count <> sales.cv_limit'
                    ));
                });
                break;
            case 'max':
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count = 0'
                    ));
                });
                break;
        }
       
        // Filter by category if it's not empty
        if ($categoryFilter) {
            $model->whereIn('sales.job_category_id', $categoryFilter);
        }
       
        // Filter by category if it's not empty
        if ($titleFilter) {
            $model->whereIn('sales.job_title_id', $titleFilter);
        }

        // Filter by user if it's not empty
        if ($userFilter) {
            $model->whereIn('sales.user_id', $userFilter);
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
                ->addColumn('job_title', function ($sale) {
                    return $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
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
                    if($sale->lat != null && $sale->lng != null){
                        $url = url('/sales/fetch-applicants-by-radius/'. $sale->id . '/15');
                        $button = '<a target="_blank" href="'. $url .'" style="color:blue;">'. $sale->formatted_postcode .'</a>'; // Using accessor
                    }else{
                        $button = $sale->formatted_postcode;
                    }
                    return $button;
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
                ->addColumn('created_at', function ($sale) {
                    return $sale->formatted_created_at; // Using accessor
                })
                ->addColumn('updated_at', function ($sale) {
                    return $sale->formatted_updated_at; // Using accessor
                })
                ->addColumn('cv_limit', function ($sale) {
                    $status = $sale->no_of_sent_cv == $sale->cv_limit ? '<span class="badge w-100 bg-danger" style="font-size:90%" >' . $sale->no_of_sent_cv . '/' . $sale->cv_limit . '<br>Limit Reached</span>' : "<span class='badge w-100 bg-primary' style='font-size:90%'>" . ((int)$sale->cv_limit - (int)$sale->no_of_sent_cv . '/' . (int)$sale->cv_limit) . "<br>Limit Remains</span>";
                    return $status;
                })
                ->addColumn('sale_notes', function ($sale) {
                    $notesIndex = '-'; 
                    if(!empty($sale->sale_notes)){
                        $notesIndex = $sale->sale_notes;
                    }else{
                        $notesIndex = $sale->latest_note;
                    }

                    $notes = nl2br(htmlspecialchars($notesIndex, ENT_QUOTES, 'UTF-8'));
                    // $notes = $notes ? $notes : 'N/A';
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

                    // PRIORITY 1 — Check main status first
                    if ($sale->status == 0) {
                        return '<span class="badge bg-danger">Closed</span>';
                    }

                    if ($sale->status == 2) {
                        return '<span class="badge bg-warning">Pending</span>';
                    }

                    if ($sale->status == 3) {
                        return '<span class="badge bg-danger">Rejected</span>';
                    }

                    // PRIORITY 2 — Status = 1 (Open) — Now check sub-status
                    if ($sale->status == 1) {

                        if ($sale->is_on_hold == 1) {
                            return '<span class="badge bg-warning">On Hold</span>';
                        }

                        if ($sale->is_re_open == 1) {
                            return '<span class="badge bg-dark">Re-Open</span>';
                        }

                        return '<span class="badge bg-success">Open</span>';
                    }

                    return $status;
                })
                ->addColumn('action', function ($sale) {
                    $postcode = $sale->formatted_postcode;
                    $posted_date = $sale->formatted_created_at;
                    $office_id = $sale->office_id;
                    $office = Office::find($office_id);
                    $office_name = $office ? ucwords($office->office_name) : '-';
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    $unit_name = $unit ? ucwords($unit->unit_name) : '-';
                    $status_badge = '';
                    $jobTitle = $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                    $type = $sale->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords($type) . ')' : '';
                    $jobCategory = $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';

                    // Status badges
                    if ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status_badge = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status_badge = '<span class="badge bg-dark">Re-Open</span>';
                    } elseif ($sale->status == 0) {
                        $status_badge = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 1) {
                        $status_badge = '<span class="badge bg-success">Open</span>';
                    } elseif ($sale->status == 2) {
                        $status_badge = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status_badge = '<span class="badge bg-danger">Rejected</span>';
                    }

                    $position_type = strtoupper(str_replace('-',' ',$sale->position_type));
                    $position = '<span class="badge bg-primary">'. $position_type .'</span>';

                    $action = '';
                    $action .= '<div class="btn-group dropstart">
                                    <button type="button" class="border-0 bg-transparent p-0" 
                                            data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                        <iconify-icon icon="solar:menu-dots-square-outline" 
                                                    class="align-middle fs-24 text-dark"></iconify-icon>
                                    </button>
                                    <ul class="dropdown-menu">';

                    if (Gate::allows('sale-edit')) {
                        $action .= '<li><a class="dropdown-item" href="' . route('sales.edit', ['id' => (int)$sale->id]) . '">Edit</a></li>';
                    }

                    if (Gate::allows('sale-view')) {
                        $action .= '<li><a class="dropdown-item" href="#" onclick="showDetailsModal(
                            ' . $sale->id . ',
                            \'' . e($posted_date) . '\',
                            \'' . e($office_name) . '\',
                            \'' . e($unit_name) . '\',
                            \'' . e($postcode) . '\',
                            \'' . e(strip_tags($jobCategory)) . '\',
                            \'' . e(strip_tags($jobTitle)) . '\',
                            \'' . e($status_badge) . '\',
                            \'' . e($sale->timing) . '\',
                            \'' . e(htmlspecialchars($sale->experience, ENT_QUOTES, 'UTF-8')) . '\',
                            \'' . e($sale->salary) . '\',
                            \'' . e(strip_tags($position)) . '\',
                            \'' . e($sale->qualification) . '\',
                            \'' . e($sale->benefits) . '\'
                        )">View</a></li>';
                    }

                    if (Gate::allows('sale-add-note')) {
                        $action .= '<li><a class="dropdown-item" href="#" onclick="addNotesModal(' . (int)$sale->id . ')">Add Note</a></li>';
                    }

                    if (Gate::allows('sale-change-status')) {
                        $action .= '<li><a class="dropdown-item" href="#" onclick="changeSaleStatusModal(' . (int)$sale->id . ',' . $sale->status . ')">Mark As Open/Close</a></li>';
                    }

                    if (Gate::allows('sale-mark-on-hold')) {
                        if ($sale->status == 1 && $sale->is_on_hold == 0) {
                            $action .= '<li><a class="dropdown-item" href="#" onclick="changeSaleOnHoldStatusModal(' . (int)$sale->id . ', 2)">Mark as On Hold</a></li>';
                        }
                    }

                    $action .= '<li><hr class="dropdown-divider"></li>';

                    if (Gate::allows('sale-view-documents')) {
                        $action .= '<li><a class="dropdown-item" href="#" onclick="viewSaleDocuments(' . (int)$sale->id . ')">View Documents</a></li>';
                    }
                    
                    $url = route('sales.history', ['id' => (int)$sale->id]);
                    if (Gate::allows('sale-view-history')) {
                        $action .= '<li><a class="dropdown-item" target="_blank" href="'. $url .'">View History</a></li>';
                    }

                    if (Gate::allows('sale-view-notes-history')) {
                        $action .= '<li><a class="dropdown-item" href="#" onclick="viewNotesHistory(' . (int)$sale->id . ')">Notes History</a></li>';
                    }

                    if (Gate::allows('sale-view-manager-details')) {
                        $action .= '<li><a class="dropdown-item" href="#" onclick="viewManagerDetails(' . (int)$sale->unit_id . ')">Manager Details</a></li>';
                    }

                    $action .= '</ul></div>';

                    return $action;

                })
                ->rawColumns(['sale_notes', 'experience', 'sale_postcode', 'qualification', 'job_title', 'cv_limit', 'open_date', 'job_category', 'office_name', 'salary', 'unit_name', 'status', 'action', 'statusFilter'])
                ->make(true);
        }
    }
    public function getDirectSales(Request $request)
    {
        $typeFilter = $request->input('type_filter', ''); // Default is empty (no filter)
        $categoryFilter = $request->input('category_filter', ''); // Default is empty (no filter)
        $titleFilter = $request->input('title_filter', ''); // Default is empty (no filter)
        $dateRangeFilter = $request->input('date_range_filter', ''); // Default is empty (no filter)
        $limitCountFilter = $request->input('cv_limit_filter', ''); // Default is empty (no filter)
        $officeFilter = $request->input('office_filter', ''); // Default is empty (no filter)
        $userFilter = $request->input('user_filter', ''); // Default is empty (no filter)

        // Subquery to get the latest audit (open_date) for each sale
        $latestAuditSub = DB::table('audits')
            ->select(DB::raw('MAX(id) as id'))
            ->where('auditable_type', 'Horsefly\Sale')
            ->where('message', 'like', '%sale-opened%')
            ->groupBy('auditable_id');

        $model = Sale::query()
            ->select([
            'sales.*',
            'job_titles.name as job_title_name',
            'job_categories.name as job_category_name',
            'offices.office_name as office_name',
            'units.unit_name as unit_name',
            'users.name as user_name',
            'audits.created_at as open_date'
            ])
            ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
            ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
            ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
            ->leftJoin('users', 'sales.user_id', '=', 'users.id')
            // Join only the latest audit for each sale
            ->leftJoin('audits', function ($join) use ($latestAuditSub) {
            $join->on('audits.auditable_id', '=', 'sales.id')
                ->where('audits.auditable_type', '=', 'Horsefly\Sale')
                ->where('audits.message', 'like', '%sale-opened%')
                ->whereIn('audits.id', $latestAuditSub);
            })
            ->with(['jobTitle', 'jobCategory', 'unit', 'office', 'user'])
            ->where('sales.status', 1)
            ->where('sales.is_on_hold', 0)
            ->where(function ($query) {
            $query->whereNotNull('audits.id')
                  ->orWhereNull('audits.id');
            })
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

        // Filter by type if it's not empty
        switch($typeFilter){
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
        
        // Filter by user if it's not empty
        if ($userFilter) {
            $model->where('sales.user_id', $userFilter);
        }
        
        // Filter by category if it's not empty
        switch($limitCountFilter){
            case 'zero':
                $model->where('sales.cv_limit', '=', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1'
                    ));
                });
                break;
            case 'not max':
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count > 0 
                        AND sent_cv_count <> sales.cv_limit'
                    ));
                });
                break;
            case 'max':
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count = 0'
                    ));
                });
                break;
        }
       
        // Filter by category if it's not empty
        if ($categoryFilter) {
            $model->where('sales.job_category_id', $categoryFilter);
        }
       
        // Filter by category if it's not empty
        if ($titleFilter) {
            $model->where('sales.job_title_id', $titleFilter);
        }

        if ($dateRangeFilter) {
            // Parse the date range filter (format: "YYYY-MM-DD|YYYY-MM-DD")
            [$start_date, $end_date] = explode('|', $dateRangeFilter);
            $start_date = trim($start_date) . ' 00:00:00';
            $end_date = trim($end_date) . ' 23:59:59';

            $model->where(function ($query) use ($start_date, $end_date) {
                $query->whereBetween('sales.updated_at', [$start_date, $end_date])
                    ->orWhereBetween('audits.created_at', [$start_date, $end_date]);
            });
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
                ->addColumn('job_title', function ($sale) {
                    return $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                })
                ->addColumn('cv_limit', function ($sale) {
                    $status = $sale->no_of_sent_cv == $sale->cv_limit ? '<span class="badge w-100 bg-danger" style="font-size:90%" >' . $sale->no_of_sent_cv . '/' . $sale->cv_limit . '<br>Limit Reached</span>' : "<span class='badge w-100 bg-primary' style='font-size:90%'>" . ((int)$sale->cv_limit - (int)$sale->no_of_sent_cv . '/' . (int)$sale->cv_limit) . "<br>Limit Remains</span>";
                    return $status;
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
                ->addColumn('open_date', function ($sale) {
                    return $sale->open_date ? Carbon::parse($sale->open_date)->format('d M Y, h:i A') : '-'; // Using accessor
                })
                ->addColumn('sale_notes', function ($sale) {
                    $notes = nl2br(htmlspecialchars($sale->sale_notes, ENT_QUOTES, 'UTF-8'));
                    $notes = $notes ? $notes : 'N/A';
                    $postcode = htmlspecialchars($sale->sale_postcode, ENT_QUOTES, 'UTF-8');
                    $unit = Unit::find($sale->unit_id);
                    $unit_name = $unit ? ucwords($unit->unit_name) : '-';
                    $office = Office::find($sale->office_id);
                    $office_name = $office ? ucwords($office->office_name) : '-';

                    // Tooltip content with additional data-bs-placement and title
                    return '<a href="#" title="View Note" onclick="showNotesModal(\'' . (int)$sale->id . '\', \'' . $notes . '\', \'' . $office_name . '\', \'' . $unit_name . '\', \'' . $postcode . '\')">
                                <iconify-icon icon="solar:eye-scan-bold" class="text-primary fs-24"></iconify-icon>
                            </a>
                            <a href="#" title="Add Short Note" onclick="addNotesModal(\'' . (int)$sale->id . '\')">
                                <iconify-icon icon="solar:clipboard-add-linear" class="text-warning fs-24"></iconify-icon>
                            </a>';
                })
                ->addColumn('status', function ($sale) {
                    $status = '';
                    if ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status = '<span class="badge bg-dark">Re-Open</span>';
                    } elseif ($sale->status == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

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

                ->addColumn('action', function ($sale) {
                    $postcode = $sale->formatted_postcode;
                    $posted_date = $sale->formatted_created_at;
                    $office_id = $sale->office_id;
                    $office = Office::find($office_id);
                    $office_name = $office ? ucwords($office->office_name) : '-';
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    $unit_name = $unit ? ucwords($unit->unit_name) : '-';
                    $status_badge = '';
                    $jobTitle = $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                    $type = $sale->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    $jobCategory = $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';

                    if ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status_badge = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status_badge = '<span class="badge bg-dark">Re-Open</span>';
                    } elseif ($sale->status == 0) {
                        $status_badge = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 1) {
                        $status_badge = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 2) {
                        $status_badge = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status_badge = '<span class="badge bg-danger">Rejected</span>';
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
                                    \'' . e($posted_date) . '\',
                                    \'' . e($office_name) . '\',
                                    \'' . e($unit_name) . '\',
                                    \'' . e($postcode) . '\',
                                    \'' . e(strip_tags($jobCategory)) . '\',
                                    \'' . e(strip_tags($jobTitle)) . '\',
                                    \'' . e($status_badge) . '\',
                                    \'' . e($sale->timing) . '\',
                                    \'' . e(htmlspecialchars($sale->experience, ENT_QUOTES, 'UTF-8')) . '\',
                                    \'' . e($sale->salary) . '\',
                                    \'' . e(strip_tags($position)) . '\',
                                    \'' . e($sale->qualification) . '\',
                                    \'' . e($sale->benefits) . '\'
                                )">View</a></li>';
                        if ($sale->status == 1) {
                            $action .= '<li><a class="dropdown-item" href="#" onclick="changeSaleStatusModal(' . (int)$sale->id . ', 0)">Mark as Close</a></li>';
                        }else{
                            $action .= '<li><a class="dropdown-item" href="#" onclick="changeSaleStatusModal(' . (int)$sale->id . ', 1)">Mark as Open</a></li>';
                        }
                        if ($sale->status == 1 && $sale->is_on_hold == 0) {
                            $action .= '<li><a class="dropdown-item" href="#" onclick="changeSaleOnHoldStatusModal(' . (int)$sale->id . ', 2)">Mark as On Hold</a></li>';
                        }
                    $url = route('sales.history', [ 'id' => (int)$sale->id ]);
                    $action .= '<li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="#" onclick="viewSaleDocuments(' . (int)$sale->id . ')">View Documents</a></li>
                                    <li><a class="dropdown-item" href="'. $url .'">View History</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="viewNotesHistory(' . (int)$sale->id . ')">Notes History</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="viewManagerDetails(' . (int)$sale->unit_id . ')">Manager Details</a></li>
                                </ul>
                            </div>';

                    return $action;
                })
                ->rawColumns(['sale_notes', 'experience', 'qualification', 'sale_postcode', 'cv_limit', 'open_date', 'job_title', 'job_category', 'office_name', 'unit_name', 'status', 'action', 'statusFilter'])
                ->make(true);
        }
    }
    public function getRejectedSales(Request $request)
    {
        $typeFilter = $request->input('type_filter', ''); // Default is empty (no filter)
        $categoryFilter = $request->input('category_filter', ''); // Default is empty (no filter)
        $titleFilter = $request->input('title_filter', ''); // Default is empty (no filter)
        $dateRangeFilter = $request->input('date_range_filter', ''); // Default is empty (no filter)
        $limitCountFilter = $request->input('cv_limit_filter', ''); // Default is empty (no filter)
        $officeFilter = $request->input('office_filter', ''); // Default is empty (no filter)
        $userFilter = $request->input('user_filter', ''); // Default is empty (no filter)

        // Subquery to get the latest audit (open_date) for each sale
        $latestAuditSub = DB::table('audits')
            ->select(DB::raw('MAX(id) as id'))
            ->where('auditable_type', 'Horsefly\Sale')
            ->where('message', 'like', '%sale-rejected%')
            ->whereIn('auditable_id', function($query) {
                $query->select('id')
                    ->from('sales'); // Ensure we only consider rejected sales
            })
            ->groupBy('auditable_id');

        $model = Sale::query()
            ->select([
            'sales.*',
            'job_titles.name as job_title_name',
            'job_categories.name as job_category_name',
            'offices.office_name as office_name',
            'units.unit_name as unit_name',
            'users.name as user_name',
            'audits.created_at as rejected_date'
            ])
            ->where('sales.status', 3) // rejected sales
            ->where('sales.is_on_hold', 0) // Not on hold
            ->leftJoin(DB::raw('(SELECT id, sale_id, sale_note, created_at 
                    FROM sale_notes 
                    WHERE created_at = (
                        SELECT MAX(created_at) 
                        FROM sale_notes AS sn 
                        WHERE sn.sale_id = sale_notes.sale_id
                    )
                ) AS latest_sales_notes'), 
                'sales.id', '=', 'latest_sales_notes.sale_id'
            )
            ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
            ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
            ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
            ->leftJoin('users', 'sales.user_id', '=', 'users.id')
             // Join only the latest audit for each sale
            ->leftJoin('audits', function ($join) use ($latestAuditSub) {
                $join->on('audits.auditable_id', '=', 'sales.id')
                    ->where('audits.auditable_type', '=', 'Horsefly\Sale')
                    ->where('audits.message', 'like', '%sale-rejected%')
                    ->whereIn('audits.id', $latestAuditSub);
            })
            ->with(['jobTitle', 'jobCategory', 'unit', 'office', 'user'])
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

                    $query->orWhereHas('office', function ($q) use ($likeSearch) {
                        $q->where('offices.office_name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('user', function ($q) use ($likeSearch) {
                        $q->where('users.name', 'LIKE', "%{$likeSearch}%");
                    });
                });
            }
        }

        // Filter by type if it's not empty
        switch($typeFilter){
            case 'specialist':
                $model->where('sales.job_type', 'specialist');
                break;
            case 'regular':
                $model->where('sales.job_type', 'regular');
                break;
        }

        // Filter by user if it's not empty
        if ($userFilter) {
            $model->whereIn('sales.user_id', $userFilter);
        }
       
        // Filter by category if it's not empty
        if ($officeFilter) {
            $model->whereIn('sales.office_id', $officeFilter);
        }
        
        // Filter by category if it's not empty
        switch($limitCountFilter){
            case 'zero':
                $model->where('sales.cv_limit', '=', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1'
                    ));
                });
                break;
            case 'not max':
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count > 0 
                        AND sent_cv_count <> sales.cv_limit'
                    ));
                });
                break;
            case 'max':
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count = 0'
                    ));
                });
                break;
        }
       
        // Filter by category if it's not empty
        if ($categoryFilter) {
            $model->whereIn('sales.job_category_id', $categoryFilter);
        }
       
        // Filter by category if it's not empty
        if ($titleFilter) {
            $model->whereIn('sales.job_title_id', $titleFilter);
        }

        $now = Carbon::today();
        switch($dateRangeFilter) {
            case 'last-3-months':
                $startDate = $now->copy()->subMonths(3)->startOfDay();
                $endDate = $now->endOfDay();
                $model->whereBetween('sales.updated_at', [$startDate, $endDate]);
                break;
                
            case 'last-6-months':
                $endDate = $now->copy()->subMonths(3)->endOfDay();
                $startDate = $endDate->copy()->subMonths(6)->startOfDay();
                $model->whereBetween('sales.updated_at', [$startDate, $endDate]);
                break;
                
            case 'last-9-months':
                $endDate = $now->copy()->subMonths(9)->endOfDay();
                $startDate = $endDate->copy()->subMonths(9)->startOfDay();
                $model->whereBetween('sales.updated_at', [$startDate, $endDate]);
                break;
                
            case 'other':
                $cutoffDate = $now->copy()->subMonths(18);
                $model->where('sales.updated_at', '<', $cutoffDate);
                break;

            default:
                $startDate = $now->copy()->subMonths(3)->startOfDay();
                $endDate = $now->endOfDay();
                $model->whereBetween('sales.updated_at', [$startDate, $endDate]);
                break;
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
                 ->addColumn('rejected_date', function ($sale) {
                    return $sale->rejected_date ? Carbon::parse($sale->rejected_date)->format('d M Y, h:i A') : '-'; // Using accessor
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
                ->addColumn('cv_limit', function ($sale) {
                    $status = $sale->no_of_sent_cv == $sale->cv_limit ? '<span class="badge w-100 bg-danger" style="font-size:90%" >' . $sale->no_of_sent_cv . '/' . $sale->cv_limit . '<br>Limit Reached</span>' : "<span class='badge w-100 bg-primary' style='font-size:90%'>" . ((int)$sale->cv_limit - (int)$sale->no_of_sent_cv . '/' . (int)$sale->cv_limit) . "<br>Limit Remains</span>";
                    return $status;
                })
                ->addColumn('created_at', function ($sale) {
                    return $sale->formatted_created_at; // Using accessor
                })
                ->addColumn('updated_at', function ($sale) {
                    return $sale->formatted_updated_at; // Using accessor
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
                                        <h5 class="modal-title" id="' . $id . '-label">Sale Salary</h5>
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
                    $notes      = $sale->sale_notes ? nl2br(e($sale->sale_notes)) : 'N/A';
                    $postcode   = strtoupper($sale->sale_postcode ?? '-');
                    $unit_name  = $sale->unit ? ucwords($sale->unit->unit_name) : '-';
                    $office_name = $sale->office ? ucwords($sale->office->office_name) : '-';

                    $id = (int) $sale->id;

                    // Safely encode for JS
                    $notesJs    = htmlspecialchars(json_encode($notes), ENT_QUOTES, 'UTF-8');
                    $officeJs   = htmlspecialchars(json_encode($office_name), ENT_QUOTES, 'UTF-8');
                    $unitJs     = htmlspecialchars(json_encode($unit_name), ENT_QUOTES, 'UTF-8');
                    $postcodeJs = htmlspecialchars(json_encode($postcode), ENT_QUOTES, 'UTF-8');

                    return <<<HTML
                        <a href="#" title="View Note"
                        onclick="showNotesModal($id, $notesJs, $officeJs, $unitJs, $postcodeJs)">
                            <iconify-icon icon="solar:eye-scan-bold" class="text-primary fs-24"></iconify-icon>
                        </a>
                        <a href="#" title="Add Short Note"
                        onclick="addNotesModal($id)">
                            <iconify-icon icon="solar:clipboard-add-linear" class="text-warning fs-24"></iconify-icon>
                        </a>
                    HTML;
                })
                ->addColumn('status', function ($sale) {
                    $status = '';
                    if ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status = '<span class="badge bg-dark">Re-Open</span>';
                    } elseif ($sale->status == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

                    return $status;
                })
                ->addColumn('action', function ($sale) {
                    $postcode = $sale->formatted_postcode;
                    $posted_date = $sale->formatted_created_at;
                    $office_id = $sale->office_id;
                    $office = Office::find($office_id);
                    $office_name = $office ? ucwords($office->office_name) : '-';
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    $unit_name = $unit ? ucwords($unit->unit_name) : '-';
                    $status_badge = '';
                    $jobTitle = $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                    $type = $sale->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords($type) . ')' : '';
                    $jobCategory = $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';

                    if ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status_badge = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status_badge = '<span class="badge bg-dark">Re-Open</span>';
                    } elseif ($sale->status == 0) {
                        $status_badge = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 1) {
                        $status_badge = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 2) {
                        $status_badge = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status_badge = '<span class="badge bg-danger">Rejected</span>';
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
                                ' . $sale->id . ',
                                \'' . e($posted_date) . '\',
                                \'' . e($office_name) . '\',
                                \'' . e($unit_name) . '\',
                                \'' . e($postcode) . '\',
                                \'' . e(strip_tags($jobCategory)) . '\',
                                \'' . e(strip_tags($jobTitle)) . '\',
                                \'' . e($status_badge) . '\',
                                \'' . e($sale->timing) . '\',
                                \'' . e(htmlspecialchars($sale->experience, ENT_QUOTES, 'UTF-8')) . '\',
                                \'' . e($sale->salary) . '\',
                                \'' . e(strip_tags($position)) . '\',
                                \'' . e($sale->qualification) . '\',
                                \'' . e($sale->benefits) . '\'
                            )">View</a></li>';
                                $action .= '<li><a class="dropdown-item" href="#" onclick="changeSaleStatusModal(' . $sale->id . ', 1)">Mark as Open</a></li>';
                                if ($sale->status == 1 && $sale->is_on_hold == 0) {
                                    $action .= '<li><a class="dropdown-item" href="#" onclick="changeSaleOnHoldStatusModal(' . $sale->id . ', 2)">Mark as On Hold</a></li>';
                                }
                                $url = route('sales.history', [ 'id' => $sale->id ]);
                                $action .= '<li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item" href="#" onclick="viewSaleDocuments(' . $sale->id . ')">View Documents</a></li>
                                                <li><a class="dropdown-item" href="'. $url .'" target="_blank">View History</a></li>
                                                <li><a class="dropdown-item" href="#" onclick="viewNotesHistory(' . $sale->id . ')">Notes History</a></li>
                                                <li><a class="dropdown-item" href="#" onclick="viewManagerDetails(' . $sale->unit_id . ')">Manager Details</a></li>
                                            </ul>
                                        </div>';

                                return $action;
                })
                ->rawColumns(['sale_notes', 'experience', 'salary', 'sale_postcode', 'qualification', 'job_title', 'cv_limit', 'rejected_date', 'job_category', 'office_name', 'unit_name', 'status', 'action', 'statusFilter'])
                ->make(true);
        }
    }
    public function getClosedSales(Request $request)
    {
        $typeFilter = $request->input('type_filter', ''); // Default is empty (no filter)
        $categoryFilter = $request->input('category_filter', ''); // Default is empty (no filter)
        $titleFilter = $request->input('title_filter', ''); // Default is empty (no filter)
        $dateRangeFilter = $request->input('date_range_filter', ''); // Default is empty (no filter)
        $limitCountFilter = $request->input('cv_limit_filter', ''); // Default is empty (no filter)
        $officeFilter = $request->input('office_filter', ''); // Default is empty (no filter)
        $userFilter = $request->input('user_filter', ''); // Default is empty (no filter)

        // Subquery to get the latest audit (open_date) for each sale
        $latestAuditSub = DB::table('audits')
            ->select(DB::raw('MAX(id) as id'))
            ->where('auditable_type', 'Horsefly\Sale')
            ->where('message', 'like', '%sale-closed%')
            ->whereIn('auditable_id', function($query) {
                $query->select('id')
                    ->from('sales'); // Ensure we only consider closed sales
            })
            ->groupBy('auditable_id');

        $model = Sale::query()
            ->select([
            'sales.*',
            'job_titles.name as job_title_name',
            'job_categories.name as job_category_name',
            'offices.office_name as office_name',
            'units.unit_name as unit_name',
            'users.name as user_name',
            'audits.created_at as closed_date'
            ])
            ->where('sales.status', 0) // Closed sales
            ->where('sales.is_on_hold', 0) // Not on hold
            ->leftJoin(DB::raw('(SELECT id, sale_id, sale_note, created_at 
                    FROM sale_notes 
                    WHERE created_at = (
                        SELECT MAX(created_at) 
                        FROM sale_notes AS sn 
                        WHERE sn.sale_id = sale_notes.sale_id
                    )
                ) AS latest_sales_notes'), 
                'sales.id', '=', 'latest_sales_notes.sale_id'
            )
            ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
            ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
            ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
            ->leftJoin('users', 'sales.user_id', '=', 'users.id')
             // Join only the latest audit for each sale
            ->leftJoin('audits', function ($join) use ($latestAuditSub) {
                $join->on('audits.auditable_id', '=', 'sales.id')
                    ->where('audits.auditable_type', '=', 'Horsefly\Sale')
                    ->where('audits.message', 'like', '%sale-closed%')
                    ->whereIn('audits.id', $latestAuditSub);
            })
            ->with(['jobTitle', 'jobCategory', 'unit', 'office', 'user'])
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

                    $query->orWhereHas('office', function ($q) use ($likeSearch) {
                        $q->where('offices.office_name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('user', function ($q) use ($likeSearch) {
                        $q->where('users.name', 'LIKE', "%{$likeSearch}%");
                    });
                });
            }
        }

        // Filter by type if it's not empty
        switch($typeFilter){
            case 'specialist':
                $model->where('sales.job_type', 'specialist');
                break;
            case 'regular':
                $model->where('sales.job_type', 'regular');
                break;
        }

        // Filter by user if it's not empty
        if ($userFilter) {
            $model->whereIn('sales.user_id', $userFilter);
        }
       
        // Filter by category if it's not empty
        if ($officeFilter) {
            $model->whereIn('sales.office_id', $officeFilter);
        }
        
        // Filter by category if it's not empty
        switch($limitCountFilter){
            case 'zero':
                $model->where('sales.cv_limit', '=', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1'
                    ));
                });
                break;
            case 'not max':
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count > 0 
                        AND sent_cv_count <> sales.cv_limit'
                    ));
                });
                break;
            case 'max':
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count = 0'
                    ));
                });
                break;
        }
       
        // Filter by category if it's not empty
        if ($categoryFilter) {
            $model->whereIn('sales.job_category_id', $categoryFilter);
        }
       
        // Filter by category if it's not empty
        if ($titleFilter) {
            $model->whereIn('sales.job_title_id', $titleFilter);
        }

        $now = Carbon::today();
        switch($dateRangeFilter) {
            case 'last-3-months':
                $startDate = $now->copy()->subMonths(3)->startOfDay();
                $endDate = $now->endOfDay();
                $model->whereBetween('sales.updated_at', [$startDate, $endDate]);
                break;
                
            case 'last-6-months':
                $endDate = $now->copy()->subMonths(3)->endOfDay();
                $startDate = $endDate->copy()->subMonths(6)->startOfDay();
                $model->whereBetween('sales.updated_at', [$startDate, $endDate]);
                break;
                
            case 'last-9-months':
                $endDate = $now->copy()->subMonths(9)->endOfDay();
                $startDate = $endDate->copy()->subMonths(9)->startOfDay();
                $model->whereBetween('sales.updated_at', [$startDate, $endDate]);
                break;
                
            case 'other':
                $cutoffDate = $now->copy()->subMonths(18);
                $model->where('sales.updated_at', '<', $cutoffDate);
                break;

            default:
                $startDate = $now->copy()->subMonths(3)->startOfDay();
                $endDate = $now->endOfDay();
                $model->whereBetween('sales.updated_at', [$startDate, $endDate]);
                break;
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
                 ->addColumn('closed_date', function ($sale) {
                    return $sale->closed_date ? Carbon::parse($sale->closed_date)->format('d M Y, h:i A') : '-'; // Using accessor
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
                ->addColumn('cv_limit', function ($sale) {
                    $status = $sale->no_of_sent_cv == $sale->cv_limit ? '<span class="badge w-100 bg-danger" style="font-size:90%" >' . $sale->no_of_sent_cv . '/' . $sale->cv_limit . '<br>Limit Reached</span>' : "<span class='badge w-100 bg-primary' style='font-size:90%'>" . ((int)$sale->cv_limit - (int)$sale->no_of_sent_cv . '/' . (int)$sale->cv_limit) . "<br>Limit Remains</span>";
                    return $status;
                })
                ->addColumn('created_at', function ($sale) {
                    return $sale->formatted_created_at; // Using accessor
                })
                ->addColumn('updated_at', function ($sale) {
                    return $sale->formatted_updated_at; // Using accessor
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
                                        <h5 class="modal-title" id="' . $id . '-label">Sale Salary</h5>
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
                    $notes      = $sale->sale_notes ? nl2br(e($sale->sale_notes)) : 'N/A';
                    $postcode   = strtoupper($sale->sale_postcode ?? '-');
                    $unit_name  = $sale->unit ? ucwords($sale->unit->unit_name) : '-';
                    $office_name = $sale->office ? ucwords($sale->office->office_name) : '-';

                    $id = (int) $sale->id;

                    // Safely encode for JS
                    $notesJs    = htmlspecialchars(json_encode($notes), ENT_QUOTES, 'UTF-8');
                    $officeJs   = htmlspecialchars(json_encode($office_name), ENT_QUOTES, 'UTF-8');
                    $unitJs     = htmlspecialchars(json_encode($unit_name), ENT_QUOTES, 'UTF-8');
                    $postcodeJs = htmlspecialchars(json_encode($postcode), ENT_QUOTES, 'UTF-8');

                    return <<<HTML
                        <a href="#" title="View Note"
                        onclick="showNotesModal($id, $notesJs, $officeJs, $unitJs, $postcodeJs)">
                            <iconify-icon icon="solar:eye-scan-bold" class="text-primary fs-24"></iconify-icon>
                        </a>
                        <a href="#" title="Add Short Note"
                        onclick="addNotesModal($id)">
                            <iconify-icon icon="solar:clipboard-add-linear" class="text-warning fs-24"></iconify-icon>
                        </a>
                    HTML;
                })
                ->addColumn('status', function ($sale) {
                    $status = '';
                    if ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status = '<span class="badge bg-dark">Re-Open</span>';
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
                ->addColumn('action', function ($sale) {
                    $postcode = $sale->formatted_postcode;
                    $posted_date = $sale->formatted_created_at;
                    $office_id = $sale->office_id;
                    $office = Office::find($office_id);
                    $office_name = $office ? ucwords($office->office_name) : '-';
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    $unit_name = $unit ? ucwords($unit->unit_name) : '-';
                    $status_badge = '';
                    $jobTitle = $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                    $type = $sale->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords($type) . ')' : '';
                    $jobCategory = $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';

                    if ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status_badge = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status_badge = '<span class="badge bg-dark">Re-Open</span>';
                    } elseif ($sale->status == 0) {
                        $status_badge = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 1) {
                        $status_badge = '<span class="badge bg-success">Open</span>';
                    } elseif ($sale->status == 2) {
                        $status_badge = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status_badge = '<span class="badge bg-danger">Rejected</span>';
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
                                ' . $sale->id . ',
                                \'' . e($posted_date) . '\',
                                \'' . e($office_name) . '\',
                                \'' . e($unit_name) . '\',
                                \'' . e($postcode) . '\',
                                \'' . e(strip_tags($jobCategory)) . '\',
                                \'' . e(strip_tags($jobTitle)) . '\',
                                \'' . e($status_badge) . '\',
                                \'' . e($sale->timing) . '\',
                                \'' . e(htmlspecialchars($sale->experience, ENT_QUOTES, 'UTF-8')) . '\',
                                \'' . e($sale->salary) . '\',
                                \'' . e(strip_tags($position)) . '\',
                                \'' . e($sale->qualification) . '\',
                                \'' . e($sale->benefits) . '\'
                            )">View</a></li>';
                                $action .= '<li><a class="dropdown-item" href="#" onclick="changeSaleStatusModal(' . $sale->id . ', 1)">Mark as Open</a></li>';
                                if ($sale->status == 1 && $sale->is_on_hold == 0) {
                                    $action .= '<li><a class="dropdown-item" href="#" onclick="changeSaleOnHoldStatusModal(' . $sale->id . ', 2)">Mark as On Hold</a></li>';
                                }
                                $url = route('sales.history', [ 'id' => $sale->id ]);
                                $action .= '<li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item" href="#" onclick="viewSaleDocuments(' . $sale->id . ')">View Documents</a></li>
                                                <li><a class="dropdown-item" href="'. $url .'" target="_blank">View History</a></li>
                                                <li><a class="dropdown-item" href="#" onclick="viewNotesHistory(' . $sale->id . ')">Notes History</a></li>
                                                <li><a class="dropdown-item" href="#" onclick="viewManagerDetails(' . $sale->unit_id . ')">Manager Details</a></li>
                                            </ul>
                                        </div>';

                                return $action;
                })
                ->rawColumns(['sale_notes', 'experience', 'sale_postcode', 'salary', 'qualification', 'job_title', 'cv_limit', 'closed_date', 'job_category', 'office_name', 'unit_name', 'status', 'action', 'statusFilter'])
                ->make(true);
        }
    }
    public function getOpenSales(Request $request)
    {
        $typeFilter = $request->input('type_filter', ''); // Default is empty (no filter)
        $categoryFilter = $request->input('category_filter', ''); // Default is empty (no filter)
        $titleFilter = $request->input('title_filter', ''); // Default is empty (no filter)
        $dateFlockFilter = $request->input('date_flock_filter', ''); // Default is empty (no filter)
        $dateRangeFilter = $request->input('date_range_filter', ''); // Default is empty (no filter)
        $limitCountFilter = $request->input('cv_limit_filter', ''); // Default is empty (no filter)
        $officeFilter = $request->input('office_filter', ''); // Default is empty (no filter)
        $userFilter = $request->input('user_filter', ''); // Default is empty (no filter)

        // Subquery to get the latest audit (open_date) for each sale
        $latestAuditSub = DB::table('audits')
            ->select(DB::raw('MAX(id) as id'))
            ->where('auditable_type', 'Horsefly\Sale')
            ->where('message', 'like', '%sale-opened%')
            ->whereIn('auditable_id', function($query) {
                $query->select('id')
                    ->from('sales'); // Ensure we only consider closed sales
            })
            ->groupBy('auditable_id');

        $model = Sale::query()
            ->select([
            'sales.*',
            'job_titles.name as job_title_name',
            'job_categories.name as job_category_name',
            'offices.office_name as office_name',
            'units.unit_name as unit_name',
            'users.name as user_name',
            'audits.created_at as open_date'
            ])
            ->where('sales.status', 1) // open sales
            ->where('sales.is_on_hold', 0) // Not on hold
            ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
            ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
            ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
            ->leftJoin('users', 'sales.user_id', '=', 'users.id')
             // Join only the latest audit for each sale
            ->leftJoin('audits', function ($join) use ($latestAuditSub) {
                $join->on('audits.auditable_id', '=', 'sales.id')
                    ->where('audits.auditable_type', '=', 'Horsefly\Sale')
                    ->where('audits.message', 'like', '%sale-opened%')
                    ->whereIn('audits.id', $latestAuditSub);
            })
            ->with(['jobTitle', 'jobCategory', 'unit', 'office', 'user'])
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

                    $query->orWhereHas('office', function ($q) use ($likeSearch) {
                        $q->where('offices.office_name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('user', function ($q) use ($likeSearch) {
                        $q->where('users.name', 'LIKE', "%{$likeSearch}%");
                    });
                });
            }
        }

        // Filter by type if it's not empty
        switch($typeFilter){
            case 'specialist':
                $model->where('sales.job_type', 'specialist');
                break;
            case 'regular':
                $model->where('sales.job_type', 'regular');
                break;
        }

        // Filter by user if it's not empty
        if ($userFilter) {
            $model->whereIn('sales.user_id', $userFilter);
        }
       
        // Filter by category if it's not empty
        if ($officeFilter) {
            $model->whereIn('sales.office_id', $officeFilter);
        }
        
        // Filter by category if it's not empty
        switch($limitCountFilter){
            case 'zero':
                $model->where('sales.cv_limit', '=', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1'
                    ));
                });
                break;
            case 'not max':
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count > 0 
                        AND sent_cv_count <> sales.cv_limit'
                    ));
                });
                break;
            case 'max':
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count = 0'
                    ));
                });
                break;
        }
       
        // Filter by category if it's not empty
        if ($categoryFilter) {
            $model->whereIn('sales.job_category_id', $categoryFilter);
        }
       
        // Filter by category if it's not empty
        if ($titleFilter) {
            $model->whereIn('sales.job_title_id', $titleFilter);
        }

        if ($dateRangeFilter) {
            // Parse the date range filter (format: "YYYY-MM-DD|YYYY-MM-DD")
            [$start_date, $end_date] = explode('|', $dateRangeFilter);
            $start_date = trim($start_date) . ' 00:00:00';
            $end_date = trim($end_date) . ' 23:59:59';

            $model->where(function ($query) use ($start_date, $end_date) {
                $query->whereBetween('sales.updated_at', [$start_date, $end_date])
                    ->orWhereBetween('audits.created_at', [$start_date, $end_date]);
            });
        }

        $now = Carbon::today();
        switch($dateFlockFilter) {
            case 'last-3-months':
                $startDate = $now->copy()->subMonths(3);
                $endDate = $now;

                $model->whereBetween('sales.updated_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

                break;
                
            case 'last-6-months':
                $endDate = $now->copy()->subMonths(3);
                $startDate = $endDate->copy()->subMonths(6);
                $model->whereBetween('sales.updated_at', [$startDate->startOfDay(), $endDate->endOfDay()]);
                break;
                
            case 'last-9-months':
                $endDate = $now->copy()->subMonths(9);
                $startDate = $endDate->copy()->subMonths(9);
                $model->whereBetween('sales.updated_at', [$startDate->startOfDay(), $endDate->endOfDay()]);
                break;
                
            case 'other':
                $cutoffDate = $now->copy()->subMonths(18);
                $model->where('sales.updated_at', '<', $cutoffDate->endOfDay());
                break;
            default:
                $startDate = $now->copy()->subMonths(3);
                $endDate = $now;
                $model->whereBetween('sales.updated_at', [$startDate->startOfDay(), $endDate->endOfDay()]);
                break;
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
                ->addColumn('open_date', function ($sale) {
                    return $sale->open_date ? Carbon::parse($sale->open_date)->format('d M Y, h:i A') : '-'; // Using accessor
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
                ->addColumn('cv_limit', function ($sale) {
                    $status = $sale->no_of_sent_cv == $sale->cv_limit ? '<span class="badge w-100 bg-danger" style="font-size:90%" >' . $sale->no_of_sent_cv . '/' . $sale->cv_limit . '<br>Limit Reached</span>' : "<span class='badge w-100 bg-primary' style='font-size:90%'>" . ((int)$sale->cv_limit - (int)$sale->no_of_sent_cv . '/' . (int)$sale->cv_limit) . "<br>Limit Remains</span>";
                    return $status;
                })
                ->addColumn('sale_notes', function ($sale) {
                    $notes      = $sale->sale_notes ? nl2br(e($sale->sale_notes)) : 'N/A';
                    $postcode   = strtoupper($sale->sale_postcode ?? '-');
                    $unit_name  = $sale->unit ? ucwords($sale->unit->unit_name) : '-';
                    $office_name = $sale->office ? ucwords($sale->office->office_name) : '-';

                    $id = (int) $sale->id;

                    // Safely encode for JS
                    $notesJs    = htmlspecialchars(json_encode($notes), ENT_QUOTES, 'UTF-8');
                    $officeJs   = htmlspecialchars(json_encode($office_name), ENT_QUOTES, 'UTF-8');
                    $unitJs     = htmlspecialchars(json_encode($unit_name), ENT_QUOTES, 'UTF-8');
                    $postcodeJs = htmlspecialchars(json_encode($postcode), ENT_QUOTES, 'UTF-8');

                    return <<<HTML
                        <a href="#" title="View Note"
                        onclick="showNotesModal($id, $notesJs, $officeJs, $unitJs, $postcodeJs)">
                            <iconify-icon icon="solar:eye-scan-bold" class="text-primary fs-24"></iconify-icon>
                        </a>
                        <a href="#" title="Add Short Note"
                        onclick="addNotesModal($id)">
                            <iconify-icon icon="solar:clipboard-add-linear" class="text-warning fs-24"></iconify-icon>
                        </a>
                    HTML;
                })
                ->addColumn('status', function ($sale) {
                    $status = '';
                    if ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status = '<span class="badge bg-dark">Re-Open</span>';
                    } elseif ($sale->status == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

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
                                        <h5 class="modal-title" id="' . $id . '-label">Sale Salary</h5>
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
                ->addColumn('action', function ($sale) {
                    $postcode = $sale->formatted_postcode;
                    $posted_date = $sale->formatted_created_at;
                    $office_id = $sale->office_id;
                    $office = Office::find($office_id);
                    $office_name = $office ? ucwords($office->office_name) : '-';
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    $unit_name = $unit ? ucwords($unit->unit_name) : '-';
                    $status_badge = '';
                    $jobTitle = $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                    $type = $sale->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords($type) . ')' : '';
                    $jobCategory = $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';

                    if ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status_badge = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status_badge = '<span class="badge bg-dark">Re-Open</span>';
                    } elseif ($sale->status == 0) {
                        $status_badge = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 1) {
                        $status_badge = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 2) {
                        $status_badge = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status_badge = '<span class="badge bg-danger">Rejected</span>';
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
                                    ' . $sale->id . ',
                                    \'' . e($posted_date) . '\',
                                    \'' . e($office_name) . '\',
                                    \'' . e($unit_name) . '\',
                                    \'' . e($postcode) . '\',
                                    \'' . e(strip_tags($jobCategory)) . '\',
                                    \'' . e(strip_tags($jobTitle)) . '\',
                                    \'' . e($status_badge) . '\',
                                    \'' . e($sale->timing) . '\',
                                    \'' . e(htmlspecialchars($sale->experience, ENT_QUOTES, 'UTF-8')) . '\',
                                    \'' . e($sale->salary) . '\',
                                    \'' . e(strip_tags($position)) . '\',
                                    \'' . e($sale->qualification) . '\',
                                    \'' . e($sale->benefits) . '\'
                                )">View</a></li>';
                    $action .= '<li><a class="dropdown-item" href="#" onclick="changeSaleStatusModal(' . $sale->id . ', 0)">Mark as Close</a></li>';
                    if ($sale->status == '1' && $sale->is_on_hold == '0') {
                        $action .= '<li><a class="dropdown-item" href="#" onclick="changeSaleOnHoldStatusModal(' . $sale->id . ', 2)">Mark as On Hold</a></li>';
                    }
                    $url = route('sales.history', [ 'id' => $sale->id ]);
                    $action .= '<li><hr class="dropdown-divider"></li>
                                     <li><a class="dropdown-item" href="#" onclick="viewSaleDocuments(' . $sale->id . ')">View Documents</a></li>
                                    <li><a class="dropdown-item" href="'. $url .'" target="_blank">View History</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="viewNotesHistory(' . $sale->id . ')">Notes History</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="viewManagerDetails(' . $sale->unit_id . ')">Manager Details</a></li>
                                </ul>
                            </div>';

                    return $action;
                })
                ->rawColumns(['sale_notes', 'experience', 'salary','sale_postcode', 'qualification', 'cv_limit', 'job_title', 'open_date', 'job_category', 'office_name', 'unit_name', 'status', 'action', 'statusFilter'])
                ->make(true);
        }
    }
    // public function pendingOnHoldSales3(Request $request)
    // {
    //     $statusFilter = $request->input('status_filter', ''); // Default is empty (no filter)

    //     $sales = Sale::query()->with(['jobTitle', 'jobCategory'])
    //     ->where('is_on_hold', 2)->latest();

    //     if ($request->ajax()) {
    //         return DataTables::eloquent($sales)
    //             ->addIndexColumn() // This will automatically add a serial number to the rows
    //             ->addColumn('office_name', function ($sale) {
    //                 $office_id = $sale->office_id;
    //                 $office = Office::find($office_id);
    //                 return $office ? ucwords($office->office_name) : '-';
    //             })
    //             ->addColumn('unit_name', function ($sale) {
    //                 $unit_id = $sale->unit_id;
    //                 $unit = Unit::find($unit_id);
    //                 return $unit ? ucwords($unit->unit_name) : '-';
    //             })
    //             ->addColumn('job_title', function ($sale) {
    //                 return $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
    //             })
    //             ->addColumn('job_category', function ($sale) {
    //                 $type = $sale->job_type;
    //                 $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords($type) . ')' : '';
    //                 return $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';
    //             })
    //             ->addColumn('sale_postcode', function ($sale) {
    //                 if($sale->lat != null && $sale->lng != null){
    //                     $url = url('/sales/fetch-applicants-by-radius/'. $sale->id . '/15');
    //                     $button = '<a target="_blank" href="'. $url .'" style="color:blue;">'. $sale->formatted_postcode .'</a>'; // Using accessor
    //                 }else{
    //                     $button = $sale->formatted_postcode;
    //                 }
    //                 return $button;
    //             })
    //             ->addColumn('created_at', function ($sale) {
    //                 return $sale->formatted_created_at; // Using accessor
    //             })
    //             ->addColumn('updated_at', function ($sale) {
    //                 return $sale->formatted_updated_at; // Using accessor
    //             })
    //             ->addColumn('cv_limit', function ($sale) {
    //                 $status = $sale->no_of_sent_cv == $sale->cv_limit ? '<span class="badge w-100 bg-danger" style="font-size:90%" >' . $sale->no_of_sent_cv . '/' . $sale->cv_limit . '<br>Limit Reached</span>' : "<span class='badge w-100 bg-primary' style='font-size:90%'>" . ((int)$sale->cv_limit - (int)$sale->no_of_sent_cv . '/' . (int)$sale->cv_limit) . "<br>Limit Remains</span>";
    //                 return $status;
    //             })
    //             ->addColumn('sale_notes', function ($sale) {
    //                 $notes = nl2br(htmlspecialchars($sale->sale_notes, ENT_QUOTES, 'UTF-8'));
    //                 $notes = $notes ? $notes : 'N/A';
    //                 $postcode = htmlspecialchars($sale->sale_postcode, ENT_QUOTES, 'UTF-8');
    //                 $unit = Unit::find($sale->unit_id);
    //                 $unit_name = $unit ? ucwords($unit->unit_name) : '-';
    //                 $office = Office::find($sale->office_id);
    //                 $office_name = $office ? ucwords($office->office_name) : '-';

    //                 // Tooltip content with additional data-bs-placement and title
    //                 return '<a href="#" title="View Note" onclick="showNotesModal(\'' . (int)$sale->id . '\', \'' . $notes . '\', \'' . $office_name . '\', \'' . $unit_name . '\', \'' . strtoupper($postcode) . '\')">
    //                             <iconify-icon icon="solar:eye-scan-bold" class="text-primary fs-24"></iconify-icon>
    //                         </a>';
    //             })
    //             ->addColumn('qualification', function ($sale) {
    //                 $fullHtml = $sale->qualification; // HTML from Summernote
    //                 $id = 'qua-' . $sale->id;

    //                 // 0. Remove inline styles and <span> tags (to avoid affecting layout)
    //                 $cleanedHtml = preg_replace('/<(span|[^>]+) style="[^"]*"[^>]*>/i', '<$1>', $fullHtml);
    //                 $cleanedHtml = preg_replace('/<\/?span[^>]*>/i', '', $cleanedHtml);

    //                 // 1. Convert block-level and <br> tags into \n
    //                 $withBreaks = preg_replace(
    //                     '/<(\/?(p|div|li|br|ul|ol|tr|td|table|h[1-6]))[^>]*>/i',
    //                     "\n",
    //                     $cleanedHtml
    //                 );

    //                 // 2. Remove all other HTML tags except basic formatting tags
    //                 $plainText = strip_tags($withBreaks, '<b><strong><i><em><u>');

    //                 // 3. Decode HTML entities
    //                 $decodedText = html_entity_decode($plainText);

    //                 // 4. Normalize multiple newlines
    //                 $normalizedText = preg_replace("/[\r\n]+/", "\n", $decodedText);

    //                 // 5. Limit preview characters
    //                 $preview = Str::limit(trim($normalizedText), 80);

    //                 // 6. Convert newlines to <br>
    //                 $shortText = nl2br($preview);

    //                 return '
    //                     <a href="#"
    //                     data-bs-toggle="modal"
    //                     data-bs-target="#' . $id . '">'
    //                     . $shortText . '
    //                     </a>

    //                     <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
    //                         <div class="modal-dialog modal-lg modal-dialog-scrollable">
    //                             <div class="modal-content">
    //                                 <div class="modal-header">
    //                                     <h5 class="modal-title" id="' . $id . '-label">Sale Qualification</h5>
    //                                     <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    //                                 </div>
    //                                 <div class="modal-body">
    //                                     ' . $fullHtml . '
    //                                 </div>
    //                                 <div class="modal-footer">
    //                                     <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
    //                                 </div>
    //                             </div>
    //                         </div>
    //                     </div>';
    //             })
    //             ->addColumn('experience', function ($sale) {
    //                 $fullHtml = $sale->experience; // HTML from Summernote
    //                 $id = 'exp-' . $sale->id;

    //                 // 0. Remove inline styles and <span> tags (to avoid affecting layout)
    //                 $cleanedHtml = preg_replace('/<(span|[^>]+) style="[^"]*"[^>]*>/i', '<$1>', $fullHtml);
    //                 $cleanedHtml = preg_replace('/<\/?span[^>]*>/i', '', $cleanedHtml);

    //                 // 1. Convert block-level and <br> tags into \n
    //                 $withBreaks = preg_replace(
    //                     '/<(\/?(p|div|li|br|ul|ol|tr|td|table|h[1-6]))[^>]*>/i',
    //                     "\n",
    //                     $cleanedHtml
    //                 );

    //                 // 2. Remove all other HTML tags except basic formatting tags
    //                 $plainText = strip_tags($withBreaks, '<b><strong><i><em><u>');

    //                 // 3. Decode HTML entities
    //                 $decodedText = html_entity_decode($plainText);

    //                 // 4. Normalize multiple newlines
    //                 $normalizedText = preg_replace("/[\r\n]+/", "\n", $decodedText);

    //                 // 5. Limit preview characters
    //                 $preview = Str::limit(trim($normalizedText), 80);

    //                 // 6. Convert newlines to <br>
    //                 $shortText = nl2br($preview);

    //                 return '
    //                     <a href="#"
    //                     data-bs-toggle="modal"
    //                     data-bs-target="#' . $id . '">'
    //                     . $shortText . '
    //                     </a>

    //                     <div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-label" aria-hidden="true">
    //                         <div class="modal-dialog modal-lg modal-dialog-scrollable">
    //                             <div class="modal-content">
    //                                 <div class="modal-header">
    //                                     <h5 class="modal-title" id="' . $id . '-label">Sale Experience</h5>
    //                                     <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    //                                 </div>
    //                                 <div class="modal-body">
    //                                     ' . $fullHtml . '
    //                                 </div>
    //                                 <div class="modal-footer">
    //                                     <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
    //                                 </div>
    //                             </div>
    //                         </div>
    //                     </div>';
    //             })
    //             ->addColumn('status', function ($sale) {
    //                 $status = '';
    //                 if ($sale->status == 1 && $sale->is_on_hold == 1) {
    //                     $status = '<span class="badge bg-warning">On Hold</span>';
    //                 } elseif ($sale->status == 1 && $sale->is_re_open == 1) {
    //                     $status = '<span class="badge bg-dark">Re-Open</span>';
    //                 } elseif ($sale->status == 0) {
    //                     $status = '<span class="badge bg-danger">Closed</span>';
    //                 } elseif ($sale->status == 1) {
    //                     $status = '<span class="badge bg-success">Active</span>';
    //                 } elseif ($sale->status == 2) {
    //                     $status = '<span class="badge bg-warning">Pending</span>';
    //                 } elseif ($sale->status == 3) {
    //                     $status = '<span class="badge bg-danger">Rejected</span>';
    //                 }

    //                 return $status;
    //             })
    //             ->addColumn('action', function ($sale) {
    //                 $postcode = $sale->formatted_postcode;
    //                 $posted_date = $sale->formatted_created_at;
    //                 $office_id = $sale->office_id;
    //                 $office = Office::find($office_id);
    //                 $office_name = $office ? ucwords($office->office_name) : '-';
    //                 $unit_id = $sale->unit_id;
    //                 $unit = Unit::find($unit_id);
    //                 $unit_name = $unit ? ucwords($unit->unit_name) : '-';
    //                 $status_badge = '';
    //                 $jobTitle = $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
    //                 $type = $sale->job_type;
    //                 $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords($type) . ')' : '';
    //                 $jobCategory = $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';
                    
    //                 if ($sale->status == 1 && $sale->is_on_hold == 1) {
    //                     $status_badge = '<span class="badge bg-warning">On Hold</span>';
    //                 } elseif ($sale->status == 1 && $sale->is_re_open == 1) {
    //                     $status_badge = '<span class="badge bg-dark">Re-Open</span>';
    //                 } elseif ($sale->status == 0) {
    //                     $status_badge = '<span class="badge bg-danger">Closed</span>';
    //                 } elseif ($sale->status == 1) {
    //                     $status_badge = '<span class="badge bg-success">Active</span>';
    //                 } elseif ($sale->status == 2) {
    //                     $status_badge = '<span class="badge bg-warning">Pending</span>';
    //                 } elseif ($sale->status == 3) {
    //                     $status_badge = '<span class="badge bg-danger">Rejected</span>';
    //                 }
                    
    //                 $position_type = strtoupper(str_replace('-',' ',$sale->position_type));
    //                 $position = '<span class="badge bg-primary">'. $position_type .'</span>';

    //                 $action = '';
    //                 $action = '<div class="btn-group dropstart">
    //                             <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
    //                                 <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
    //                             </button>
    //                             <ul class="dropdown-menu">
    //                             <li><a class="dropdown-item" href="#" onclick="showDetailsModal(
    //                                 ' . $sale->id . ',
    //                                 \'' . e($posted_date) . '\',
    //                                 \'' . e($office_name) . '\',
    //                                 \'' . e($unit_name) . '\',
    //                                 \'' . e($postcode) . '\',
    //                                 \'' . e(strip_tags($jobCategory)) . '\',
    //                                 \'' . e(strip_tags($jobTitle)) . '\',
    //                                 \'' . e($status_badge) . '\',
    //                                 \'' . e($sale->timing) . '\',
    //                                 \'' . e(htmlspecialchars($sale->experience, ENT_QUOTES, 'UTF-8')) . '\',
    //                                 \'' . e($sale->salary) . '\',
    //                                 \'' . e(strip_tags($position)) . '\',
    //                                 \'' . e($sale->qualification) . '\',
    //                                 \'' . e($sale->benefits) . '\'
    //                             )">View</a></li>';
    //                 if ($sale->is_on_hold == 2) {
    //                     $action .= '<li><a class="dropdown-item" href="#" onclick="changeSaleOnHoldStatusModal(' . $sale->id .', 1)">Mark as Approved</a></li>';
    //                     $action .= '<li><a class="dropdown-item" href="#" onclick="changeSaleOnHoldStatusModal(' . $sale->id .', 0)">Mark as Dis-Approved</a></li>';
    //                 }
    //                 $action .= '<li><hr class="dropdown-divider"></li>
    //                                 <li><a class="dropdown-item" href="#" onclick="viewSaleDocuments(' . $sale->id . ')">View Documents</a></li>
    //                                 <li><a class="dropdown-item" href="#" onclick="viewNotesHistory(' . $sale->id . ')">Notes History</a></li>
    //                                 <li><a class="dropdown-item" href="#" onclick="viewManagerDetails(' . $sale->unit_id . ')">Manager Details</a></li>
    //                             </ul>
    //                         </div>';

    //                 return $action;
    //             })
    //             ->rawColumns(['sale_notes', 'experience', 'sale_postcode', 'qualification', 'cv_limit', 'job_title', 'job_category', 'office_name', 'unit_name', 'status', 'action'])
    //             ->make(true);
    //     }
    // }
    public function pendingOnHoldSales(Request $request)
    {
        $typeFilter = $request->input('type_filter', ''); // Default is empty (no filter)
        $categoryFilter = $request->input('category_filter', ''); // Default is empty (no filter)
        $titleFilter = $request->input('title_filter', ''); // Default is empty (no filter)
        $dateFlockFilter = $request->input('date_flock_filter', ''); // Default is empty (no filter)
        $dateRangeFilter = $request->input('date_range_filter', ''); // Default is empty (no filter)
        $limitCountFilter = $request->input('cv_limit_filter', ''); // Default is empty (no filter)
        $officeFilter = $request->input('office_filter', ''); // Default is empty (no filter)
        $userFilter = $request->input('user_filter', ''); // Default is empty (no filter)

        // Subquery to get the latest audit (open_date) for each sale
        $latestAuditSub = DB::table('audits')
            ->select(DB::raw('MAX(id) as id'))
            ->where('auditable_type', 'Horsefly\Sale')
            ->where('message', 'like', '%sale-opened%')
            ->whereIn('auditable_id', function($query) {
                $query->select('id')
                    ->from('sales'); // Ensure we only consider closed sales
            })
            ->groupBy('auditable_id');

        $model = Sale::query()
            ->select([
            'sales.*',
            'job_titles.name as job_title_name',
            'job_categories.name as job_category_name',
            'offices.office_name as office_name',
            'units.unit_name as unit_name',
            'users.name as user_name',
            'audits.created_at as open_date'
            ])
            ->where('sales.status', 1) // open sales
            ->where('sales.is_on_hold', 2) // Not on hold
            ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
            ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
            ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
            ->leftJoin('users', 'sales.user_id', '=', 'users.id')
             // Join only the latest audit for each sale
            ->leftJoin('audits', function ($join) use ($latestAuditSub) {
                $join->on('audits.auditable_id', '=', 'sales.id')
                    ->where('audits.auditable_type', '=', 'Horsefly\Sale')
                    ->where('audits.message', 'like', '%sale-opened%')
                    ->whereIn('audits.id', $latestAuditSub);
            })
            ->with(['jobTitle', 'jobCategory', 'unit', 'office', 'user'])
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

                    $query->orWhereHas('office', function ($q) use ($likeSearch) {
                        $q->where('offices.office_name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('user', function ($q) use ($likeSearch) {
                        $q->where('users.name', 'LIKE', "%{$likeSearch}%");
                    });
                });
            }
        }

        // Filter by type if it's not empty
        switch($typeFilter){
            case 'specialist':
                $model->where('sales.job_type', 'specialist');
                break;
            case 'regular':
                $model->where('sales.job_type', 'regular');
                break;
        }

        // Filter by user if it's not empty
        if ($userFilter) {
            $model->whereIn('sales.user_id', $userFilter);
        }
       
        // Filter by category if it's not empty
        if ($officeFilter) {
            $model->whereIn('sales.office_id', $officeFilter);
        }
        
        // Filter by category if it's not empty
        switch($limitCountFilter){
            case 'zero':
                $model->where('sales.cv_limit', '=', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1'
                    ));
                });
                break;
            case 'not max':
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count > 0 
                        AND sent_cv_count <> sales.cv_limit'
                    ));
                });
                break;
            case 'max':
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count = 0'
                    ));
                });
                break;
        }
       
        // Filter by category if it's not empty
        if ($categoryFilter) {
            $model->whereIn('sales.job_category_id', $categoryFilter);
        }
       
        // Filter by category if it's not empty
        if ($titleFilter) {
            $model->whereIn('sales.job_title_id', $titleFilter);
        }

        if ($dateRangeFilter) {
            // Parse the date range filter (format: "YYYY-MM-DD|YYYY-MM-DD")
            [$start_date, $end_date] = explode('|', $dateRangeFilter);
            $start_date = trim($start_date) . ' 00:00:00';
            $end_date = trim($end_date) . ' 23:59:59';

            $model->where(function ($query) use ($start_date, $end_date) {
                $query->whereBetween('sales.updated_at', [$start_date, $end_date])
                    ->orWhereBetween('audits.created_at', [$start_date, $end_date]);
            });
        }

        $now = Carbon::today();
        switch($dateFlockFilter) {
            case 'last-3-months':
                $startDate = $now->copy()->subMonths(3);
                $endDate = $now;

                $model->whereBetween('sales.updated_at', [$startDate->startOfDay(), $endDate->endOfDay()]);

                break;
                
            case 'last-6-months':
                $endDate = $now->copy()->subMonths(3);
                $startDate = $endDate->copy()->subMonths(6);
                $model->whereBetween('sales.updated_at', [$startDate->startOfDay(), $endDate->endOfDay()]);
                break;
                
            case 'last-9-months':
                $endDate = $now->copy()->subMonths(9);
                $startDate = $endDate->copy()->subMonths(9);
                $model->whereBetween('sales.updated_at', [$startDate->startOfDay(), $endDate->endOfDay()]);
                break;
                
            case 'other':
                $cutoffDate = $now->copy()->subMonths(18);
                $model->where('sales.updated_at', '<', $cutoffDate->endOfDay());
                break;
            default:
                $startDate = $now->copy()->subMonths(3);
                $endDate = $now;
                $model->whereBetween('sales.updated_at', [$startDate->startOfDay(), $endDate->endOfDay()]);
                break;
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
                ->addColumn('open_date', function ($sale) {
                    return $sale->open_date ? Carbon::parse($sale->open_date)->format('d M Y, h:i A') : '-'; // Using accessor
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
                ->addColumn('cv_limit', function ($sale) {
                    $status = $sale->no_of_sent_cv == $sale->cv_limit ? '<span class="badge w-100 bg-danger" style="font-size:90%" >' . $sale->no_of_sent_cv . '/' . $sale->cv_limit . '<br>Limit Reached</span>' : "<span class='badge w-100 bg-primary' style='font-size:90%'>" . ((int)$sale->cv_limit - (int)$sale->no_of_sent_cv . '/' . (int)$sale->cv_limit) . "<br>Limit Remains</span>";
                    return $status;
                })
                ->addColumn('sale_notes', function ($sale) {
                    $notes      = $sale->sale_notes ? nl2br(e($sale->sale_notes)) : 'N/A';
                    $postcode   = strtoupper($sale->sale_postcode ?? '-');
                    $unit_name  = $sale->unit ? ucwords($sale->unit->unit_name) : '-';
                    $office_name = $sale->office ? ucwords($sale->office->office_name) : '-';

                    $id = (int) $sale->id;

                    // Safely encode for JS
                    $notesJs    = htmlspecialchars(json_encode($notes), ENT_QUOTES, 'UTF-8');
                    $officeJs   = htmlspecialchars(json_encode($office_name), ENT_QUOTES, 'UTF-8');
                    $unitJs     = htmlspecialchars(json_encode($unit_name), ENT_QUOTES, 'UTF-8');
                    $postcodeJs = htmlspecialchars(json_encode($postcode), ENT_QUOTES, 'UTF-8');

                    return <<<HTML
                        <a href="#" title="View Note"
                        onclick="showNotesModal($id, $notesJs, $officeJs, $unitJs, $postcodeJs)">
                            <iconify-icon icon="solar:eye-scan-bold" class="text-primary fs-24"></iconify-icon>
                        </a>
                    HTML;
                })
                ->addColumn('status', function ($sale) {
                    $status = '';
                    if ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status = '<span class="badge bg-dark">Re-Open</span>';
                    } elseif ($sale->status == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

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
                                        <h5 class="modal-title" id="' . $id . '-label">Sale Salary</h5>
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
                ->addColumn('action', function ($sale) {
                    $postcode = $sale->formatted_postcode;
                    $posted_date = $sale->formatted_created_at;
                    $office_id = $sale->office_id;
                    $office = Office::find($office_id);
                    $office_name = $office ? ucwords($office->office_name) : '-';
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    $unit_name = $unit ? ucwords($unit->unit_name) : '-';
                    $status_badge = '';
                    $jobTitle = $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                    $type = $sale->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords($type) . ')' : '';
                    $jobCategory = $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';

                    if ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status_badge = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status_badge = '<span class="badge bg-dark">Re-Open</span>';
                    } elseif ($sale->status == 0) {
                        $status_badge = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 1) {
                        $status_badge = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 2) {
                        $status_badge = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status_badge = '<span class="badge bg-danger">Rejected</span>';
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
                                    ' . $sale->id . ',
                                    \'' . e($posted_date) . '\',
                                    \'' . e($office_name) . '\',
                                    \'' . e($unit_name) . '\',
                                    \'' . e($postcode) . '\',
                                    \'' . e(strip_tags($jobCategory)) . '\',
                                    \'' . e(strip_tags($jobTitle)) . '\',
                                    \'' . e($status_badge) . '\',
                                    \'' . e($sale->timing) . '\',
                                    \'' . e(htmlspecialchars($sale->experience, ENT_QUOTES, 'UTF-8')) . '\',
                                    \'' . e($sale->salary) . '\',
                                    \'' . e(strip_tags($position)) . '\',
                                    \'' . e($sale->qualification) . '\',
                                    \'' . e($sale->benefits) . '\'
                                )">View</a></li>';
                    $action .= '<li><a class="dropdown-item" href="#" onclick="changeSaleStatusModal(' . $sale->id . ', 0)">Mark as Close</a></li>';
                    if ($sale->status == '1' && $sale->is_on_hold == '0') {
                        $action .= '<li><a class="dropdown-item" href="#" onclick="changeSaleOnHoldStatusModal(' . $sale->id . ', 2)">Mark as On Hold</a></li>';
                    }
                    $url = route('sales.history', [ 'id' => $sale->id ]);
                    $action .= '<li><hr class="dropdown-divider"></li>
                                     <li><a class="dropdown-item" href="#" onclick="viewSaleDocuments(' . $sale->id . ')">View Documents</a></li>
                                    <li><a class="dropdown-item" href="'. $url .'" target="_blank">View History</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="viewNotesHistory(' . $sale->id . ')">Notes History</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="viewManagerDetails(' . $sale->unit_id . ')">Manager Details</a></li>
                                </ul>
                            </div>';

                    return $action;
                })
                ->rawColumns(['sale_notes', 'experience', 'salary','sale_postcode', 'qualification', 'cv_limit', 'job_title', 'open_date', 'job_category', 'office_name', 'unit_name', 'status', 'action', 'statusFilter'])
                ->make(true);
        }
    }
    public function getOnHoldSales(Request $request)
    {
        $typeFilter = $request->input('type_filter', ''); // Default is empty (no filter)
        $categoryFilter = $request->input('category_filter', ''); // Default is empty (no filter)
        $titleFilter = $request->input('title_filter', ''); // Default is empty (no filter)
        $limitCountFilter = $request->input('cv_limit_filter', ''); // Default is empty (no filter)
        $officeFilter = $request->input('office_filter', ''); // Default is empty (no filter)
        $userFilter = $request->input('user_filter', ''); // Default is empty (no filter)


        $model = Sale::query()
            ->select([
            'sales.*',
            'job_titles.name as job_title_name',
            'job_categories.name as job_category_name',
            'offices.office_name as office_name',
            'units.unit_name as unit_name',
            'users.name as user_name',
            ])
            ->where('sales.status', 1)
            ->where('sales.is_on_hold', 1)
            ->leftJoin('job_titles', 'sales.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'sales.job_category_id', '=', 'job_categories.id')
            ->leftJoin('offices', 'sales.office_id', '=', 'offices.id')
            ->leftJoin('units', 'sales.unit_id', '=', 'units.id')
            ->leftJoin('users', 'sales.user_id', '=', 'users.id')
            ->with(['jobTitle', 'jobCategory', 'unit', 'office', 'user'])
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

                    $query->orWhereHas('office', function ($q) use ($likeSearch) {
                        $q->where('offices.office_name', 'LIKE', "%{$likeSearch}%");
                    });

                    $query->orWhereHas('user', function ($q) use ($likeSearch) {
                        $q->where('users.name', 'LIKE', "%{$likeSearch}%");
                    });
                });
            }
        }

        // Filter by type if it's not empty
        switch($typeFilter){
            case 'specialist':
                $model->where('sales.job_type', 'specialist');
                break;
            case 'regular':
                $model->where('sales.job_type', 'regular');
                break;
        }
       
        // Filter by category if it's not empty
        if ($officeFilter) {
            $model->whereIn('sales.office_id', $officeFilter);
        }
        
        // Filter by category if it's not empty
        switch($limitCountFilter){
            case 'zero':
                $model->where('sales.cv_limit', '=', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1'
                    ));
                });
                break;
            case 'not max':
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count > 0 
                        AND sent_cv_count <> sales.cv_limit'
                    ));
                });
                break;
            case 'max':
                $model->where('sales.cv_limit', '>', function ($query) {
                    $query->select(DB::raw('count(cv_notes.sale_id) AS sent_cv_count 
                        FROM cv_notes WHERE cv_notes.sale_id=sales.id 
                        AND cv_notes.status = 1 HAVING sent_cv_count = 0'
                    ));
                });
                break;
        }
       
        // Filter by category if it's not empty
        if ($categoryFilter) {
            $model->whereIn('sales.job_category_id', $categoryFilter);
        }
       
        // Filter by category if it's not empty
        if ($titleFilter) {
            $model->whereIn('sales.job_title_id', $titleFilter);
        }

        // Filter by user if it's not empty
        if ($userFilter) {
            $model->whereIn('sales.user_id', $userFilter);
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
                ->addColumn('job_category', function ($sale) {
                    $type = $sale->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords($type) . ')' : '';
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
                                        <h5 class="modal-title" id="' . $id . '-label">Sale Salary</h5>
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
                    $notes      = $sale->sale_notes ? nl2br(e($sale->sale_notes)) : 'N/A';
                    $postcode   = strtoupper($sale->sale_postcode ?? '-');
                    $unit_name  = $sale->unit ? ucwords($sale->unit->unit_name) : '-';
                    $office_name = $sale->office ? ucwords($sale->office->office_name) : '-';

                    $id = (int) $sale->id;

                    // Safely encode for JS
                    $notesJs    = htmlspecialchars(json_encode($notes), ENT_QUOTES, 'UTF-8');
                    $officeJs   = htmlspecialchars(json_encode($office_name), ENT_QUOTES, 'UTF-8');
                    $unitJs     = htmlspecialchars(json_encode($unit_name), ENT_QUOTES, 'UTF-8');
                    $postcodeJs = htmlspecialchars(json_encode($postcode), ENT_QUOTES, 'UTF-8');

                    return <<<HTML
                        <a href="#" title="View Note"
                        onclick="showNotesModal($id, $notesJs, $officeJs, $unitJs, $postcodeJs)">
                            <iconify-icon icon="solar:eye-scan-bold" class="text-primary fs-24"></iconify-icon>
                        </a>
                    HTML;
                })
                ->addColumn('status', function ($sale) {
                    $status = '';
                    if ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status = '<span class="badge bg-dark">Re-Open</span>';
                    } elseif ($sale->status == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

                    return $status;
                })
                ->addColumn('action', function ($sale) {
                    $postcode = $sale->formatted_postcode;
                    $posted_date = $sale->formatted_created_at;
                    $office_id = $sale->office_id;
                    $office = Office::find($office_id);
                    $office_name = $office ? ucwords($office->office_name) : '-';
                    $unit_id = $sale->unit_id;
                    $unit = Unit::find($unit_id);
                    $unit_name = $unit ? ucwords($unit->unit_name) : '-';
                    $status_badge = '';
                    $jobTitle = $sale->jobTitle ? strtoupper($sale->jobTitle->name) : '-';
                    $type = $sale->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords($type) . ')' : '';
                    $jobCategory = $sale->jobCategory ? ucwords($sale->jobCategory->name) . $stype : '-';

                    $position_type = strtoupper(str_replace('-',' ',$sale->position_type));
                    $position = '<span class="badge bg-primary">'. $position_type .'</span>';

                    if ($sale->status == 1 && $sale->is_on_hold == 1) {
                        $status_badge = '<span class="badge bg-warning">On Hold</span>';
                    } elseif ($sale->status == 1 && $sale->is_re_open == 1) {
                        $status_badge = '<span class="badge bg-dark">Re-Open</span>';
                    } elseif ($sale->status == 0) {
                        $status_badge = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($sale->status == 1) {
                        $status_badge = '<span class="badge bg-success">Active</span>';
                    } elseif ($sale->status == 2) {
                        $status_badge = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($sale->status == 3) {
                        $status_badge = '<span class="badge bg-danger">Rejected</span>';
                    }
                    
                    $action = '';
                    $action .= '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#" onclick="showDetailsModal(
                                    ' . $sale->id . ',
                                    \'' . e($posted_date) . '\',
                                    \'' . e($office_name) . '\',
                                    \'' . e($unit_name) . '\',
                                    \'' . e($postcode) . '\',
                                    \'' . e(strip_tags($jobCategory)) . '\',
                                    \'' . e(strip_tags($jobTitle)) . '\',
                                    \'' . e($status_badge) . '\',
                                    \'' . e($sale->timing) . '\',
                                    \'' . e(htmlspecialchars($sale->experience, ENT_QUOTES, 'UTF-8')) . '\',
                                    \'' . e($sale->salary) . '\',
                                    \'' . e(strip_tags($position)) . '\',
                                    \'' . e($sale->qualification) . '\',
                                    \'' . e($sale->benefits) . '\'
                                )">View</a></li>
                                <li><a class="dropdown-item" href="#" onclick="changeSaleOnHoldStatusModal(' . $sale->id . ', 0)">Mark as Unhold</a></li>';
                    $action .= '<li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="#" onclick="viewSaleDocuments(' . $sale->id . ')">View Documents</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="viewNotesHistory(' . $sale->id . ')">Notes History</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="viewManagerDetails(' . $sale->unit_id . ')">Manager Details</a></li>
                                </ul>
                            </div>';

                    return $action;
                })
                ->rawColumns(['sale_notes', 'experience', 'salary', 'sale_postcode', 'qualification', 'cv_limit', 'job_title', 'job_category', 'office_name', 'unit_name', 'status', 'action'])
                ->make(true);
        }
    }
    public function getApplicantsBySaleRadius(Request $request)
    {
        $statusFilter = $request->input('status_filter', ''); // Default is empty (no filter)
        $searchTerm = $request->input('search', ''); // This will get the search query
        $sale_id = $request->input('sale_id', ''); // This will get the search query
        $radius = $request->input('radius', ''); // This will get the search query
        
        $sale = Sale::find($sale_id);
        $lat = $sale->lat;
        $lon = $sale->lng;

        $model = Applicant::query()->with('cv_notes', 'pivotSales', 'history_request_nojob')
            ->select([
                'applicants.*',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'job_sources.name as job_source_name',
                DB::raw("(ACOS(SIN($lat * PI() / 180) * SIN(lat * PI() / 180) + 
                    COS($lat * PI() / 180) * COS(lat * PI() / 180) * 
                    COS(($lon - lng) * PI() / 180)) * 180 / PI() * 60 * 1.852) AS distance")
            ])
            ->where('applicants.status', 1)
            ->where("is_in_nurse_home", false)
            ->having('distance', '<', $radius)
            ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
            ->leftJoin('job_sources', 'applicants.job_source_id', '=', 'job_sources.id')
            ->with(['jobTitle', 'jobCategory', 'jobSource'])
            ->selectRaw("
                CASE
                    WHEN applicants.paid_status = 'close' THEN 1
                    WHEN EXISTS (SELECT 1 FROM cv_notes WHERE cv_notes.applicant_id = applicants.id AND cv_notes.status = 1) THEN 2
                    WHEN EXISTS (SELECT 1 FROM cv_notes WHERE cv_notes.applicant_id = applicants.id AND cv_notes.status = 0 AND cv_notes.sale_id = ?) THEN 3
                    WHEN EXISTS (SELECT 1 FROM cv_notes WHERE cv_notes.applicant_id = applicants.id AND cv_notes.status = 0) THEN 4
                    WHEN EXISTS (SELECT 1 FROM cv_notes WHERE cv_notes.applicant_id = applicants.id AND cv_notes.status = 2 AND cv_notes.sale_id = ? AND applicants.paid_status = 'open') THEN 5
                    ELSE 6
                END AS paid_status_order
            ", [$sale_id, $sale_id]);

        $jobTitle = JobTitle::find($sale->job_title_id);

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

        $model->whereIn('applicants.job_title_id', $jobTitleIds);

        // Sorting logic
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            // Handle special cases first
            if ($orderColumn === 'job_source') {
                $model->orderBy('job_source_id', $orderDirection);
            } elseif ($orderColumn === 'job_category') {
                $model->orderBy('job_category_id', $orderDirection);
            } elseif ($orderColumn === 'job_title') {
                $model->orderBy('job_title_id', $orderDirection);
            }
            // Default case for valid columns
            elseif ($orderColumn && $orderColumn !== 'DT_RowIndex' && $orderColumn !== 'checkbox') {
                $model->orderBy($orderColumn, $orderDirection);
            }
            // Fallback if no valid order column is found
            else {
                $model->orderBy('applicants.updated_at', 'desc');
            }
        } else {
            // Default sorting when no order is specified
            $model->orderBy('applicants.updated_at', 'desc');
        }

        // Filter by status if it's not empty
        switch($statusFilter){
            case 'interested':
                $model->where('is_no_job', false)
                    ->where('is_blocked', false)
                    ->where(function ($query) {
                        // Check for combinations of 'temp_not_interested' and 'is_callback_enable'
                        $query->where(function ($subQuery) {
                            $subQuery->where("is_temp_not_interested", false)
                                ->where("is_callback_enable", true);
                        })
                            ->orWhere(function ($subQuery) {
                                $subQuery->where("is_temp_not_interested", true)
                                    ->where("is_callback_enable", true);
                            })
                            ->orWhere(function ($subQuery) {
                                $subQuery->where("is_temp_not_interested", false)
                                    ->where("is_callback_enable", false);
                            })
                        ;
                    })
                    ->where(function ($query) {
                        $query->where('have_nursing_home_experience', false)
                            ->orWhereNull('have_nursing_home_experience');
                    })
                    ->whereDoesntHave('pivotSales', function ($query) use ($sale_id) {
                        $query->where('sale_id', $sale_id); 
                    });
                break;
            case 'not interested':
                $model->where('is_no_job', false)
                    ->where('is_blocked', false)
                    ->where("is_callback_enable", false)
                    ->where(function ($query) use ($sale_id) {
                        $query->where("is_temp_not_interested", true)
                            ->orWhereHas('pivotSales', function ($query) use ($sale_id) {
                                $query->where('sale_id', $sale_id); 
                            });
                    })
                    ->where(function ($query) {
                        $query->where('have_nursing_home_experience', false)
                            ->orWhereNull('have_nursing_home_experience');
                    })
                   ->where(function($query) use ($sale_id) {
                        $query->doesntHave('history_request_nojob')
                            ->orWhereDoesntHave('history_request_nojob', function($q) use ($sale_id) {
                                $q->where('sale_id', $sale_id);
                            });
                    });
                break;
            case 'blocked':
                $model->where('is_no_job', false)
                    ->where('is_blocked', true)
                    ->where("is_callback_enable", false)
                    ->where("is_temp_not_interested", false)
                    ->where(function ($query) {
                        $query->where('have_nursing_home_experience', false)
                            ->orWhereNull('have_nursing_home_experience');
                    });
                break;
            case 'callback':
                $model->where("is_callback_enable", true);
                break;
            case 'have nursing home experience':
                $model->where('have_nursing_home_experience', true);
                break;
            case 'no job':
                $model->where(function ($query) {
                    $query->where('is_no_job', true)
                        ->where('is_callback_enable', false);
                })
                ->where(function ($query) {
                    $query->where('have_nursing_home_experience', false)
                        ->orWhereNull('have_nursing_home_experience');
                })
                ->orWhereHas('history_request_nojob', function ($query) use ($sale_id) {
                    $query->where('sale_id', $sale_id)
                        ->orderBy('id', 'desc')
                        ->take(1); 
                });
                break;

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
                });
            }
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn() // This will automatically add a serial number to the rows
                 ->addColumn('checkbox', function ($applicant) {
                    return '<input type="checkbox" name="applicant_checkbox[]" class="applicant_checkbox" value="' . $applicant->id . '"/>';
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
                    if($applicant->applicant_email_secondary){
                        $email = $applicant->applicant_email .'<br>'.$applicant->applicant_email_secondary; 
                    }else{
                        $email = $applicant->applicant_email;
                    }

                    return $email; // Using accessor
                })  
                ->addColumn('applicant_experience', function ($applicant) {
                    $short = Str::limit(strip_tags($applicant->applicant_experience), 80);
                    $full = e($applicant->applicant_experience);
                    $id = 'exp-' . $applicant->id;

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
                ->addColumn('applicant_postcode', function ($applicant) {
                    if($applicant->lat != null && $applicant->lng != null){
                        $url = route('applicants.available_job', ['id' => $applicant->id, 'radius' => 15]);
                        $button = '<a href="'. $url .'" style="color:blue;">'. $applicant->formatted_postcode .'</a>'; // Using accessor
                    }else{
                        $button = $applicant->formatted_postcode;
                    }
                    return $button;
                })
                ->addColumn('applicant_notes', function ($applicant) {
                    $notes = nl2br(htmlspecialchars($applicant->applicant_notes, ENT_QUOTES, 'UTF-8'));
                    return '
                        <a href="#" title="Add Short Note" style="color:blue" onclick="addShortNotesModal(\'' . (int)$applicant->id . '\')">
                            ' . $notes . '
                        </a>
                    ';
                })
                ->addColumn('applicant_phone', function ($applicant) {
                    $strng = '';
                    if($applicant->applicant_landline){
                        $phone = '<strong>P:</strong> '.$applicant->applicant_phone;
                        $landline = '<strong>L:</strong> '.$applicant->applicant_landline;

                        $strng = $applicant->is_blocked ? "<span class='badge bg-dark'>Blocked</span>" : $phone .'<br>'. $landline;
                    }else{
                        $phone = '<strong>P:</strong> '.$applicant->applicant_phone;
                        $strng = $applicant->is_blocked ? "<span class='badge bg-dark'>Blocked</span>" : $phone;
                    }

                    return $strng;
                })
                ->addColumn('created_at', function ($applicant) {
                    return $applicant->formatted_created_at; // Using accessor
                })
                ->addColumn('updated_at', function ($applicant) {
                    return $applicant->formatted_updated_at; // Using accessor
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
                ->addColumn('paid_status', function ($applicant) use ($sale_id) {
                    $status_value = 'open';
                    $color_class = 'bg-success';
                    if ($applicant->paid_status == 'close') {
                        $status_value = 'paid';
                        $color_class = 'bg-primary';
                    } else {
                        foreach ($applicant->cv_notes as $key => $value) {
                            if ($value['status'] == 1) {//active
                                $status_value = 'sent';
                                break;
                            } elseif (($value['status'] == 0) && ($value['sale_id'] == $sale_id)) {
                                $status_value = 'reject_job';
                                $color_class = 'bg-danger';
                                break;
                            } elseif ($value['status'] == 0) {//disable
                                $status_value = 'reject';
                                $color_class = 'bg-danger';
                            } elseif (($value['status'] == 2) && //2 for paid
                            ($value['sale_id'] == $sale_id) && 
                            ($applicant->paid_status == 'open')) {
                                $status_value = 'paid';
                                $color_class = 'bg-primary';
                                break;
                            }
                        }
                    }
                    $status = '';
                    $status .= '<span class="badge ' . $color_class . '">';
                    $status .= ucwords($status_value);
                    $status .= '</span>';

                    return $status;
                })
                ->orderColumn('paid_status', 'paid_status_order $1')
                ->addColumn('action', function ($applicant) use ($sale_id) {
                    $status_value = 'open';
                    if ($applicant->paid_status == 'close') {
                        $status_value = 'paid';
                    } else {
                        foreach ($applicant->cv_notes as $key => $value) {
                            if ($value['status'] == 1) {//active
                                $status_value = 'sent';
                            } elseif (($value['status'] == 0) && ($value['sale_id'] == $sale_id)) {
                                $status_value = 'reject_job';
                            } elseif ($value['status'] == 0) {//disable
                                $status_value = 'reject';
                            } elseif (($value['status'] == 2) && //2 for paid
                                ($value['sale_id'] == $sale_id) && 
                                ($applicant['paid_status'] == 'open')) 
                            {
                                $status_value = 'paid';
                            }
                        }
                    }

                    $html = '<div class="btn-group dropstart">
                            <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                            </button>
                            <ul class="dropdown-menu">';
                                if ($status_value == 'open' || $status_value == 'reject') {
                                    $html .= '<li><a href="#" onclick="markNotInterestedModal('. $applicant->id .', '. $sale_id .')" 
                                                        class="dropdown-item">
                                                        Mark Not Interested On Sale
                                                    </a></li>

                                                <li><a href="#" class="dropdown-item" onclick="markNoNursingHomeModal('. $applicant->id .')">
                                                        Mark No Nursing Home</a></li>

                                                <li><a href="#" onclick="sendCVModal('. $applicant->id .', '. $sale_id .')" class="dropdown-item" >
                                                    <span>Send CV</span></a></li>
                                            
                                                <li><a href="#" class="dropdown-item"  onclick="markApplicantCallbackModal('. $applicant->id .', '. $sale_id .')">Mark Callback</a></li>';
                                } elseif ($status_value == 'sent' || $status_value == 'reject_job' || $status_value == 'paid') {
                                    $html .= '<button type="button" class="btn btn-light btn-sm disabled d-inline-flex align-items-center">
                                            <iconify-icon icon="solar:lock-bold" class="fs-14 me-1"></iconify-icon> Locked
                                        </button>';
                                }

                                $html .= '</ul>
                        </div>';

                    return $html;
                })
                ->rawColumns(['checkbox', 'applicant_postcode', 'applicant_experience', 'applicant_notes', 'applicant_email', 'applicant_phone', 'job_title', 'crm_resume', 'applicant_resume', 'paid_status', 'job_category', 'job_source', 'action'])
                ->with(['sale_id' => $sale_id])
                ->make(true);
        }
    }
    public function storeSaleNotes(Request $request)
    {
        $user = Auth::user();

        $sale_id = $request->input('sale_id');
        $details = $request->input('details');
        $sale_notes = $details . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y');

        $updateData = ['sale_notes' => $sale_notes];

        Sale::where('id', $sale_id)->update($updateData);

        $sale_note = SaleNote::create([
            'sale_id' => $sale_id,
            'sale_note' => $sale_notes,
            'user_id' => $user->id,
        ]);

        $sale_note->update(['sales_notes_uid' => md5($sale_note->id)]);

        $sale = Sale::findOrFail($sale_id);
        $audit = new ActionObserver();
        $audit->customSaleAudit($sale, 'sale_notes');

        // Disable previous module note
        ModuleNote::where([
                'module_noteable_id' => $sale_id,
                'module_noteable_type' => 'Horsefly\Sale'
            ])
            ->where('status', 1)
            ->update(['status' => 0]);

        // Create new module note
        $moduleNote = ModuleNote::create([
            'details' => $sale_notes,
            'module_noteable_id' => $sale_id,
            'module_noteable_type' => 'Horsefly\Sale',
            'user_id' => $user->id,
        ]);

        $moduleNote->update(['module_note_uid' => md5($moduleNote->id)]);

        return redirect()->to(url()->previous());
    }
    public function changeSaleStatus(Request $request)
    {
        $user = Auth::user();

        $sale_id = $request->input('sale_id');
        $status = $request->input('status');
        $details = $request->input('details');
        $sale_notes = $details . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y');

        if($status == 1){
            $updateData = [
                'sale_notes' => $sale_notes,
                'status' => 2, // Assuming 2 is for pending
                'is_on_hold' => false,
                'is_re_open' => false
            ];
        }else{
            $updateData = [
                'sale_notes' => $sale_notes,
                'status' => $status, // Assuming 2 is for pending
                'is_on_hold' => false,
                'is_re_open' => false
            ];
        }

        $sale = Sale::findOrfail($sale_id);
        $sale->update($updateData);

        $audit = new ActionObserver();
        $audit->changeSaleStatus($sale, ['status' => $status]);

        // Disable previous module note
        ModuleNote::where([
                'module_noteable_id' => $sale_id,
                'module_noteable_type' => 'Horsefly\Sale'
            ])
            ->where('status', 1)
            ->update(['status' => 0]);

        // Create new module note
        $moduleNote = ModuleNote::create([
            'details' => $sale_notes,
            'module_noteable_id' => $sale_id,
            'module_noteable_type' => 'Horsefly\Sale',
            'user_id' => $user->id,
            'status' => 1,
        ]);

        $moduleNote->update(['module_note_uid' => md5($moduleNote->id)]);

        return redirect()->to(url()->previous());
    }
    public function changeSaleHoldStatus(Request $request)
    {
        $user = Auth::user();

        $sale_id = $request->input('id');
        $status = $request->input('status');
        $details = $request->input('details');
        $sale_notes = $details . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y');

        if(isset($request->details)){
            $updateData = [
                'is_on_hold' => $status,
                'sale_notes' => $sale_notes
            ];
        }else{
            $updateData = [
                'is_on_hold' => $status
            ];
        }

        $sale = Sale::FindOrfail($sale_id);
        $sale->update($updateData);

        // Disable previous module note
        ModuleNote::where([
                'module_noteable_id' => $sale_id,
                'module_noteable_type' => 'Horsefly\Sale'
            ])
            ->where('status', 1)
            ->update(['status' => 0]);

        // Create new module note
        $moduleNote = ModuleNote::create([
            'details' => $sale_notes,
            'module_noteable_id' => $sale_id,
            'module_noteable_type' => 'Horsefly\Sale',
            'user_id' => $user->id,
        ]);

        $moduleNote->update(['module_note_uid' => md5($moduleNote->id)]);

        $audit = new ActionObserver();
        $audit->changeSaleOnHoldStatus($sale, ['status' => $status]);

        return redirect()->to(url()->previous());
    }
    public function saleHistoryIndex($id)
    {
        $sale = Sale::withCount('active_cvs')->find($id);

        if (!$sale) {
            return redirect()->back()->with('error', 'Sale not found.');
        }   
        $office = Office::where('id', $sale->office_id)->select('office_name')->first();
        $unit = Unit::where('id', $sale->unit_id)->select('unit_name')->first();
        $jobCategory = JobCategory::where('id', $sale->job_category_id)->select('name')->first();
        $jobTitle = JobTitle::where('id', $sale->job_title_id)->select('name')->first();
        $jobType = ucwords(str_replace('-', ' ', $sale->job_type));
        $jobType = $jobType == 'Specialist' ? ' (' . $jobType . ')' : '';
        $postcode = ucwords($sale->sale_postcode);
        $active_cvs_count = $sale->active_cvs_count;
        $cv_limit = $sale->cv_limit;

        $badgeColor = '';

        if($cv_limit <= $active_cvs_count){
            $badgeColor = 'bg-danger';
        }else{
            $badgeColor = 'bg-success';
        }

        return view('sales.history', compact('sale', 'office', 'unit', 'jobCategory', 'jobTitle', 'jobType', 'postcode', 'active_cvs_count', 'cv_limit', 'badgeColor'));
    }
    public function getOfficeUnits(Request $request)
    {
        $units = Unit::where('office_id', $request->input('office_id'))
            ->where('status', 1)
            ->select('id', 'unit_name')
            ->get();

        return response()->json($units);
    }
    public function removeDocument(Request $request)
    {
        $documentId = $request->input('id');

        try {
            // Find the document
            $document = SaleDocument::findOrFail($documentId);

            $sale = Sale::findOrFail($document->sale_id);
            $audit = new ActionObserver();
            $audit->customSaleAudit($sale, 'document_removed');

            // Delete the file from the directory
            $filePath = storage_path('app/public/' . $document->document_path);
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            // Delete the document record from the database
            $document->delete();

            return response()->json([
                'success' => true,
                'message' => 'Document removed successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error removing document: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while removing the document. Please try again.',
            ], 500);
        }
    }
    public function export(Request $request)
    {
        $type = $request->query('type', 'all'); // Default to 'all' if not provided
        $status = $request->query('type', '');

        if($type == 'declined'){
            $filename = 'crm_declined_data_'.Carbon::now()->format('d-M-Y');
        }elseif($type == 'not_attended'){
            $filename = 'crm_not_attended_data_'.Carbon::now()->format('d-M-Y');
        }elseif($type == 'start_date_hold'){
            $filename = 'crm_start_date_hold_data_'.Carbon::now()->format('d-M-Y');
        }elseif($type == 'dispute'){
            $filename = 'crm_disputed_data_'.Carbon::now()->format('d-M-Y');
        }elseif($type == 'paid'){
            $filename = 'crm_paid_data_'.Carbon::now()->format('d-M-Y');
        }else{
           $filename = 'sales_'.$type;
        }
        
        return Excel::download(new SalesExport($type, $status), $filename.".csv");
    }
    public function getSaleDocuments(Request $request)
    {
        try {
            // Validate the incoming request to ensure 'id' is provided and is a valid integer
            $request->validate([
                'id' => 'required|integer',  // Assuming 'module_notes' is the table name and 'id' is the primary key
            ]);

            // Fetch the module notes by the given ID
            $document = SaleDocument::where('sale_id', $request->id)->latest()->get();

            // Check if the module note was found
            if (!$document) {
                return response()->json(['error' => 'Document not found'], 404);  // Return 404 if not found
            }

            // Return the specific fields you need (e.g., applicant name, notes, etc.)
            return response()->json([
                'data' => $document,
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
    public function getSaleHistoryAjaxRequest(Request $request)
    {
        $sale_id = $request->sale_id;
        // Prepare CRM Notes query
        $model = Applicant::query()
            ->join(DB::raw('
                (
                    SELECT *
                    FROM crm_notes
                    WHERE id IN (
                        SELECT MAX(id)
                        FROM crm_notes
                        GROUP BY applicant_id, sale_id
                    )
                ) AS crm_notes
            '), 'crm_notes.applicant_id', '=', 'applicants.id')
            ->join('sales', 'sales.id', '=', 'crm_notes.sale_id')
            ->join('offices', 'offices.id', '=', 'sales.office_id')
            ->join('units', 'units.id', '=', 'sales.unit_id')
            ->join('history', function($join) {
                $join->on('crm_notes.applicant_id', '=', 'history.applicant_id')
                    ->on('crm_notes.sale_id', '=', 'history.sale_id');
            })
             ->select([
                'applicants.id',
                'applicants.applicant_name',
                'applicants.applicant_postcode',
                'applicants.applicant_phone',
                'applicants.applicant_landline',
                'applicants.job_category_id',
                'applicants.job_title_id',
                'applicants.job_type',
                'job_titles.name as job_title_name',
                'job_categories.name as job_category_name',
                'history.created_at',
                'history.stage',
                'history.sub_stage',
                'crm_notes.details as note_details',
                'crm_notes.created_at as notes_created_at',
            ])
            ->leftJoin('job_titles', 'applicants.job_title_id', '=', 'job_titles.id')
            ->leftJoin('job_categories', 'applicants.job_category_id', '=', 'job_categories.id')
            ->with(['jobTitle', 'jobCategory'])
            ->where([
                'crm_notes.sale_id' => $sale_id,
                'history.status' => 1
            ]);

        // Sorting logic
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');
            // Handle special cases first
            if ($orderColumn === 'job_category') {
                $model->orderBy('job_category_id', $orderDirection);
            } elseif ($orderColumn === 'job_title') {
                $model->orderBy('job_title_id', $orderDirection);
            }
            // Default case for valid columns
            elseif ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $model->orderBy($orderColumn, $orderDirection);
            } else {
                $model->orderBy('history.created_at', 'desc');
            }
        } else {
            $model->orderBy('history.created_at', 'desc');
        }

        // Apply search filter BEFORE sending to DataTables
        if ($request->has('search.value')) {
            $searchTerm = $request->input('search.value');
            $model->where(function ($query) use ($searchTerm) {
                $query->where('history.sub_stage', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('history.stage', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('history.created_at', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('crm_notes.details', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('applicants.applicant_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('applicants.applicant_phone', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('applicants.applicant_landline', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('applicants.applicant_postcode', 'LIKE', "%{$searchTerm}%");

                // Relationship searches with explicit table names
                $query->orWhereHas('jobTitle', function ($q) use ($searchTerm) {
                    $q->where('job_titles.name', 'LIKE', "%{$searchTerm}%");
                });

                $query->orWhereHas('jobCategory', function ($q) use ($searchTerm) {
                    $q->where('job_categories.name', 'LIKE', "%{$searchTerm}%");
                });
            });
        }

        // Handle AJAX request
        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn()
                ->addColumn('created_at', function ($row) {
                    return Carbon::parse($row->created_at)->format('d M Y, h:i A');
                })
                ->addColumn('job_title', function ($row) {
                    return $row->jobTitle ? strtoupper($row->jobTitle->name) : '-';
                })
                ->addColumn('job_category', function ($row) {
                    $type = $row->job_type;
                    $stype  = $type && $type == 'specialist' ? '<br>(' . ucwords('Specialist') . ')' : '';
                    return $row->jobCategory ? $row->jobCategory->name . $stype : '-';
                })
                ->addColumn('stage', function ($row) {
                    return strtoupper($row->stage);
                })
                ->addColumn('sub_stage', function ($row) {
                    return '<span class="badge bg-primary">' . ucwords(str_replace('_', ' ', $row->sub_stage)) . '</span>';
                })
                ->addColumn('details', function ($row) {
                    $short = Str::limit(strip_tags($row->note_details), 100);
                    $full = e($row->note_details);
                    $id = 'exp-' . $row->id;

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
                    // Tooltip content with additional data-bs-placement and title
                    return $notes;
                })
                ->addColumn('action', function ($row) use ($sale_id) {
                    // Tooltip content with additional data-bs-placement and title
                    return '<a href="#" title="View All Notes" onclick="viewNotesHistory(\'' . (int)$row->id . '\',\'' . (int)$sale_id . '\')">
                                <iconify-icon icon="solar:clipboard-text-bold" class="text-info fs-24"></iconify-icon>
                            </a>';
                })
                ->rawColumns(['details', 'job_category', 'stage', 'job_title', 'action', 'sub_stage'])
                ->make(true);
        }
    }
}
