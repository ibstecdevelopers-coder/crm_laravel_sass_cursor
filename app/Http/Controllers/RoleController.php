<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
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
        return view('roles.list');
    }
    public function permissionIndex()
    {
        $users = DB::table('users')->where('is_active', true)->orderBy('name', 'asc')->get();

        return view('permissions.list', compact('users'));
    }
    public function create()
    {
        return view('roles.create');
    }
    public function store(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:roles,name',
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        try {
            $role = Role::create([
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
    public function permissionStore(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:permissions,name',
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
            $permission = \Spatie\Permission\Models\Permission::create([
                'name' => strtolower(str_replace(' ', '-', $request->input('name'))),
                'guard_name' => 'web',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permission created successfully',
                'redirect' => route('permissions.list')
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating permission: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating the permission. Please try again.'
            ], 500);
        }
    }
    public function getPermissions(Request $request)
    {
        $statusFilter = $request->input('status_filter', ''); // Default is empty (no filter)
        $query = \Spatie\Permission\Models\Permission::query();

        // Search filter
        if ($request->has('search.value')) {
            $searchTerm = strtolower(str_replace(' ', '-', $request->input('search.value')));
            if (!empty($searchTerm)) {
            // Replace hyphens with spaces in DB and search term for comparison
            $query->whereRaw('LOWER(REPLACE(name, "-", " ")) LIKE ?', ["%" . str_replace('-', ' ', $searchTerm) . "%"]);
            }
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
                ->addColumn('name', function ($permission) {
                    return ucwords(str_replace('-', ' ', $permission->name));
                })
                ->addColumn('slug', function ($permission) {
                    return $permission->name;
                })
                ->addColumn('created_at', function ($permission) {
                    return $permission->created_at ? $permission->created_at->format('d-m-Y, h:i A') : '-';
                })
                ->addColumn('action', function ($permission) {
                    $name = ucwords(str_replace('-', ' ', $permission->name));
                    return '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">
                                     <li>
                                        <a class="dropdown-item" href="#" onclick="showEditModal(
                                            \'' . $permission->id . '\',
                                            \'' . addslashes(htmlspecialchars($name)) . '\'
                                        )">Edit</a>
                                    </li>
                                </ul>
                            </div>';
                })
                ->rawColumns(['action', 'slug'])
                ->make(true);
        }
    }
    public function getRoles(Request $request)
    {
        $query = Role::query();

        // Search filter
        if ($request->has('search.value')) {
            $searchTerm = strtolower($request->input('search.value'));
            if (!empty($searchTerm)) {
                $query->whereRaw('LOWER(name) LIKE ?', ["%{$searchTerm}%"]);
            }
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
                ->addColumn('name', function ($role) {
                    return ucwords($role->name);
                })
                ->addColumn('permissions_count', function ($role) {
                    $totalPermissions = \Spatie\Permission\Models\Permission::count();
                    $assignedPermissions = $role->permissions()->count();
                    return "{$assignedPermissions} / {$totalPermissions}";
                })
                ->addColumn('created_at', function ($role) {
                    return $role->created_at ? $role->created_at->format('d-m-Y, h:i A') : '-';
                })
                ->addColumn('updated_at', function ($role) {
                    return $role->updated_at ? $role->updated_at->format('d-m-Y, h:i A') : '-';
                })
                ->addColumn('action', function ($role) {
                    return '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="' . route('roles.edit', ['id' => $role->id]) . '">Edit</a></li>
                                    <li><a class="dropdown-item" href="'. route('roles.view', ['id' => $role->id]) .'">View</a></li>
                                </ul>
                            </div>';
                })
                ->rawColumns(['action','updated_at', 'created_at'])
                ->make(true);
        }
    }
    public function edit($id)
    {
        $role = DB::table('roles')->where('id', $id)->first();
        $permissions = DB::table('permissions')->get();
        $rolePermissions = DB::table('role_has_permissions')->where('role_id', $role->id)->pluck('permission_id')->toArray();
        $rolePermissions = array_map('intval', $rolePermissions);

        // Group permissions by first word before the hyphen
        $groupedPermissions = collect($permissions)->groupBy(function ($permission) {
            return explode('-', $permission->name)[0];
        });
        
        return view('roles.edit', compact('role', 'permissions', 'rolePermissions', 'groupedPermissions'));
    }
    public function view($id)
    {
        $role = DB::table('roles')->where('id', $id)->first();
        $permissions = DB::table('permissions')->get();
        $rolePermissions = DB::table('role_has_permissions')->where('role_id', $role->id)->pluck('permission_id')->toArray();
        $rolePermissions = array_map('intval', $rolePermissions);

        // Group permissions by first word before the hyphen
        $groupedPermissions = collect($permissions)->groupBy(function ($permission) {
            return explode('-', $permission->name)[0];
        });
        
        return view('roles.view', compact('role', 'permissions', 'rolePermissions', 'groupedPermissions'));
    }
    public function permissionUpdate(Request $request)
    {
        $id = $request->input('id');
        // Validation
        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('permissions', 'name')->ignore($id),
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
            $permission = \Spatie\Permission\Models\Permission::findOrFail($id);

            // Update role name
            $permission->name = strtolower(str_replace(' ', '-', $request->input('name')));
            $permission->save();

            return response()->json([
                'success' => true,
                'message' => 'Permission updated successfully',
                'redirect' => route('permissions.list')
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating permission: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the permission. Please try again.'
            ], 500);
        }
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
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,name',
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
}
