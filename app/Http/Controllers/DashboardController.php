<?php

namespace App\Http\Controllers;


use Illuminate\Http\Request;
use Horsefly\User;
use Horsefly\Sale;
use Horsefly\Applicant;
use Horsefly\Unit;
use Horsefly\Office;
use Horsefly\Message;
use Horsefly\Audit;
use Horsefly\CVNote;
use Horsefly\CrmNote;
use Horsefly\RevertStage;
use Horsefly\ApplicantNote;
use Horsefly\History;
use App\Http\Controllers\Controller;
use Horsefly\LoginDetail;
use Illuminate\Support\Facades\Log;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;
use App\Exports\UsersExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Horsefly\JobCategory;
use Horsefly\JobTitle;
use Horsefly\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function __construct()
    {
    }
    public function index()
    {
        $user = Auth::user();

        // if ($user->hasRole(['super_admin', 'admin'])) {
            return view('dashboards.index');
        // } elseif ($user->hasRole(['sales', 'sale and crm', 'quality'])) {
        //     return view('dashboards.sales');
        // } else {
        //     return view('dashboards.agents');
        // }
    }
    public function notificationsIndex()
    {
        return view('dashboards.notifications');
    }
    public function getCounts()
    {
        $now = Carbon::now();

        // Static date calculations
        $last7DaysStart   = $now->copy()->subDays(16)->startOfDay();
        $last7DaysEnd     = $now->copy()->endOfDay();
        $days21Start      = $now->copy()->subDays(37)->startOfDay();
        $days21End        = $now->copy()->subDays(17)->endOfDay();
        $cutoffDate       = $now->copy()->subDays(36)->endOfDay();

        // Preload counts that are quick
        $applicantsCount = Applicant::where('status', 1)->count();
        $officesCount    = Office::where('status', 1)->count();
        $unitsCount      = Unit::where('status', 1)->count();
        $salesCount      = Sale::where('status', 1)->where('is_on_hold', 0)->count();

        // 🧠 Optimize by getting applicant IDs that exist in pivot table once
        $linkedApplicantIds = DB::table('applicants_pivot_sales')->distinct()->pluck('applicant_id');

        // Cache those IDs in memory for all 3 queries
        $unlinkedApplicants = Applicant::query()
            ->where('status', 1)
            ->whereNotIn('id', $linkedApplicantIds);

        // Use clones to avoid re-query building overhead
        $last7DaysCount = (clone $unlinkedApplicants)
            ->whereBetween('updated_at', [$last7DaysStart, $last7DaysEnd])
            ->count();

        $last21DaysCount = (clone $unlinkedApplicants)
            ->whereBetween('updated_at', [$days21Start, $days21End])
            ->count();

        $last3MonthsCount = (clone $unlinkedApplicants)
            ->where('updated_at', '<=', $cutoffDate)
            ->count();

        return response()->json([
            'applicantsCount'  => $applicantsCount,
            'officesCount'     => $officesCount,
            'unitsCount'       => $unitsCount,
            'salesCount'       => $salesCount,
            'last7DaysCount'   => $last7DaysCount,
            'last21DaysCount'  => $last21DaysCount,
            'last3MonthsCount' => $last3MonthsCount,
        ]);
    }
    public function getUsersForDashboard(Request $request)
    {
        $model = User::query()
            ->leftJoin('model_has_roles', function ($join) {
                $join->on('users.id', '=', 'model_has_roles.model_id')
                    ->where('model_has_roles.model_type', '=', User::class);
            })
            ->leftJoin('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->select('users.*', 'roles.name as role_name'); // Add alias for sorting

        // Sorting logic
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            if ($orderColumn === 'role') {
                $model->orderBy('role_name', $orderDirection);
            } elseif ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $model->orderBy($orderColumn, $orderDirection);
            } else {
                $model->orderBy('users.created_at', 'desc');
            }
        } else {
            // Default sorting when no order is specified
            $model->orderBy('users.created_at', 'desc');
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn() // This will automatically add a serial number to the rows
               ->addColumn('name', function ($user) {
                    $path = asset('/images/users/user.png') ?? asset('/images/users/default.jpg');

                    return '
                        <div class="d-flex align-items-center">
                            <img src="' . $path . '" class="avatar-sm rounded-circle me-2" alt="user">
                            <span>' . e($user->formatted_name) . '</span>
                        </div>
                    ';
                })

                ->addColumn('role_name', function ($user) {
                    $role = str_replace('_',' ', $user->role_name); // returns the first (or only) role name
                    return $role ? ucwords($role) : '-';
                })
                ->addColumn('created_at', function ($user) {
                    return $user->formatted_created_at; // Using accessor
                })
                ->addColumn('updated_at', function ($user) {
                    return $user->formatted_updated_at; // Using accessor
                })
                ->addColumn('is_active', function ($user) {
                    $status = '';
                    if ($user->is_active) {
                        $status = '<span class="badge bg-success-subtle text-success py-1 px-2 fs-12">Active</span>';
                    } else {
                        $status = '<span class="badge bg-danger-subtle text-danger py-1 px-2 fs-12">Inactive</span>';
                    }

                    return $status;
                })
                ->addColumn('action', function ($user) {
                    $name = $user->formatted_name;
                    $email = $user->email;
                    $roleName = ucwords(str_replace('_', ' ', $user->role_name));
                    $status = '';

                    if ($user->is_active) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } else {
                        $status = '<span class="badge bg-danger">Inactive</span>';
                    }
                    $html = '';
                    $html .= '<div class="d-flex gap-2 align-items-center">
                                <a href="#!" class="btn btn-light btn-sm" onclick="showDetailsModal(
                                        \'' . (int)$user->id . '\',
                                        \'' . addslashes(htmlspecialchars($name)) . '\',
                                        \'' . addslashes(htmlspecialchars($email)) . '\',
                                        \'' . addslashes(htmlspecialchars($roleName)) . '\',
                                        \'' . addslashes(htmlspecialchars($status)) . '\'
                                    )">
                                    <iconify-icon icon="solar:eye-broken"
                                                class="align-middle fs-18"></iconify-icon>
                                </a>';
                    if(Gate::allows('dashboard-users-stats')){
                        $html .= '<a href="#!" class="btn btn-light btn-sm" onclick="showStatisticsModal(
                                            \'' . (int)$user->id . '\'
                                        )">
                                        <iconify-icon icon="solar:square-arrow-right-up-bold" class="text-info align-middle fs-18"></iconify-icon>
                                    </a>
                                </div>';
                    }
                    return $html;
                })
                ->rawColumns(['name', 'is_active', 'action', 'role_name'])
                ->make(true);
        }
    }
    public function getUserStatistics(Request $request)
    {
        // Validate input using Laravel 12's Validator
        $validator = Validator::make($request->all(), [
            'user_key' => ['required', 'exists:users,id'],
            'date_range_filter' => ['required', 'regex:/^\d{4}-\d{2}-\d{2}\|\d{4}-\d{2}-\d{2}$/'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->all()], 422);
        }

        try {
            // Parse date range
            [$start_date, $end_date] = explode('|', $request->input('date_range_filter'));
            $start_date = trim($start_date) . ' 00:00:00';
            $end_date = trim($end_date) . ' 23:59:59';

            // Validate date formats using Carbon
            Carbon::parse($start_date);
            Carbon::parse($end_date);

            $user_id = $request->input('user_key');

            // Fetch user with role using Eloquent relationships
            $userWithRole = User::query()
                ->with(['roles' => fn ($query) => $query->select('roles.id', 'roles.name')])
                ->where('id', $user_id)
                ->select('id', 'name')
                ->firstOrFail();

            $user_role = $userWithRole->roles->first()->name ?? '';
            $user_name = $userWithRole->name ?? '';

            // Initialize stats arrays
            $quality_stats = [
                'cvs_sent' => 0,
                'cvs_rejected' => 0,
                'cvs_cleared' => 0,
            ];

            $user_stats = array_fill_keys([
                'CRM_sent_cvs', 'CRM_rejected_cv', 'CRM_request', 'CRM_rejected_by_request',
                'CRM_confirmation', 'CRM_rebook', 'CRM_attended', 'CRM_not_attended',
                'CRM_start_date', 'CRM_start_date_hold', 'CRM_declined', 'CRM_invoice',
                'CRM_dispute', 'CRM_paid', 'close_sales', 'open_sales'
            ], 0);

            $prev_user_stats = array_fill_keys([
                'CRM_start_date', 'CRM_invoice', 'CRM_paid'
            ], 0);

            // Process sales-related data for Sales roles
            if (in_array($user_role, ['Sales', 'Sale and CRM'], true)) {
                // Fetch sales with related data
                $salesQuery = Sale::query()
                    ->where('user_id', $user_id)
                    ->whereIn('status', [0, 1])
                    ->whereBetween('created_at', [$start_date, $end_date]);

                // Count closed sales
                $user_stats['close_sales'] = Audit::query()
                    ->where('message', 'sale-closed')
                    ->where('auditable_type', Sale::class)
                    ->whereIn('auditable_id', $salesQuery->pluck('id'))
                    ->whereBetween('created_at', [$start_date, $end_date])
                    ->count();

                $sales = $salesQuery->get();
                $user_stats['open_sales'] = $sales->count() - $user_stats['close_sales'];

                // Fetch CV notes for sales
                $cv_notes = CVNote::query()
                    ->whereIn('sale_id', $sales->pluck('id'))
                    ->whereBetween('updated_at', [$start_date, $end_date])
                    ->select('applicant_id', 'sale_id')
                    ->get();
            } else {
                // Fetch CV notes for non-sales roles
                $cv_notes = CVNote::query()
                    ->where('user_id', $user_id)
                    ->whereBetween('created_at', [$start_date, $end_date])
                    ->select('applicant_id', 'sale_id')
                    ->get();
            }

            $quality_stats['cvs_sent'] = $cv_notes->count();

            // Batch process CV-related stats
            $cv_grouped = $cv_notes->groupBy(['applicant_id', 'sale_id']);

            foreach ($cv_grouped as $applicant_id => $sales_group) {
                foreach ($sales_group as $sale_id => $cv_group) {
                    // Fetch all relevant history records
                    $history = History::query()
                        ->whereIn('sub_stage', ['quality_reject', 'crm_reject', 'crm_request',
                            'crm_request_confirm', 'crm_reebok', 'crm_interview_attended',
                            'crm_interview_not_attended', 'crm_start_date', 'crm_start_date_back',
                            'crm_start_date_hold', 'crm_invoice', 'crm_dispute', 'crm_paid',
                            'crm_request_reject', 'crm_declined'
                        ])
                        ->where('applicant_id', $applicant_id)
                        ->where('sale_id', $sale_id)
                        ->whereBetween('created_at', [$start_date, $end_date])
                        ->get()
                        ->keyBy('sub_stage');
                   
                    $history_forCleared = History::query()
                        ->whereIn('sub_stage', [
                            'quality_cleared'
                        ])
                        ->where('applicant_id', $applicant_id)
                        ->where('sale_id', $sale_id)
                        ->whereBetween('updated_at', [$start_date, $end_date])
                        ->get()
                        ->keyBy('sub_stage');

                    // Quality stats
                    if (isset($history_forCleared['quality_cleared']) && $history_forCleared['quality_cleared']->status === 1) {
                        $quality_stats['cvs_cleared']++;
                        $user_stats['CRM_sent_cvs']++;
                    }
                    if (isset($history['quality_reject']) && $history['quality_reject']->status === 1) {
                        $quality_stats['cvs_rejected']++;
                    }
                    if (isset($history['crm_reject']) && $history['crm_reject']->status === 1) {
                        $user_stats['CRM_rejected_cv']++;
                        continue;
                    }

                    // CRM request and confirmation checks
                    if (isset($history['crm_request'])) {
                        $crm_sent_cv = CrmNote::query()
                            ->where([
                                'moved_tab_to' => 'cv_sent',
                                'applicant_id' => $applicant_id,
                                'sale_id' => $sale_id
                            ])
                            ->whereBetween('created_at', [$start_date, $end_date])
                            ->orderByDesc('id')
                            ->first();

                        if ($crm_sent_cv && Carbon::parse($history['crm_request']->history_added_date . ' ' . 
                            $history['crm_request']->history_added_time)->gt($crm_sent_cv->created_at)) {
                            $user_stats['CRM_request']++;
                            $this->processCrmStats($history, $user_stats, $applicant_id, $sale_id, $start_date, $end_date);
                        }
                    }
                }
            }

            // Previous month stats
            $prevMonthStart = Carbon::now()->subMonth(6)->startOfMonth()->format('Y-m-d') . ' 00:00:00';
            $prevMonthEnd = Carbon::now()->subMonth(6)->endOfMonth()->format('Y-m-d') . ' 23:59:59';

            $prev_cv_notes = CVNote::query()
                ->where('user_id', $user_id)
                ->whereBetween('created_at', [$prevMonthStart, $prevMonthEnd])
                ->select('applicant_id', 'sale_id')
                ->get();

            $prev_cv_grouped = $prev_cv_notes->groupBy(['applicant_id', 'sale_id']);

            foreach ($prev_cv_grouped as $applicant_id => $sales_group) {
                foreach ($sales_group as $sale_id => $cv_group) {
                    $prev_history = History::query()
                        ->whereIn('sub_stage', [
                            'crm_start_date', 'crm_start_date_back', 'crm_invoice', 'crm_paid'
                        ])
                        ->where('applicant_id', $applicant_id)
                        ->where('sale_id', $sale_id)
                        ->whereBetween('created_at', [$prevMonthStart, $prevMonthEnd])
                        ->get()
                        ->keyBy('sub_stage');

                    if (isset($prev_history['crm_start_date']) || isset($prev_history['crm_start_date_back'])) {
                        $prev_user_stats['CRM_start_date']++;
                    }
                    if (isset($prev_history['crm_invoice'])) {
                        $prev_user_stats['CRM_invoice']++;
                    }
                    if (isset($prev_history['crm_paid'])) {
                        $prev_user_stats['CRM_paid']++;
                    }
                }
            }

            return response()->json([
                'user_name' => $user_name,
                'user_role' => $user_role,
                'quality_stats' => $quality_stats,
                'user_stats' => $user_stats,
                'prev_user_stats' => $prev_user_stats,
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred while processing statistics'], 500);
        }
    }
    private function processCrmStats($history, array &$user_stats, $applicant_id, $sale_id, string $start_date, string $end_date): void
    {
        if (isset($history['crm_request_reject']) && $history['crm_request_reject']->status === 1) {
            $user_stats['CRM_rejected_by_request']++;
        }

        if (isset($history['crm_request_confirm']) && isset($history['crm_request']) &&
            Carbon::parse($history['crm_request_confirm']->history_added_date . ' ' . 
                $history['crm_request_confirm']->history_added_time)->gt(
                Carbon::parse($history['crm_request']->history_added_date . ' ' . 
                    $history['crm_request']->history_added_time)
            )) {
            $user_stats['CRM_confirmation']++;

            if (isset($history['crm_reebok']) && $history['crm_reebok']->status === 1) {
                $user_stats['CRM_rebook']++;
            }

            if (isset($history['crm_interview_attended'])) {
                $user_stats['CRM_attended']++;

                if (isset($history['crm_declined']) && $history['crm_declined']->status === 1) {
                    $user_stats['CRM_declined']++;
                }

                if (isset($history['crm_interview_not_attended']) && $history['crm_interview_not_attended']->status === 1) {
                    $user_stats['CRM_not_attended']++;
                }

                if (isset($history['crm_start_date']) || isset($history['crm_start_date_back'])) {
                    $user_stats['CRM_start_date']++;

                    if (isset($history['crm_start_date_hold']) && $history['crm_start_date_hold']->status === 1) {
                        $user_stats['CRM_start_date_hold']++;
                    }

                    if (isset($history['crm_invoice'])) {
                        $user_stats['CRM_invoice']++;

                        if (isset($history['crm_dispute']) && $history['crm_dispute']->status === 1) {
                            $user_stats['CRM_dispute']++;
                        }

                        if (isset($history['crm_paid'])) {
                            $user_stats['CRM_paid']++;
                        }
                    }
                }
            }
        }
    }
    public function getWeeklySales()
    {
        $startOfWeek = now()->startOfWeek();
        $endOfWeek = now()->endOfWeek();

        $dailyCounts = Sale::whereBetween('created_at', [$startOfWeek, $endOfWeek])
            ->select(DB::raw('DAYOFWEEK(created_at) as day'), DB::raw('COUNT(*) as total'))
            ->groupBy(DB::raw('DAYOFWEEK(created_at)'))
            ->pluck('total', 'day');

        // Format: 1 = Sunday, 7 = Saturday
        $chartData = [];
        for ($i = 1; $i <= 7; $i++) {
            $chartData[] = $dailyCounts[$i] ?? 0;
        }

        $salesDetails = Sale::with(['office', 'unit'])
            ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
            ->get(['id', 'unit_id', 'office_id', 'sale_postcode', 'created_at']);

        return response()->json([
            'total' => array_sum($chartData),
            'chartData' => $chartData,
            'details' => $salesDetails
        ]);
    }
    public function getSalesAnalytic(Request $request)
    {
        $range = $request->input('range', 'year');

        if ($range === 'year') {
            $from = now()->startOfYear();
            $to = now()->endOfYear();
            $grouping = 'MONTH(created_at)';
            $rangeLabels = collect(range(1, 12))->map(function ($month) {
                return Carbon::create()->month($month)->format('F');
            });
        } else {
            $from = now()->startOfMonth();
            $to = now()->endOfMonth();
            $grouping = 'DATE(created_at)';
            $daysInMonth = now()->daysInMonth;
            $rangeLabels = collect(range(1, $daysInMonth))->map(function ($day) {
                return now()->startOfMonth()->addDays($day - 1)->format('d M');
            });
        }

        $rawData = Sale::selectRaw("$grouping as label")
            ->selectRaw("SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as new_added")
            ->selectRaw("SUM(CASE WHEN status = 2 AND created_at THEN 1 ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN status = 1 AND is_re_open = 1 AND created_at != updated_at THEN 1 ELSE 0 END) as reopened")
            ->selectRaw("SUM(CASE WHEN status = 0 AND created_at != updated_at THEN 1 ELSE 0 END) as closed")
            ->selectRaw("SUM(CASE WHEN status = 3 AND created_at != updated_at THEN 1 ELSE 0 END) as rejected")
            ->whereBetween('created_at', [$from, $to])
            ->groupBy(DB::raw($grouping))
            ->orderBy(DB::raw($grouping))
            ->get()
            ->keyBy(function ($item) use ($range) {
                if ($range === 'year') {
                    return Carbon::create()->month((int)$item->label)->format('F');
                } else {
                    // $item->label is "YYYY-MM-DD"
                    return Carbon::parse($item->label)->format('d M');
                }
            });

        $labels = [];
        $new = [];
        $reopened = [];
        $closed = [];
        $pending = [];
        $rejected = [];

        foreach ($rangeLabels as $label) {
            $labels[] = $label;
            $new[] = isset($rawData[$label]) ? (int) $rawData[$label]->new_added : 0;
            $reopened[] = isset($rawData[$label]) ? (int) $rawData[$label]->reopened : 0;
            $closed[] = isset($rawData[$label]) ? (int) $rawData[$label]->closed : 0;
            $pending[] = isset($rawData[$label]) ? (int) $rawData[$label]->pending : 0;
            $rejected[] = isset($rawData[$label]) ? (int) $rawData[$label]->rejected : 0;
        }

        return response()->json([
            'labels' => $labels,
            'new_added' => $new,
            'reopened' => $reopened,
            'closed' => $closed,
            'pending' => $pending,
            'rejected' => $rejected,
        ]);
    }
    public function getUnreadMessages()
    {
        try {
            $messages = Message::query()
                ->where('status', 'incoming')
                ->where('module_type', 'Horsefly\\Applicant')
                ->where('is_read', 0)
                ->with(['user' => fn ($query) => $query->select('id', 'name')])
                ->select('id', 'user_id', 'message', 'created_at')
                ->latest()
                ->take(5)
                ->get()
                ->map(function ($message) {
                    return [
                        'id' => $message->id,
                        'user_name' => $message->applicant->applicant_name ?? 'Unknown',
                        'avatar' => asset('images/users/boy.png') ?? asset('images/users/default.jpg') ,
                        'message' => Str::limit(strip_tags($message->message), 150),
                        'created_at' => $message->created_at->diffForHumans(),
                    ];
                });

            $unreadCount = Message::where('status', 'incoming')
                ->where('module_type', 'Horsefly\\Applicant')
                ->where('is_read', 0)
                ->count();

            return response()->json([
                'success' => true,
                'messages' => $messages,
                'unread_count' => $unreadCount,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch messages: ' . $e->getMessage(),
            ], 500);
        }
    }

    /************************ Private Functions ***************/
    private function generateJobDetailsModal($notification)
    {
        $modalId = 'jobDetailsModal_' . $notification->sale_id;  // Unique modal ID for each applicant's job details

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
    public function getUnreadNotifications()
    {
        try {
            $notifications = Notification::query()
                ->where('user_id', Auth::id())
                // Left join with the 'users' table to get the 'notify_by' user (sender)
                ->leftJoin('users as notify_by_users', 'notifications.notify_by', '=', 'notify_by_users.id') 
                // Eager load the other relationships
                ->with([
                    'user' => fn($query) => $query->select('id', 'name'),  // Eager load the 'user' relationship (recipient of the notification)
                    'applicant' => fn($query) => $query->select('id', 'applicant_name'),  // Eager load the 'applicant' relationship
                    'sale' => fn($query) => $query->select('id', 'sale_postcode')  // Eager load the 'sale' relationship
                ])
                // Filter unread notifications
                // ->where('notifications.is_read', 0)
                ->select('notifications.*', 'notify_by_users.name as notify_by_name') // Select the 'name' of the notify_by user from the joined table
                ->latest()
                ->take(5)
                ->get()
                ->map(function ($notification) {
                    return [
                        'id' => $notification->id,
                        'user_name' => $notification->user->name ?? 'Unknown',  // Access the 'name' field of the 'user' relationship
                        'notify_by' => $notification->notify_by_name ?? 'Unknown',  // Access the 'notify_by_name' (name of the user who triggered the notification)
                        'applicant_name' => $notification->applicant->applicant_name ?? 'Unknown',  // Access the 'applicant_name'
                        'sale_postcode' => $notification->sale->sale_postcode ?? 'Unknown',  // Access the 'sale_postcode'
                        'message' => Str::limit(strip_tags($notification->message), 150),
                        'created_at' => $notification->created_at->diffForHumans(),
                    ];
                });

            $unreadCount = Notification::where('user_id', Auth::id())->where('notifications.is_read', 0)
                ->count();

            return response()->json([
                'success' => true,
                'notifications' => $notifications,
                'unread_count' => $unreadCount,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch notifications: ' . $e->getMessage(),
            ], 500);
        }
    }
    public function getUserNotifications(Request $request)
    {
        Notification::where('is_read', 0)
            ->where('user_id', Auth::id())
            ->update(['is_read' => 1]);

        $notifications = Notification::query()
            ->where('user_id', Auth::id())
            // Left join with the 'users' table to get the 'notify_by' user (sender)
            ->leftJoin('users as notify_by_users', 'notifications.notify_by', '=', 'notify_by_users.id')
            // Eager load the other relationships for applicants and sales
            ->with([
                'user' => fn($query) => $query->select('id', 'name'), // Eager load the 'user' relationship
                'applicant' => fn($query) => $query->select(
                    'id', 'applicant_name', 'applicant_email', 'applicant_email_secondary', 'applicant_phone', 'applicant_phone_secondary', 'applicant_postcode'
                ), // Eager load the 'applicant' relationship
                'sale' => fn($query) => $query->with('jobCategory', 'jobTitle', 'office', 'unit') // Eager load sale with related jobCategory, jobTitle, office, and unit
            ])
            ->select('notifications.*', 'notify_by_users.name as notify_by_name') // Select 'name' of the notify_by user from the joined table
            ->latest();

        // Applying the search functionality if necessary
        if ($request->has('search.value')) {
            $searchTerm = strtolower($request->input('search.value'));
            if (!empty($searchTerm)) {
                $notifications->where(function ($query) use ($searchTerm) {
                    $query->whereRaw('LOWER(notifications.message) LIKE ?', ["%{$searchTerm}%"])
                        ->orWhereHas('applicant', function ($q) use ($searchTerm) {
                            $q->whereRaw('LOWER(applicants.applicant_name) LIKE ?', ["%{$searchTerm}%"]);
                        })
                        ->orWhereHas('sale', function ($q) use ($searchTerm) {
                            $q->whereRaw('LOWER(sales.sale_postcode) LIKE ?', ["%{$searchTerm}%"]);
                        });
                });
            }
        }

        // Sorting logic
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');
            if ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $notifications->orderBy($orderColumn, $orderDirection);
            }
        } else {
            $notifications->orderBy('notifications.created_at', 'desc');
        }

        if ($request->ajax()) {
            return DataTables::eloquent($notifications)
                ->addIndexColumn() // Automatically adds a serial number to the rows
                ->addColumn('applicant_name', function ($notification) {
                    return $notification->applicant ? $notification->applicant->applicant_name : '-';
                })
                ->addColumn('applicant_email', function ($notification) {
                    return $notification->applicant ? $notification->applicant->applicant_email : '-';
                })
                ->addColumn('applicant_postcode', function ($notification) {
                    return $notification->applicant ? $notification->applicant->applicant_postcode : '-';
                })
                ->addColumn('sale_postcode', function ($notification) {
                    return $notification->sale ? $notification->sale->sale_postcode : '-';
                })
                ->addColumn('office_name', function ($notification) {
                    return $notification->sale->office ? $notification->sale->office->office_name : '-';
                })
                ->addColumn('unit_name', function ($notification) {
                    return $notification->sale->unit ? $notification->sale->unit->unit_name : '-';
                })
                ->addColumn('job_category', function ($notification) {
                    return $notification->sale && $notification->sale->jobCategory ? $notification->sale->jobCategory->name : '-';
                })
                ->addColumn('job_title', function ($notification) {
                    return $notification->sale && $notification->sale->jobTitle ? $notification->sale->jobTitle->name : '-';
                })
                ->addColumn('notify_by_name', function ($notification) {
                    return ucwords($notification->notify_by_name);
                })
                ->addColumn('notes_detail', function ($notification) {
                    return ucwords($notification->message);
                })
                ->addColumn('created_at', function ($notification) {
                    return Carbon::parse($notification->created_at)->format('d M Y, h:i A');
                })
                ->addColumn('job_details', function ($notification) {
                    $position_type = strtoupper(str_replace('-', ' ', $notification->sale->position_type));
                    $position = '<span class="badge bg-primary">' . htmlspecialchars($position_type, ENT_QUOTES) . '</span>';

                    if ($notification->sale->status == 1) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } elseif ($notification->sale->status == 0 && $notification->sale->is_on_hold == 0) {
                        $status = '<span class="badge bg-danger">Closed</span>';
                    } elseif ($notification->sale->status == 2) {
                        $status = '<span class="badge bg-warning">Pending</span>';
                    } elseif ($notification->sale->status == 3) {
                        $status = '<span class="badge bg-danger">Rejected</span>';
                    }

                    // Escape HTML in $status for JavaScript (to prevent XSS)
                    $escapedStatus = htmlspecialchars($status, ENT_QUOTES);

                    // Prepare modal HTML for the "Job Details"
                    $modalHtml = $this->generateJobDetailsModal($notification);

                    // Return the action link with a modal trigger and the modal HTML
                    return '<a href="#" class="dropdown-item" style="color: blue;" onclick="showDetailsModal('
                        . (int)$notification->sale_id . ','
                        . '\'' . htmlspecialchars(Carbon::parse($notification->sale->created_at)->format('d M Y, h:i A'), ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$notification->sale->office->office_name, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$notification->sale->unit->unit_name, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$notification->sale->sale_postcode, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$notification->sale->jobCategory->name, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$notification->sale->jobTitle->name, ENT_QUOTES) . '\','
                        . '\'' . $escapedStatus . '\','
                        . '\'' . htmlspecialchars((string)$notification->sale->timing, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$notification->sale->experience, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$notification->sale->salary, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$position, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$notification->sale->qualification, ENT_QUOTES) . '\','
                        . '\'' . htmlspecialchars((string)$notification->sale->benefits, ENT_QUOTES) . '\')">
                        <iconify-icon icon="solar:square-arrow-right-up-bold" class="text-info fs-24"></iconify-icon>
                        </a>' . $modalHtml;
                })
                ->addColumn('action', function ($notification) {
                    $html = '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" 
                                        href="#" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#crmMarkRequestConfirmOrRejectModal' . (int)$notification->applicant_id . '-' . (int)$notification->sale_id . '"
                                        data-applicant-id="' . (int)$notification->applicant_id . '"
                                        data-sale-id="' . (int)$notification->sale_id . '"
                                        onclick="crmMarkRequestConfirmOrRejectModal(' . (int)$notification->applicant_id . ', ' . (int)$notification->sale_id . ')">
                                        Mark Confirm / Reject CV
                                    </a></li>
                                </ul>
                            </div>';

                    // Modal for notification details
                    $html .= '<div class="modal fade" id="notificationDetailsModal' . $notification->id . '" tabindex="-1" aria-labelledby="notificationDetailsModalLabel' . $notification->id . '" aria-hidden="true">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="notificationDetailsModalLabel' . $notification->id . '">Notification Details</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p><strong>Notification:</strong> ' . $notification->message . '</p>
                                            <p><strong>Applicant Name:</strong> ' . ($notification->applicant ? $notification->applicant->applicant_name : '-') . '</p>
                                            <p><strong>Sale Postcode:</strong> ' . ($notification->sale ? $notification->sale->sale_postcode : '-') . '</p>
                                        </div>
                                    </div>
                                </div>
                            </div>';
                            /** CRM Mark Confirm Or Reject Modal */
                        $html .= '<div id="crmMarkRequestConfirmOrRejectModal' . (int)$notification->applicant_id . '-' . (int)$notification->sale_id . '" class="modal fade" tabindex="-1" aria-labelledby="crmMarkRequestConfirmOrRejectModalLabel' . (int)$notification->applicant_id . '-' . (int)$notification->sale_id . '" aria-hidden="true">
                                    <div class="modal-dialog modal-lg modal-dialog-top">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title" id="crmMarkRequestConfirmOrRejectModalLabel' . (int)$notification->applicant_id . '-' . (int)$notification->sale_id . '">CRM Mark Request Confirm Or Reject</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body modal-body-text-left">
                                                <div class="notificationAlert' . (int)$notification->applicant_id . '-' . (int)$notification->sale_id . ' notification-alert"></div>
                                                <form action="" method="" id="crmMarkRequestConfirmOrRejectForm' . (int)$notification->applicant_id . '-' . (int)$notification->sale_id . '" class="form-horizontal">
                                                    <input type="hidden" name="applicant_id" value="' . (int)$notification->applicant_id . '">
                                                    <input type="hidden" name="sale_id" value="' . (int)$notification->sale_id . '">
                                                    <div class="mb-3">
                                                        <label for="details' . (int)$notification->applicant_id . '-' . (int)$notification->sale_id . '" class="form-label">Notes</label>
                                                        <textarea class="form-control" name="details" id="crmMarkRequestConfirmOrRejectDetails' . (int)$notification->applicant_id . '-' . (int)$notification->sale_id . '" rows="4" required></textarea>
                                                        <div class="invalid-feedback">Please provide details.</div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-primary savecrmMarkRequestButtonConfirm" data-applicant-id="' . (int)$notification->applicant_id . '" data-sale-id="' . (int)$notification->sale_id . '">Confirm</button>
                                                        <button type="button" class="btn btn-primary savecrmMarkRequestButtonReject" data-applicant-id="' . (int)$notification->applicant_id . '" data-sale-id="' . (int)$notification->sale_id . '">Reject</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>';

                    return $html;
                })
                ->rawColumns(['action', 'notify_by_name', 'applicant_name', 'notes_detail', 'job_details', 'unit_name', 'office_name', 'applicant_email', 'applicant_postcode', 'sale_postcode', 'job_category', 'job_title'])
                ->make(true);
        }
    }
    public function markNotificationsAsRead(Request $request)
    {
        // Mark notifications as read
        Notification::where('is_read', 0)
            ->where('user_id', Auth::id())
            ->update(['is_read' => 1]);

        return response()->json(['success' => true]);
    }

    // public function getStats(Request $request)
    // {
    //     // Example: You can replace with your actual queries
    //     return response()->json([
    //         'applicants' => [
    //             'nurses' => 5,
    //             'non_nurses' => 6,
    //             'callbacks' => 4,
    //             'not_interested' => 2,
    //         ],
    //         'sales' => [
    //             'open' => 5,
    //             'close' => 6,
    //             'pending' => 4,
    //             'rejected' => 2,
    //         ],
    //         'quality' => [
    //             'sent_cvs' => 5,
    //             'rejected_cvs' => 6,
    //             'cleared_cvs' => 4,
    //         ],
    //         'chart' => [
    //             'labels' => ['Nurses','Non Nurses','Callbacks','Not Interested'],
    //             'series' => [5,6,4,2],
    //         ]
    //     ]);
    // }
    public function getStats(Request $request)
    {
        $date = $request->input('date') ?? Carbon::parse('2025-02-26')->format('d-m-Y');

        // ✅ Validate date format
        $validator = Validator::make(['date' => $date], [
            'date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->all()], 422);
        }

        // ✅ Safely parse date (handles various formats)
        try {
            $formatted_date = Carbon::parse($date)->format('Y-m-d');
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid date format.'], 400);
        }

        /** -------------------------
         *  APPLICANTS SECTION
         *  ------------------------*/
        $job_category_nurse = JobCategory::whereRaw('LOWER(name) = ?', ['nurse'])->first();

        $nurses = Applicant::where([
                'status' => 1,
                'job_category_id' => $job_category_nurse->id ?? 0
            ])
            ->whereDate('created_at', $formatted_date)
            ->count();

        $non_nurses = Applicant::where('status', 1)
            ->when($job_category_nurse, function ($q) use ($job_category_nurse) {
                $q->where('job_category_id', '!=', $job_category_nurse->id);
            })
            ->whereDate('created_at', $formatted_date)
            ->count();

        $callbacks = ApplicantNote::where('moved_tab_to', 'callback')
            ->whereDate('created_at', $formatted_date)
            ->count();

        $not_interested = Applicant::join('applicants_pivot_sales', 'applicants_pivot_sales.applicant_id', '=', 'applicants.id')
            ->where('applicants.status', 1)
            ->where('applicants_pivot_sales.is_interested', 0)
            ->whereDate('applicants_pivot_sales.created_at', $formatted_date)
            ->count();

        /** -------------------------
         *  SALES SECTION
         *  ------------------------*/
        $open_sales = Sale::where('status', 1)
            ->whereDate('created_at', $formatted_date)
            ->count();

        $close_sales = Audit::where('message', 'sale-closed')
            ->where('auditable_type', 'Horsefly\\Sale')
            ->whereDate('updated_at', $formatted_date)
            ->count();

        $pending_sales = Sale::where('status', 'pending')
            ->whereDate('created_at', $formatted_date)
            ->count();

        $rejected_sales = Audit::where('message', 'sale-rejected')
            ->where('auditable_type', 'Horsefly\\Sale')
            ->whereDate('updated_at', $formatted_date)
            ->count();

        /** -------------------------
         *  QUALITY SECTION
         *  ------------------------*/
        $sent_cvs = CvNote::whereDate('created_at', $formatted_date)->count();

        $rejected_cvs = History::where('sub_stage', 'quality_reject')
            ->where('status', 'active')
            ->whereDate('created_at', $formatted_date)
            ->count();

        $cleared_cvs = History::where('sub_stage', 'quality_cleared')
            ->whereDate('created_at', $formatted_date)
            ->count();

        /** -------------------------
         *  FINAL RESPONSE
         *  ------------------------*/
        return response()->json([
            'date' => Carbon::parse($formatted_date)->format('d M, Y'),
            'applicants' => [
                'nurses' => $nurses,
                'non_nurses' => $non_nurses,
                'callbacks' => $callbacks,
                'not_interested' => $not_interested,
            ],
            'sales' => [
                'open' => $open_sales,
                'close' => $close_sales,
                'pending' => $pending_sales,
                'rejected' => $rejected_sales,
            ],
            'quality' => [
                'sent_cvs' => $sent_cvs,
                'rejected_cvs' => $rejected_cvs,
                'cleared_cvs' => $cleared_cvs,
            ]
        ]);
    }
    public function getChartData(Request $request)
    {
        $inputDate = $request->input('date') ?? Carbon::parse('2025-07-24')->format('d-m-Y');

        // ✅ Validate date format
        $validator = Validator::make(['date' => $inputDate], [
            'date' => 'required|date_format:d-m-Y',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->all()], 422);
        }

        $formattedDate = Carbon::createFromFormat('d-m-Y', $inputDate)->format('Y-m-d');
        $displayDate = Carbon::createFromFormat('d-m-Y', $inputDate)->format('jS F Y');

        $daily_data = [];

        /*** QUALITY ***/
        $daily_data['quality_cvs'] = CvNote::whereDate('created_at', $formattedDate)->count();
        $daily_data['quality_revert'] = RevertStage::where('stage', 'quality_revert')->whereDate('created_at', $formattedDate)->count();
        $daily_data['quality_cvs_rejected'] = History::where(['sub_stage' => 'quality_reject', 'status' => 'active'])
            ->whereDate('created_at', $formattedDate)->count();
        $daily_data['quality_cvs_cleared'] = History::where('sub_stage', 'quality_cleared')
            ->whereDate('created_at', $formattedDate)->count();
        $daily_data['quality_cvs_hold'] = History::where('sub_stage', 'quality_cvs_hold')
            ->whereDate('created_at', $formattedDate)->count();

        /*** CRM ***/
        $daily_data['crm_sent'] = $daily_data['quality_cvs_cleared'];
        $daily_data['crm_open_cvs'] = $daily_data['quality_cvs_hold'];

        $daily_data['crm_rejected'] = History::where(['sub_stage' => 'crm_reject', 'status' => 'active'])
            ->whereDate('created_at', $formattedDate)->count();

        $daily_data['crm_requested'] = History::where('sub_stage', 'crm_request')
            ->whereDate('created_at', $formattedDate)->count();

        $daily_data['crm_request_rejected'] = History::where(['sub_stage' => 'crm_request_reject', 'status' => 'active'])
            ->whereDate('created_at', $formattedDate)->count();

        $daily_data['crm_confirmed'] = History::where('sub_stage', 'crm_request_confirm')
            ->whereDate('created_at', $formattedDate)->count();

        $daily_data['crm_prestart_attended'] = History::where('sub_stage', 'crm_interview_attended')
            ->whereDate('created_at', $formattedDate)->count();

        $daily_data['crm_rebook'] = History::where('sub_stage', 'crm_rebook')
            ->whereDate('created_at', $formattedDate)->count();

        $daily_data['crm_not_attended'] = History::where(['sub_stage' => 'crm_interview_not_attended', 'status' => 'active'])
            ->whereDate('created_at', $formattedDate)->count();

        $daily_data['crm_declined'] = History::where(['sub_stage' => 'crm_declined', 'status' => 'active'])
            ->whereDate('created_at', $formattedDate)->count();

        $daily_data['crm_date_started'] = History::whereIn('sub_stage', ['crm_start_date', 'crm_start_date_back'])
            ->whereDate('created_at', $formattedDate)->count();

        $daily_data['crm_start_date_held'] = History::where(['sub_stage' => 'crm_start_date_hold', 'status' => 'active'])
            ->whereDate('created_at', $formattedDate)->count();

        $daily_data['crm_invoiced'] = History::where('sub_stage', 'crm_invoice')
            ->whereDate('created_at', $formattedDate)->count();

        $daily_data['crm_disputed'] = History::where(['sub_stage' => 'crm_dispute', 'status' => 'active'])
            ->whereDate('created_at', $formattedDate)->count();

        $daily_data['crm_paid'] = History::where('sub_stage', 'crm_paid')->whereDate('created_at', $formattedDate)->count();
        $daily_data['crm_revert'] = RevertStage::where('stage', 'crm_revert')->whereDate('created_at', $formattedDate)->count();

        // ✅ Map the data into chart labels
        $labels = [
            'Sent CVs', 'Quality Revert', 'Request', 'Confirmation', 'Attended',
            'Start Date', 'Invoice', 'Paid', 'Open CVs', 'Rejected CV',
            'Crm Revert', 'Rejected By Request', 'Rebook', 'Not Attended',
            'Start Date Hold', 'Declined', 'Dispute'
        ];

        $series = [
            $daily_data['crm_sent'] ?? 0,
            $daily_data['quality_revert'] ?? 0,
            $daily_data['crm_requested'] ?? 0,
            $daily_data['crm_confirmed'] ?? 0,
            $daily_data['crm_prestart_attended'] ?? 0,
            $daily_data['crm_date_started'] ?? 0,
            $daily_data['crm_invoiced'] ?? 0,
            $daily_data['crm_paid'] ?? 0,
            $daily_data['crm_open_cvs'] ?? 0,
            $daily_data['quality_cvs_rejected'] ?? 0,
            $daily_data['crm_revert'] ?? 0,
            $daily_data['crm_request_rejected'] ?? 0,
            $daily_data['crm_rebook'] ?? 0,
            $daily_data['crm_not_attended'] ?? 0,
            $daily_data['crm_start_date_held'] ?? 0,
            $daily_data['crm_declined'] ?? 0,
            $daily_data['crm_disputed'] ?? 0,
        ];

        return response()->json([
            'date' => $displayDate,
            'series' => $series,
            'labels' => $labels,
        ]);
    }
    public function getStatisticsDetails(Request $request)
    {
        $type = $request->input('type'); // e.g. "nurses", "non_nurses", etc.
        $date = $request->input('date') ?? now()->format('Y-m-d');

        try {
            $formatted_date = Carbon::parse('2025-02-26')->format('Y-m-d');
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid date format.'], 400);
        }

        // Get the base query for Applicants filtered by date
        $query = Applicant::query()
            ->where('applicants.status', 1)
            ->whereDate('applicants.created_at', $formatted_date);

        // Filter by applicant type (based on clicked box)
        $job_category_nurse = JobCategory::whereRaw('LOWER(name) = ?', ['nurse'])->first();

        if ($type === 'nurses' && $job_category_nurse) {
            $query->where('applicants.job_category_id', $job_category_nurse->id);
        } elseif ($type === 'non_nurses' && $job_category_nurse) {
            $query->where('applicants.job_category_id', '!=', $job_category_nurse->id);
        }

        // ✅ Group by job_type (regular / specialist)
        $jobTypeCounts = $query->select('applicants.job_type', DB::raw('COUNT(*) as total'))
            ->groupBy('applicants.job_type')
            ->pluck('total', 'applicants.job_type');

        // ✅ Group by job_source (join job_sources table)
        $jobSources = $query->join('job_sources', 'applicants.job_source_id', '=', 'job_sources.id')
            ->select('job_sources.name', DB::raw('COUNT(applicants.id) as total'))
            ->groupBy('job_sources.name')
            ->pluck('total', 'job_sources.name');

        return response()->json([
            'title' => ucfirst(str_replace('_', ' ', $type)) . ' Applicants',
            'job_types' => [
                'regular' => $jobTypeCounts['regular'] ?? 0,
                'specialist' => $jobTypeCounts['specialist'] ?? 0,
            ],
            'sources' => $jobSources
        ]);
    }


}
