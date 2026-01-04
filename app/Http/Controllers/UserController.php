<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Horsefly\Audit;
use Horsefly\User;
use Horsefly\Role;
use App\Http\Controllers\Controller;
use Horsefly\LoginDetail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;
use App\Exports\UsersExport;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;
use Illuminate\Support\Arr;

class UserController extends Controller
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
        $roles = Role::orderBy('name', 'asc')->get();

        return view('users.list', compact('roles'));
    }
    public function userLogin()
    {
        $roles = Role::orderBy('name', 'asc')->get();

        return view('reports.users-login-report', compact('roles'));
    }
    public function create()
    {
        return view('users.create');
    }
    public function store(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'role' => 'required',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        try {
            // Get office data
            $userData = $request->only([
                'name',
                'email'
            ]);

            $password = Hash::make($request->password);
            $userData['password'] = $password;

            $user = User::create($userData);

            if ($request->has('role')) {
                $user->syncRoles([$request->role]);
            }

            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'redirect' => route('users.list')
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating user: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating the user. Please try again.'
            ], 500);
        }
    }
    public function getUsers(Request $request)
    {
        $statusFilter = $request->input('status_filter', ''); // Default is empty (no filter)

        $model = User::query()
            ->leftJoin('model_has_roles', function ($join) {
                $join->on('users.id', '=', 'model_has_roles.model_id')
                    ->where('model_has_roles.model_type', '=', User::class);
            })
            ->leftJoin('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->select('users.*', 'roles.name as role_name'); // Add alias for sorting

        // Filter by status if it's not empty
        switch ($statusFilter) {
            case 'inactive':
                $model->where('users.is_active', 0);
                break;
            default:
            case 'active':
                $model->where('users.is_active', 1);
                break;
        }

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
                    return $user->formatted_name; // Using accessor
                })
                ->addColumn('role_name', function ($user) {
                    $role = $user->role_name; // returns the first (or only) role name
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
                        $status = '<span class="badge bg-success">Active</span>';
                    } else {
                        $status = '<span class="badge bg-danger">Inactive</span>';
                    }

                    return $status;
                })
                ->addColumn('action', function ($user) {
                    $name = $user->formatted_name;
                    $email = $user->email;
                    $roleName = ucwords($user->role_name);
                    $status = '';

                    if ($user->is_active) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } else {
                        $status = '<span class="badge bg-danger">Inactive</span>';
                    }
                    $html = '';
                    $html .= '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">
                                    <li>
                                        <a class="dropdown-item" href="#" onclick="showEditModal(
                                            \'' . (int)$user->id . '\',
                                            \'' . addslashes(htmlspecialchars($name)) . '\',
                                            \'' . addslashes(htmlspecialchars($email)) . '\',
                                            \'' . addslashes(htmlspecialchars($user->is_active)) . '\',
                                            \'' . addslashes(htmlspecialchars($roleName)) . '\'
                                        )">Edit</a>
                                    </li>
                                    <li><a class="dropdown-item" href="#" onclick="showDetailsModal(
                                        \'' . (int)$user->id . '\',
                                        \'' . addslashes(htmlspecialchars($name)) . '\',
                                        \'' . addslashes(htmlspecialchars($email)) . '\',
                                        \'' . addslashes(htmlspecialchars($roleName)) . '\',
                                        \'' . addslashes(htmlspecialchars($status)) . '\'
                                    )">View</a></li>';
                    if ($user->is_active == true) {
                        $html .= '<li><a class="dropdown-item" href="#" onclick="changeStatusModal(
                                            \'' . (int)$user->id . '\', \'0\'
                                        )">Mark as Inctive</a></li>';
                    } else {
                        $html .=  '<li><a class="dropdown-item" href="#" onclick="changeStatusModal(
                                            \'' . (int)$user->id . '\', \'1\'
                                        )">Mark as Active</a></li>';
                    }
                    $url = route('users.activity_log', ['id' => $user->id]);
                    $html .= '<li><a target="_blank" class="dropdown-item" href="' . e($url) . '">Activity Log</a></li>';
                    '</ul>
                            </div>';

                    return $html;
                })

                ->rawColumns(['name', 'is_active', 'action', 'role_name'])
                ->make(true);
        }
    }
    public function activityLogIndex($id)
    {
        $user_id = $id;
        return view('users.activity-logs', compact('user_id'));
    }
    public function getUserActivityLogs(Request $request)
    {
        $id = $request->id;
        $model = Audit::query()
            ->where('user_id', $id)->latest('created_at');

       if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn() // This will automatically add a serial number to the rows
                ->addColumn('details', function ($audit) {
                    $content = "";
                    $content .= '<a href="#" class=""
                        data-controls-modal="#modal_audit_details'.$audit->id.'"
                        data-bs-backdrop="static" data-bs-keyboard="false" data-bs-toggle="modal"
                        data-bs-target="#modal_audit_details'.$audit->id.'">
                        <iconify-icon icon="solar:square-arrow-right-up-bold" class="text-info fs-24"></iconify-icon>
                    </a>';

                    $content .= '<div id="modal_audit_details'.$audit->id.'" class="modal fade" tabindex="-1">';
                    $content .= '<div class="modal-dialog modal-lg modal-dialog-top">';
                    $content .= '<div class="modal-content">';
                    $content .= '<div class="modal-header">';
                    $content .= '<h5 class="modal-title">Action Details</h5>';
                    $content .= '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>';
                    $content .= '</div>';
                    $content .= '<div class="modal-body modal-body-text-left">';

                    // Decode JSON data safely
                    $data = is_array($audit->data) ? $audit->data : json_decode($audit->data, true);
                    $changes = Arr::get($data, 'changes_made');

                    if (!empty($changes) && is_array($changes)) {
                        $content .= '<h5><strong>Changes</strong></h5>';
                        foreach ($changes as $key_2 => $val_2) {
                            $content .= '<div class="col-1"></div>';
                            $label = e(str_replace('_', ' ', $key_2));

                            if (is_array($val_2)) {
                                $content .= '<p><span><b>'.ucwords($label).'</b>: </span>'.e(implode(', ', $val_2)).'</p>';
                            } else {
                                $content .= '<p><span><b>'.ucwords($label).'</b>: </span>'.e($val_2).'</p>';
                            }
                        }
                    } else {
                        $content .= '<h5><strong>Details</strong></h5>';
                        foreach ($data as $key_1 => $val_1) {
                            $content .= '<div class="col-1"></div>';
                            $label = e(str_replace('_', ' ', $key_1));

                            if (is_array($val_1)) {
                                $content .= '<p><span><b>'.ucwords($label).'</b>: </span>'.e(implode(', ', $val_1)).'</p>';
                            } else {
                                $content .= '<p><span><b>'.ucwords($label).'</b>: </span>'.e($val_1).'</p>';
                            }
                        }
                    }

                    $content .= '</div>';
                    $content .= '<div class="modal-footer">';
                    $content .= '<button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>';
                    $content .= '</div>';
                    $content .= '</div>';
                    $content .= '</div>';
                    $content .= '</div>';

                    return $content;
                })
                ->addColumn('created_at', function ($audit){
                    return Carbon::parse($audit->created_at)->format('d M Y, h:i A');
                })
                ->addColumn('auditable_type', function ($audit) {
                    $module = explode("\\",$audit->auditable_type);
                    return $module[count($module) - 1];
                })
                ->rawColumns(['details', 'auditable_type'])
                ->make(true);
            }
    }
    public function changeUserStatus(Request $request)
    {
        $user_id = $request->input('user_id');

        $user = User::findOrFail($user_id);
        $user->update(['is_active' => $request->status]);

        return response()->json(['success' => true, 'message' => 'User status updated successfully.']);
    }
    public function getUsersLoginReport(Request $request)
    {
        $dateFilter = $request->input('date_filter', '');
        $filterDate = $dateFilter ? Carbon::parse($dateFilter)->toDateString() : Carbon::now()->toDateString();

        // Subquery for latest login per user (FAST)
        $latestLogin = LoginDetail::selectRaw('MAX(id) as latest_id')
            ->whereDate('created_at', $filterDate)
            ->groupBy('user_id');

        // Join it efficiently
        $model = LoginDetail::query()
            ->joinSub($latestLogin, 'latest', function ($join) {
                $join->on('login_details.id', '=', 'latest.latest_id');
            })
            ->join('users', 'users.id', '=', 'login_details.user_id')
            ->select([
                'login_details.*',
                'users.name as user_name',
            ]);

        // Sorting
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');
            $validColumn = in_array($orderColumn, ['user_name', 'login_details.created_at', 'login_details.login_at', 'login_details.logout_at']);

            $model->orderBy($validColumn ? $orderColumn : 'login_details.created_at', $orderDirection);
        } else {
            $model->orderBy('login_details.created_at', 'desc');
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn()
                ->editColumn('user_name', fn($row) => e($row->user_name))
                ->editColumn('created_at', fn($row) => Carbon::parse($row->created_at)->format('d M Y'))
                ->addColumn('credit_hours', function ($row) {
                    if ($row->login_at && $row->logout_at) {
                        try {
                            $login = Carbon::parse($row->login_at)->timezone(config('app.timezone'));
                            $logout = Carbon::parse($row->logout_at)->timezone(config('app.timezone'));

                            // Ensure logout is not before login
                            if ($logout->lessThan($login)) {
                                // Swap if accidentally reversed or use now() if not realistic
                                return '0h 0m';
                            }

                            $diffInSeconds = abs($logout->diffInSeconds($login)); // always positive
                            $hours = floor($diffInSeconds / 3600);
                            $minutes = floor(($diffInSeconds % 3600) / 60);

                            return "{$hours}h {$minutes}m";
                        } catch (\Exception $e) {
                            return '0h 0m';
                        }
                    }

                    // If no logout yet → show ongoing or zero
                    if ($row->login_at && !$row->logout_at) {
                        return '<span class="text-warning">Active</span>';
                    }

                    return '0h 0m';
                })
                ->addColumn('action', function ($row) {
                    return '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="' . route('reports.userLoginHistory', ['id' => $row->user_id]) . '">View History</a></li>
                                </ul>
                            </div>';
                })
                ->rawColumns(['action'])
                ->make(true);
        }
    }
    public function getUserLoginHistory(Request $request)
    {
        $id = $request->input('id');

        // Base query
        $model = LoginDetail::query()
            ->join('users', 'users.id', '=', 'login_details.user_id')
            ->select('login_details.*', 'users.name as user_name')
            ->where('login_details.user_id', $id);

        // Sorting
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            $validColumns = ['user_name', 'login_details.created_at', 'login_details.login_at', 'login_details.logout_at'];
            if (in_array($orderColumn, $validColumns)) {
                $model->orderBy($orderColumn, $orderDirection);
            } else {
                $model->orderBy('login_details.created_at', 'desc');
            }
        } else {
            $model->orderBy('login_details.created_at', 'desc');
        }

        // Search Filter
        if ($request->has('search.value') && $search = trim($request->input('search.value'))) {
            $model->where(function ($query) use ($search) {
                $formattedDate = null;

                try {
                    $parsed = Carbon::createFromFormat('d M Y', $search);
                    $formattedDate = $parsed->format('Y-m-d');
                } catch (\Exception $e) {
                    try {
                        $parsed = Carbon::parse($search);
                        $formattedDate = $parsed->format('Y-m-d');
                    } catch (\Exception $ex) {
                        $formattedDate = null;
                    }
                }

                $query->where(function ($sub) use ($search, $formattedDate) {
                    // text search
                    $sub->orWhere('users.name', 'LIKE', "%{$search}%")
                        ->orWhere('login_details.created_at', 'LIKE', "%{$search}%")
                        ->orWhere('login_details.login_at', 'LIKE', "%{$search}%")
                        ->orWhere('login_details.logout_at', 'LIKE', "%{$search}%");

                    // date search (prefer this)
                    if ($formattedDate) {
                        $sub->orWhereDate('login_details.created_at', $formattedDate)
                            ->orWhereDate('login_details.login_at', $formattedDate)
                            ->orWhereDate('login_details.logout_at', $formattedDate);
                    }
                });
            });
        }


        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn()
                ->editColumn('user_name', fn($row) => e($row->user_name))
                ->editColumn('created_at', fn($row) => Carbon::parse($row->created_at)->format('d M Y'))
                ->addColumn('credit_hours', function ($row) {
                    if ($row->login_at && $row->logout_at) {
                        try {
                            $login = Carbon::parse($row->login_at);
                            $logout = Carbon::parse($row->logout_at);

                            if ($logout->lessThanOrEqualTo($login)) {
                                return '0h 0m';
                            }

                            $diffInSeconds = $logout->diffInSeconds($login);
                            $hours = intdiv($diffInSeconds, 3600);
                            $minutes = intdiv($diffInSeconds % 3600, 60);

                            return "{$hours}h {$minutes}m";
                        } catch (\Exception $e) {
                            return '0h 0m';
                        }
                    }
                    return '0h 0m';
                })
                ->rawColumns(['credit_hours'])
                ->make(true);
        }
    }

    public function userLoginHistoryIndex($id)
    {
        $user = User::findOrFail($id);
        $history = LoginDetail::where('user_id', $id)->get();

        return view('reports.login-history', compact('history', 'user'));
    }
    public function userDetails($id)
    {
        $user = User::findOrFail($id);
        return view('users.details', compact('user'));
    }
    public function edit($id)
    {
        return view('users.edit');
    }
    public function update(Request $request)
    {
        // Get user ID from request
        $id = $request->input('id');

        // Validation
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($id),
            ],
            'role' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form',
            ], 422);
        }

        try {
            // Find the user
            $user = User::findOrFail($id);

            // Prepare data
            $userData = $request->only(['name', 'email']);

            if ($request->filled('password')) {
                $userData['password'] = Hash::make($request->password);
            }

            $userData['is_active'] = $request->status;

            // Update user
            $user->update($userData);

            // Update role
            if ($request->has('role')) {
                $user->syncRoles([$request->role]);
            }

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'redirect' => route('users.list')
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating user: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the user. Please try again.'
            ], 500);
        }
    }
    public function destroy($id)
    {
        $user = User::findOrFail($id);
        $user->delete();
        return redirect()->route('users.list')->with('success', 'User deleted successfully');
    }
    public function show($id)
    {
        $user = User::findOrFail($id);
        return view('users.show', compact('user'));
    }
    public function export(Request $request)
    {
        $type = $request->query('type', 'all'); // Default to 'all' if not provided

        return Excel::download(new UsersExport($type), "users_{$type}.csv");
    }
}
