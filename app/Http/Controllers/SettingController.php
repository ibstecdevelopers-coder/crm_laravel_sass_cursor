<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Horsefly\Role;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use Horsefly\EmailTemplate;
use Horsefly\JobCategory;
use Horsefly\JobSource;
use Horsefly\JobTitle;
use Horsefly\User;
use Horsefly\SmsTemplate;
use Horsefly\SmtpSetting;
use Horsefly\Setting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class SettingController extends Controller
{
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
        return view('settings.list');
    }
    public function emailTemplatesIndex()
    {
        $users = DB::table('users')->where('is_active', true)->orderBy('name', 'asc')->get();

        return view('settings.email-templates', compact('users'));
    }
    public function smsTemplatesIndex()
    {
        $users = DB::table('users')->where('is_active', true)->orderBy('name', 'asc')->get();
        return view('settings.sms-templates', compact('users'));
    }
    public function jobCategoriesIndex()
    {
        $users = DB::table('users')->where('is_active', true)->orderBy('name', 'asc')->get();

        return view('job-categories.list', compact('users'));
    }
    public function jobTitlesIndex()
    {
        $categories = JobCategory::where('is_active', true)->orderBy('name', 'asc')->get();
        $allTitles = JobTitle::orderBy('name', 'asc')->get();

        return view('job-titles.list', compact('categories', 'allTitles'));
    }
    public function jobSourceIndex()
    {
        return view('job-sources.list');
    }
    public function create()
    {
        return view('settings.create');
    }
    public function store(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:roles,name',
            // 'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        try {
            // Create the role
            $role = \Spatie\Permission\Models\Role::create([
                'name' => $request->input('name'),
                'guard_name' => 'web',
            ]);

            // Assign permissions to the role
            $role->syncPermissions($request->input('permissions'));

            return response()->json([
                'success' => true,
                'message' => 'Role created successfully',
                'redirect' => route('roles.list')
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating role: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating the role. Please try again.'
            ], 500);
        }
    }
    public function jobCategoriesStore(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:job_categories,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        try {
            // Create the role
            JobCategory::create([
                'name' => $request->input('name'),
                'description' => $request->input('name')
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Job Category created successfully',
                'redirect' => route('job-categories.list')
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating job category: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating the job category. Please try again.'
            ], 500);
        }
    }
    public function jobTitlesStore(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('job_titles')->where(function ($query) use ($request) {
                    return $query->where('job_category_id', $request->job_category_id);
                })
            ],
            'type' => 'required',
            'job_category_id' => 'required',
            'related_titles' => 'nullable|array',
            'related_titles.*' => 'string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        try {
            // Create the role
            JobTitle::create([
                'name' => strtoupper($request->input('name')),
                'type' => $request->input('type'),
                'job_category_id' => $request->input('job_category_id'),
                'description' => $request->input('name'),
                'added_by' => Auth::user()->id,
                'related_titles' => $request->input('related_titles', []),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Job Title created successfully',
                'redirect' => route('job-titles.list')
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating job title: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating the job title. Please try again.'
            ], 500);
        }
    }
    public function jobSourceStore(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:job_sources,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        try {
            // Create the role
            JobSource::create([
                'name' => $request->input('name'),
                'description' => $request->input('name'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Job Source created successfully',
                'redirect' => route('job-sources.list')
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating job source: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating the job source. Please try again.'
            ], 500);
        }
    }
    public function getJobCategories(Request $request)
    {
        $statusFilter = $request->input('status_filter', ''); // Default is empty (no filter)

        $query = JobCategory::query();

        // Search filter
        if ($request->has('search.value')) {
            $searchTerm = strtolower($request->input('search.value'));
            if (!empty($searchTerm)) {
                $query->whereRaw('LOWER(name) LIKE ?', ["%{$searchTerm}%"]);
            }
        }

        // Filter by status if it's not empty
        if ($statusFilter == 'active') {
            $query->where('is_active', 1);
        } elseif ($statusFilter == 'inactive') {
            $query->where('is_active', 0);
        }

        // Sorting
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');
            if ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $query->orderBy($orderColumn, $orderDirection);
            } else {
                $query->orderBy('created_at', 'desc');
            }
        } else {
            $query->orderBy('created_at', 'desc');
        }

        if ($request->ajax()) {
            return DataTables::eloquent($query)
                ->addIndexColumn()
                ->addColumn('created_at', function ($cat) {
                    return $cat->created_at ? $cat->created_at->format('d-m-Y, h:i A') : '-';
                })
                ->addColumn('is_active', function ($cat) {
                    $status = '';
                    if ($cat->is_active) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } else {
                        $status = '<span class="badge bg-danger">Inactive</span>';
                    }

                    return $status;
                })
                ->addColumn('action', function ($cat) {
                    $name = ucwords($cat->name);
                    return '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">
                                     <li>
                                        <a class="dropdown-item" href="#" onclick="showEditModal(
                                            \'' . $cat->id . '\',
                                            \'' . addslashes(htmlspecialchars($name)) . '\',
                                            \'' . addslashes(htmlspecialchars($cat->is_active)) . '\'
                                        )">Edit</a>
                                    </li>
                                </ul>
                            </div>';
                })
                ->rawColumns(['action', 'created_at', 'is_active'])
                ->make(true);
        }
    }
    public function getJobTitles(Request $request)
    {
        $statusFilter = $request->input('status_filter', ''); // Default is empty (no filter)
        $typeFilter = $request->input('type_filter', ''); // Default is empty (no filter)

        $query = JobTitle::query()
            ->leftJoin('job_categories', 'job_titles.job_category_id', '=', 'job_categories.id')
            ->select([
                'job_titles.*',
                'job_categories.name as job_category_name',
            ])->with('jobCategory');

        // Search filter
        if ($request->has('search.value')) {
            $searchTerm = strtolower($request->input('search.value'));
            if (!empty($searchTerm)) {
                $query->whereRaw('LOWER(job_titles.name) LIKE ?', ["%{$searchTerm}%"]);

                $query->orWhereHas('jobCategory', function ($q) use ($searchTerm) {
                    $q->where('job_categories.name', 'LIKE', "%{$searchTerm}%");
                });
            }
        }

        // Filter by status if it's not empty
        if ($statusFilter == 'active') {
            $query->where('job_titles.is_active', 1);
        } elseif ($statusFilter == 'inactive') {
            $query->where('job_titles.is_active', 0);
        }

        // Filter by type if it's not empty
        if ($typeFilter == 'specialist') {
            $query->where('job_titles.type', 'specialist');
        } elseif ($typeFilter == 'regular') {
            $query->where('job_titles.type', 'regular');
        }

        // Sorting
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');
            if ($orderColumn === 'job_category') {
                $query->orderBy('job_titles.job_category_id', $orderDirection);
            }elseif ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $query->orderBy($orderColumn, $orderDirection);
            } else {
                $query->orderBy('job_titles.created_at', 'desc');
            }
        } else {
            $query->orderBy('job_titles.created_at', 'desc');
        }

        if ($request->ajax()) {
            return DataTables::eloquent($query)
                ->addIndexColumn()
                ->addColumn('created_at', function ($title) {
                    return $title->created_at ? $title->created_at->format('d-m-Y, h:i A') : '-';
                })
                ->addColumn('is_active', function ($title) {
                    $status = '';
                    if ($title->is_active) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } else {
                        $status = '<span class="badge bg-danger">Inactive</span>';
                    }

                    return $status;
                })
                ->addColumn('job_category', function ($title) {
                    return $title->jobCategory ? $title->jobCategory->name : '-';
                })
                ->addColumn('type', function ($title) {
                    return $title->type ? ucwords(str_replace('-', ' ', $title->type)) : '-';
                })
                ->addColumn('action', function ($title) {
                    $relatedTitles = is_array($title->related_titles)
                        ? $title->related_titles
                        : json_decode($title->related_titles ?: '[]');

                    return '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">
                                    <li>
                                        <a class="dropdown-item" href="#" onclick=\'showEditModal(
                                            ' . json_encode($title->id) . ',
                                            ' . json_encode(ucwords($title->name)) . ',
                                            ' . json_encode($title->job_category_id) . ',
                                            ' . json_encode($title->type) . ',
                                            ' . json_encode($relatedTitles) . ',
                                            ' . $title->is_active . '
                                        )\'>Edit</a>
                                    </li>
                                </ul>
                            </div>';
                })
                ->rawColumns(['action', 'created_at', 'is_active', 'job_category'])
                ->make(true);
        }
    }
    public function getJobTitlesList(Request $request)
    {
        $titles = JobTitle::where('job_category_id', $request->category_id)
            ->where('type', $request->type)
            ->select('id', 'name')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'titles' => $titles
        ]);
    }
    public function getJobSources(Request $request)
    {
        $statusFilter = $request->input('status_filter', ''); // Default is empty (no filter)

        $query = JobSource::query();

        // Search filter
        if ($request->has('search.value')) {
            $searchTerm = strtolower($request->input('search.value'));
            if (!empty($searchTerm)) {
                $query->whereRaw('LOWER(name) LIKE ?', ["%{$searchTerm}%"]);
            }
        }

        // Filter by status if it's not empty
        if ($statusFilter == 'active') {
            $query->where('is_active', 1);
        } elseif ($statusFilter == 'inactive') {
            $query->where('is_active', 0);
        }

        // Sorting
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');
            if ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $query->orderBy($orderColumn, $orderDirection);
            } else {
                $query->orderBy('created_at', 'desc');
            }
        } else {
            $query->orderBy('created_at', 'desc');
        }

        if ($request->ajax()) {
            return DataTables::eloquent($query)
                ->addIndexColumn()
                ->addColumn('created_at', function ($source) {
                    return $source->created_at ? $source->created_at->format('d-m-Y, h:i A') : '-';
                })
                ->addColumn('is_active', function ($source) {
                    $status = '';
                    if ($source->is_active) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } else {
                        $status = '<span class="badge bg-danger">Inactive</span>';
                    }

                    return $status;
                })
                ->addColumn('action', function ($source) {
                    $name = ucwords($source->name);
                    return '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">
                                     <li>
                                        <a class="dropdown-item" href="#" onclick="showEditModal(
                                            \'' . $source->id . '\',
                                            \'' . addslashes(htmlspecialchars($name)) . '\',
                                            \'' . addslashes(htmlspecialchars($source->is_active)) . '\'
                                        )">Edit</a>
                                    </li>
                                </ul>
                            </div>';
                })
                ->rawColumns(['action', 'created_at', 'is_active'])
                ->make(true);
        }
    }
    public function getSmsTemplates(Request $request)
    {
        $statusFilter = $request->input('status_filter', ''); // Default is empty (no filter)

        $query = SmsTemplate::query();

        // Search filter
        if ($request->has('search.value')) {
            $searchTerm = strtolower($request->input('search.value'));
            if (!empty($searchTerm)) {
                 $query->where('sms_templates.title', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('sms_templates.template', 'LIKE', "%{$searchTerm}%");
            }
        }

        // Filter by status if it's not empty
        if ($statusFilter == 'active') {
            $query->where('status', '1');
        } elseif ($statusFilter == 'inactive') {
            $query->where('status', '0');
        }

        // Sorting
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');
            if ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $query->orderBy($orderColumn, $orderDirection);
            } else {
                $query->orderBy('title', 'asc');
            }
        } else {
            $query->orderBy('title', 'asc');
        }

        if ($request->ajax()) {
            return DataTables::eloquent($query)
                ->addIndexColumn()
                ->addColumn('created_at', function ($row) {
                    return $row->created_at ? $row->created_at->format('d-m-Y, h:i A') : '-';
                })
                ->addColumn('title', function ($row) {
                    return ucwords($row->title);
                })
                ->addColumn('slug', function ($row) {
                    return $row->slug;
                })
                ->addColumn('template', function ($row) {
                    return $row->template; // must include HTML (e.g., from Summernote)
                })
                ->addColumn('status', function ($row) {
                    $status = '';
                    if ($row->status) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } else {
                        $status = '<span class="badge bg-danger">Inactive</span>';
                    }

                    return $status;
                })
                ->addColumn('action', function ($row) {
                    return '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">
                                    <li>
                                        <a class="dropdown-item" href="#" onclick="showEditModal(' . $row->id . ')">Edit</a>
                                    </li>
                                  <!--  <li>
                                        <a class="dropdown-item" href="#" onclick="deleteTemplate(' . $row->id . ')">Delete</a>
                                    </li> -->
                                </ul>
                            </div>';
                })
                ->rawColumns(['action', 'created_at', 'template', 'slug', 'title', 'status'])
                ->make(true);
        }
    }
    // public function smsTemplateDelete(Request $request)
    // {
    //     try {
    //         $id = $request->id; // Get the ID from the route parameter

    //         $template = SmsTemplate::find($id);

    //         if (!$template) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'SMS Template not found.'
    //         ], 404);
    //         }
    //         $template->delete();
    //         return response()->json([
    //         'success' => true,
    //         'data' => $template
    //         ]);
    //     } catch (\Exception $e) {
    //         Log::error('Error deleting SMS template: ' . $e->getMessage());
    //         return response()->json([
    //         'success' => false,
    //         'message' => 'An error occurred while deleting the SMS template. Please try again.'
    //         ], 500);
    //     }
    // }
    public function smsEditTemplate(Request $request)
    {
        $id = $request->id; // Get the ID from the route parameter

        $template = SmsTemplate::find($id);
        if ($template) {
            // Format the title: replace underscores with spaces and capitalize words
            $template->title = ucwords($template->title);
        }
        if (!$template) {
            return response()->json([
                'success' => false,
                'message' => 'SMS Template not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $template
        ]);
    }
    public function smsTemplatesStore(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255|unique:sms_templates,title',
            'template' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        try {
            // Create the role
            SmsTemplate::create([
                'title' => $request->input('title'),
                'slug' => strtolower(str_replace(' ','_',$request->input('title'))),
                'template' => $request->input('template')
            ]);

            return response()->json([
                'success' => true,
                'message' => 'SMS template created successfully',
                'redirect' => route('settings.sms-templates')
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating sms template: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating the sms template. Please try again.'
            ], 500);
        }
    }
    public function smsTemplatesUpdate(Request $request)
    {

        $id = $request->input('id');
        // Validation
        $validator = Validator::make($request->all(), [
            'edit_title' => [
                'required',
                'string',
                'max:255',
                Rule::unique('sms_templates', 'title')->ignore($id),
            ],
            'edit_template' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        try {
            // Retrieve the role
            $template = SmsTemplate::findOrFail($id);

            // Update 
            // $template->title = $request->input('edit_title');
            $template->slug = strtolower(str_replace(' ','_',$request->input('edit_title')));
            $template->template = $request->input('edit_template');
            $template->status = $request->input('status');
            $template->save();

            return response()->json([
                'success' => true,
                'message' => 'Template updated successfully',
                'redirect' => route('settings.sms-templates')
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating template: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the template, Please try again.'
            ], 500);
        }
    }
    public function getEmailTemplates(Request $request)
    {
        $statusFilter = $request->input('status_filter', ''); // Default is empty (no filter)

        $query = EmailTemplate::query();

        // Search filter
        if ($request->has('search.value')) {
            $searchTerm = strtolower($request->input('search.value'));
            if (!empty($searchTerm)) {
                 $query->where('email_templates.title', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('email_templates.from_email', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('email_templates.subject', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('email_templates.template', 'LIKE', "%{$searchTerm}%");
            }
        }

        // Filter by status if it's not empty
        if ($statusFilter == 'active') {
            $query->where('is_active', 1);
        } elseif ($statusFilter == 'inactive') {
            $query->where('is_active', 0);
        }

        // Sorting
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');
            if ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $query->orderBy($orderColumn, $orderDirection);
            } else {
                $query->orderBy('email_templates.title', 'asc');
            }
        } else {
            $query->orderBy('email_templates.title', 'asc');
        }

        if ($request->ajax()) {
            return DataTables::eloquent($query)
                ->addIndexColumn()
                ->addColumn('created_at', function ($row) {
                    return $row->created_at ? $row->created_at->format('d-m-Y, h:i A') : '-';
                })
                ->addColumn('title', function ($row) {
                    return ucwords($row->title);
                })
                ->addColumn('slug', function ($row) {
                    return $row->slug;
                })
                ->addColumn('template', function ($row) {
                    return $row->template;
                })
                ->addColumn('in_active', function ($row) {
                    $status = '';
                    if ($row->is_active) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } else {
                        $status = '<span class="badge bg-danger">Inactive</span>';
                    }

                    return $status;
                })
                ->addColumn('action', function ($row) {
                    return '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">
                                     <li>
                                        <a class="dropdown-item" href="#" onclick="showEditModal(' . $row->id . ')">Edit</a>
                                    </li>
                                    <!-- <li>
                                         <a class="dropdown-item" href="#" onclick="deleteTemplate(' . $row->id . ')">Delete</a>
                                     </li>-->
                                </ul>
                            </div>';
                })
                ->rawColumns(['action', 'created_at', 'title', 'slug', 'in_active', 'template'])
                ->make(true);
        }
    }
    public function emailEditTemplate(Request $request)
    {
        $id = $request->id; // Get the ID from the route parameter

        $template = EmailTemplate::find($id);
        if ($template) {
            // Format the title: replace underscores with spaces and capitalize words
            $template->title = ucwords($template->title);
        }
        if (!$template) {
            return response()->json([
                'success' => false,
                'message' => 'Email Template not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $template
        ]);
    }
    public function emailTemplatesStore(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:191|unique:email_templates,title',
            'from_email' => 'required|email|max:255',
            'subject' => 'required|string|max:255',
            'template' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        try {
            // Create the role
            EmailTemplate::create([
                'title' => $request->input('title'),
                'slug' => strtolower(str_replace(' ','_',$request->input('title'))),
                'from_email' => $request->input('from_email'),
                'subject' => $request->input('subject'),
                'template' => $request->input('template'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Email template created successfully',
                'redirect' => route('settings.email-templates')
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating email template: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating the email template. Please try again.'
            ], 500);
        }
    }
    public function emailTemplatesUpdate(Request $request)
    {
        $id = $request->input('id');
        // Validation
        $validator = Validator::make($request->all(), [
            'edit_title' => [
                'required',
                'string',
                'max:255',
                Rule::unique('email_templates', 'title')->ignore($id),
            ],
            'edit_template' => 'required',
            'edit_from' => 'required',
            'edit_subject' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        try {
            // Retrieve the role
            $template = EmailTemplate::findOrFail($id);

            // Update 
            // $template->title = $request->input('edit_title');
            $template->slug = strtolower(str_replace(' ','_',$request->input('edit_title')));
            $template->from_email = $request->input('edit_from');
            $template->subject = $request->input('edit_subject');
            $template->template = $request->input('edit_template');
            $template->is_active = $request->input('status');
            $template->save();

            return response()->json([
                'success' => true,
                'message' => 'Template updated successfully',
                'redirect' => route('settings.sms-templates')
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating template: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the template, Please try again.'
            ], 500);
        }
    }
    // public function emailTemplateDelete(Request $request)
    // {
    //     try {
    //         $id = $request->id; // Get the ID from the route parameter

    //         $template = EmailTemplate::find($id);

    //         if (!$template) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Email Template not found.'
    //         ], 404);
    //         }
    //         $template->delete();

    //         return response()->json([
    //         'success' => true,
    //         'data' => $template
    //         ]);
    //     } catch (\Exception $e) {
    //         Log::error('Error deleting SMS template: ' . $e->getMessage());
    //         return response()->json([
    //         'success' => false,
    //         'message' => 'An error occurred while deleting the SMS template. Please try again.'
    //         ], 500);
    //     }
    // }
    public function edit($id)
    {
        return view('roles.edit');
    }
    public function update(Request $request)
    {
        $id = $request->input('role_id');
        // Validation
        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')->ignore($id),
            ],
            // 'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        try {
            // Retrieve the role
            $role = \Spatie\Permission\Models\Role::findOrFail($id);

            // Update role name
            $role->name = $request->input('name');
            $role->save();

            // Sync permissions
            $role->syncPermissions($request->input('permissions'));

            return response()->json([
                'success' => true,
                'message' => 'Role updated successfully',
                'redirect' => route('roles.list')
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating role: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the role. Please try again.'
            ], 500);
        }
    }
    public function jobCategoriesUpdate(Request $request)
    {
        $id = $request->input('id');
        // Validation
        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('job_categories', 'name')->ignore($id),
            ]
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        try {
            // Retrieve the role
            $role = JobCategory::findOrFail($id);

            // Update role name
            $role->name = $request->input('name');
            $role->description = $request->input('name');
            $role->is_active = $request->input('status');
            $role->save();

            return response()->json([
                'success' => true,
                'message' => 'Job Category updated successfully',
                'redirect' => route('roles.list')
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating job category: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the job category. Please try again.'
            ], 500);
        }
    }
    public function jobTitlesUpdate(Request $request)
    {
        $id = $request->input('id');
        // Validation
        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('job_titles', 'name')->ignore($id),
            ],
            'type' => 'required',
            'job_category_id' => 'required',
            'related_titles' => 'nullable|array',
            'related_titles.*' => 'string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        try {
            // Retrieve the role
            $jobTitle = JobTitle::findOrFail($id);

            // Update role name
            $jobTitle->name = strtoupper($request->input('name'));
            $jobTitle->description = $request->input('name');
            $jobTitle->type = $request->input('type');
            $jobTitle->job_category_id = $request->input('job_category_id');
            $jobTitle->is_active = $request->input('status');
            $jobTitle->related_titles = $request->input('related_titles', []);
            $jobTitle->save();

            return response()->json([
                'success' => true,
                'message' => 'Job Title updated successfully',
                'redirect' => route('job-titles.list')
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating job title: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the job title. Please try again.'
            ], 500);
        }
    }
    public function jobSourceUpdate(Request $request)
    {
        $id = $request->input('id');
        // Validation
        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('job_sources', 'name')->ignore($id),
            ]
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        try {
            // Retrieve the role
            $source = JobSource::findOrFail($id);

            // Update role name
            $source->name = $request->input('name');
            $source->description = $request->input('name');
            $source->is_active = $request->input('status');
            $source->save();

            return response()->json([
                'success' => true,
                'message' => 'Job Source updated successfully',
                'redirect' => route('job-sources.list')
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating job source: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the job source. Please try again.'
            ], 500);
        }
    }
    public function destroy($id)
    {
        $role = Role::findOrFail($id);
        $role->delete();
        return redirect()->route('roles.list')->with('success', 'Role deleted successfully');
    }
    public function show($id)
    {
        $role = Role::findOrFail($id);
        return view('roles.show', compact('role'));
    }
    public function getSettings()
    {
        $settings = Setting::all()->groupBy('group');

        $response = [];

        foreach ($settings as $group => $items) {
            // For simple groups (general, sms, profile, google_maps, notifications...)
            $response[$group] = $items->mapWithKeys(function ($item) {
                // Cast based on type field
                $value = $item->value;
                switch ($item->type) {
                    case 'boolean':
                        $value = filter_var($item->value, FILTER_VALIDATE_BOOLEAN);
                        break;
                    case 'json':
                        $value = json_decode($item->value, true);
                        break;
                    case 'integer':
                        $value = (int) $item->value;
                        break;
                }
                return [$item->key => $value];
            })->toArray();
        }

        // Add SMTP separately (does not overwrite)
        $response['smtp'] = SmtpSetting::all()->map(function ($setting) {
            return [
                'id'          => $setting->id,
                'mailer'      => $setting->mailer,
                'host'        => $setting->host,
                'port'        => $setting->port,
                'username'    => $setting->username,
                'password'    => $setting->password,
                'encryption'  => $setting->encryption,
                'from_address'=> $setting->from_address,
                'from_name'   => $setting->from_name,
            ];
        })->toArray();

        $response['profile'] = [
            'id'          => Auth::user()->id,
            'user_name'      => Auth::user()->name,
            'user_email'        => Auth::user()->email
        ];

        return response()->json($response);
    }
    public function saveSmtpSettings(Request $request)
    {
        try {
            $request->validate([
                'smtp' => 'required|array',
                'smtp.*.mailer' => 'required|string',
                'smtp.*.host' => 'required|string',
                'smtp.*.port' => 'required|numeric',
                'smtp.*.username' => 'required|string',
                'smtp.*.password' => 'required|string',
                'smtp.*.from_name' => 'required|string',
                'smtp.*.from_address' => 'required|email',
                'smtp.*.encryption' => 'nullable|string',
            ]);

            foreach ($request->smtp as $setting) {
                SmtpSetting::updateOrCreate(
                    ['from_address' => $setting['from_address']], // check by from_address instead of id
                    [
                        'host' => $setting['host'],
                        'mailer' => $setting['mailer'],
                        'port' => $setting['port'],
                        'username' => $setting['username'],
                        'password' => $setting['password'],
                        'from_name' => $setting['from_name'],
                        'encryption' => $setting['encryption'] ?? null,
                    ]
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'SMTP settings saved successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error saving SMTP settings.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function saveGeneralSettings(Request $request)
    {
        try {
            $request->validate([
                'site_name' => 'required|string|max:50',
            ]);

            Setting::updateOrCreate(
                ['key' => 'site_name'], // condition (find by key)
                ['value' => $request->site_name] // update or insert value
            );

            return response()->json([
                'success' => true,
                'message' => 'Site Name saved successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error saving settings.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function saveGoogleSettings(Request $request)
    {
        try {
            $request->validate([
                'google_api_url' => 'required|string',
                'google_api_key' => 'required|string',
            ]);

            // Save Google API URL
            Setting::updateOrCreate(
                ['key' => 'google_map_api_url'], // condition
                ['value' => $request->google_api_url] // value
            );

            // Save Google API Key
            Setting::updateOrCreate(
                ['key' => 'google_map_api_key'],
                ['value' => $request->google_api_key]
            );

            return response()->json([
                'success' => true,
                'message' => 'Google Map Configuration saved successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error saving settings.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function saveNotificationSettings(Request $request)
    {
        try {
            $request->validate([
                'email_notifications' => 'required',
                'sms_notifications' => 'required',
            ]);

            // Save Google API URL
            Setting::updateOrCreate(
                ['key' => 'email_notifications'], // condition
                ['value' => $request->email_notifications] // value
            );

            // Save Google API Key
            Setting::updateOrCreate(
                ['key' => 'sms_notifications'],
                ['value' => $request->sms_notifications]
            );

            return response()->json([
                'success' => true,
                'message' => 'Notifications saved successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error saving settings.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function saveSmsSettings(Request $request)
    {
        try {
            $request->validate([
                'sms_api_url' => 'required',
                'sms_port' => 'required',
                'sms_username' => 'required',
                'sms_password' => 'required',
            ]);

            // Save sms API URL
            Setting::updateOrCreate(
                ['key' => 'sms_api_url'], // condition
                ['value' => $request->sms_api_url] // value
            );

            // Save sms API Key
            Setting::updateOrCreate(
                ['key' => 'sms_port'],
                ['value' => $request->sms_port]
            );

            // Save sms API Key
            Setting::updateOrCreate(
                ['key' => 'sms_username'],
                ['value' => $request->sms_username]
            );

            // Save sms API Key
            Setting::updateOrCreate(
                ['key' => 'sms_password'],
                ['value' => $request->sms_password]
            );

            return response()->json([
                'success' => true,
                'message' => 'Notifications saved successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error saving settings.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function saveProfileSettings(Request $request)
    {
        try {
            $request->validate([
                'user_name' => 'required|string',
                'user_email' => 'required|email',
            ]);

            $user_id = Auth::user()->id;

            User::where('id', $user_id)->update([
                'name' => $request->user_name,
                'email' => $request->user_email
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Profile saved successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error saving settings.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function deleteSmtp(Request $request)
    {
        try {
            $id = $request->id;
            $smtp = SmtpSetting::find($id);

            if (!$smtp) {
                return response()->json([
                    'success' => false,
                    'message' => 'SMTP setting not found.'
                ], 404);
            }

            $smtp->delete();

            return response()->json([
                'success' => true,
                'message' => 'SMTP setting deleted successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while deleting the SMTP setting. Please try again.'
            ], 500);
        }
    }

}
