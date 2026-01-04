<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Horsefly\Role;
use App\Http\Controllers\Controller;
use Horsefly\IpAddress;
use App\Exports\IPAddressExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IPAddressController extends Controller
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
        $users = DB::table('users')->where('is_active', true)->orderBy('name', 'asc')->get();

        return view('ip-address.list', compact('users'));
    }
    public function store(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'ip_address' => 'required|string|max:255|unique:ip_addresses,ip_address',
            'user_id' => 'required|integer',
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
            IpAddress::create([
                'ip_address' => $request->input('ip_address'),
                'user_id' => $request->input('user_id'),
                // 'mac_address' => $request->input('mac_address'),
                // 'device_type' => $request->input('device_type'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'IP Address created successfully',
                'redirect' => route('ip-address.list')
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating ip: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating the ip. Please try again.'
            ], 500);
        }
    }
    public function getIPs(Request $request)
    {
        $statusFilter = $request->input('status_filter', ''); // Default is empty (no filter)

        $query = IpAddress::query()
            ->leftJoin('users', 'ip_addresses.user_id', '=', 'users.id')
            ->select('ip_addresses.*', 'users.name as user_name')
            ->where('ip_addresses.user_id', '!=', null)
            ->with('user');

        // Filter by status if it's not empty
        if ($statusFilter == 'active') {
            $query->where('ip_addresses.status', 1);
        } elseif ($statusFilter == 'inactive') {
            $query->where('ip_addresses.status', 0);
        }

        // Search filter
        if ($request->has('search.value')) {
            $searchTerm = (string) $request->input('search.value');

            if (!empty($searchTerm)) {
                $query->where(function ($query) use ($searchTerm) {
                    $likeSearch = "%{$searchTerm}%";

                    $query->whereRaw('LOWER(ip_addresses.ip_address) LIKE ?', [$likeSearch])
                        ->orWhereRaw('LOWER(ip_addresses.created_at) LIKE ?', [$likeSearch]);

                    $query->orWhereHas('user', function ($q) use ($likeSearch) {
                        $q->where('users.name', 'LIKE', "%{$likeSearch}%");
                    });
                });
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
                ->addColumn('user_name', function ($ip) {
                    return $ip->user ? $ip->user->name : '-';
                })
                ->addColumn('created_at', function ($ip) {
                    return $ip->created_at ? $ip->created_at->format('d-m-Y h:i A') : '-';
                })
                ->addColumn('status', function ($ip) {
                    $status = '';
                    if ($ip->status) {
                        $status = '<span class="badge bg-success">Active</span>';
                    } else {
                        $status = '<span class="badge bg-danger">Inactive</span>';
                    }

                    return $status;
                })
                ->addColumn('action', function ($ip) {
                    $ip_address = $ip->ip_address;
                    $user_id = $ip->user_id;
                    $status = $ip->status;
                    return '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">
                                    <li>
                                        <a class="dropdown-item" href="#" onclick="showEditModal(
                                            \'' . $ip->id . '\',
                                            \'' . addslashes(htmlspecialchars($ip_address)) . '\',
                                            \'' . addslashes(htmlspecialchars($user_id)) . '\',
                                            \'' . addslashes(htmlspecialchars($status)) . '\'
                                        )">Edit</a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="#" onclick="deleteIpAddress('. $ip->id .'); return false;">Delete</a>
                                    </li>
                                </ul>
                            </div>';
                })
                ->rawColumns(['action', 'user_name', 'status'])
                ->make(true);
        }
    }
    public function update(Request $request)
    {
        $id = $request->input('id');

        // Validation
        $validator = Validator::make($request->all(), [
            'ip_address' => [
                'required',
                'string',
                Rule::unique('ip_addresses', 'ip_address')->ignore($id, 'id'),
            ],
            'user_id' => [
                'required',
            ],
            'status' => [
                'nullable', // optional, make it 'required|in:0,1' if it's only 0/1
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        try {
            // Retrieve the IP address record
            $ip = IpAddress::findOrFail($id);

            // Update fields
            $ip->ip_address = $request->input('ip_address');
            $ip->user_id    = $request->input('user_id');
            $ip->status     = $request->input('status');
            $ip->save();

            return response()->json([
                'success'  => true,
                'message'  => 'IP Address updated successfully',
                'redirect' => route('ip-address.list'),
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating IP Address: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the IP Address. Please try again.'
            ], 500);
        }
    }
    public function destroy(Request $request)
    {
        $id = $request->input('id');

        $ip = IpAddress::withTrashed()->findOrFail($id);
        $ip->forceDelete(); // Permanently delete the record

        return redirect()->route('ip-address.list')->with('success', 'IP Address deleted permanently');
    }
    public function show($id)
    {
        $role = Role::findOrFail($id);
        return view('ip-address.show', compact('role'));
    }
    public function export(Request $request)
    {
        $type = $request->query('type', 'all'); // Default to 'all' if not provided
        
        return Excel::download(new IPAddressExport($type), "ip_address_{$type}.csv");
    }
}
