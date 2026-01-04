<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Horsefly\Unit;
use Horsefly\Office;
use Horsefly\Contact;
use Horsefly\ModuleNote;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Exports\UnitsExport;
use Maatwebsite\Excel\Facades\Excel;
use App\Traits\Geocode;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Carbon;
use App\Observers\ActionObserver;
use League\Csv\Reader;

class UnitController extends Controller
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
        return view('units.list');
    }
    public function create()
    {
        return view('units.create');
    }
    public function store(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'office_id' => 'required',
            'unit_name' => 'required|string|max:255',
            'unit_postcode' => ['required', 'string', 'min:3', 'max:8', 'regex:/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d ]+$/'],
            'unit_notes' => 'required|string|max:255',

            // Contact person's details (Array validation)
            'contact_name' => 'required|array',
            'contact_name.*' => 'required|string|max:255',

            'contact_email' => 'required|array',
            'contact_email.*' => 'required|email|max:255',

            'contact_phone' => 'nullable|array',
            'contact_phone.*' => 'nullable|string|max:20',

            'contact_landline' => 'nullable|array',
            'contact_landline.*' => 'nullable|string|max:20',

            'contact_note' => 'nullable|array',
            'contact_note.*' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Get office data
            $unitData = $request->only([
                'office_id',
                'unit_name',
                'unit_postcode',
                'unit_website',
                'unit_notes',
            ]);

            // Format data for office
            $unitData['user_id'] = Auth::id();

            $postcode = $request->unit_postcode;
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

                    $unitData['lat'] = $result['lat'];
                    $unitData['lng'] = $result['lng'];
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unable to locate address: ' . $e->getMessage()
                    ], 400);
                }
            } else {
                $unitData['lat'] = $postcode_query->lat;
                $unitData['lng'] = $postcode_query->lng;
            }

            $unitData['unit_notes'] = $unit_notes = $request->unit_notes . ' --- By: ' . Auth::user()->name . ' Date: ' . Carbon::now()->format('d-m-Y');

            $unit = Unit::create($unitData);

            // Iterate through each contact provided in the request
            foreach ($request->input('contact_name') as $index => $contactName) {
                // Create contact data for each contact in the array
                $contactData = [
                    'contact_name' => $contactName,
                    'contact_email' => $request->input('contact_email')[$index],
                    'contact_phone' => preg_replace('/[^0-9]/', '', $request->input('contact_phone')[$index]),
                    'contact_landline' => $request->input('contact_landline')[$index]
                        ? preg_replace('/[^0-9]/', '', $request->input('contact_landline')[$index])
                        : null,
                    'contact_note' => $request->input('contact_note')[$index] ?? null,
                ];

                // Create each contact and associate it with the office
                $unit->contact()->create($contactData);
            }

            // Generate UID
            $unit->update(['unit_uid' => md5($unit->id)]);

            // Create new module note
            $moduleNote = ModuleNote::create([
                'details' => $unit_notes,
                'module_noteable_id' => $unit->id,
                'module_noteable_type' => 'Horsefly\Unit',
                'user_id' => Auth::id()
            ]);

            $moduleNote->update([
                'module_note_uid' => md5($moduleNote->id)
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Unit created successfully',
                'redirect' => route('units.list')
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating unit: ' . $e->getMessage());

            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function getUnits(Request $request)
    {
        $statusFilter = $request->input('status_filter', '');

        // Base query with eager loading
        $query = Unit::with('contact')
            ->select([
                'units.id',
                'units.office_id',
                'units.unit_name',
                'units.unit_postcode',
                'units.unit_website',
                'units.unit_notes',
                'units.status',
                'units.created_at',
                'units.updated_at',
                DB::raw('offices.office_name as office_name')
            ])
            ->leftJoin('offices', 'units.office_id', '=', 'offices.id');

            // Status filter
            if ($statusFilter == 'active') {
                $query->where('units.status', 1);
            } elseif ($statusFilter == 'inactive') {
                $query->where('units.status', 0);
            }

        // Searching
        $searchTerm = $request->input('search.value', '');
        if ($searchTerm) {
            $like = "%{$searchTerm}%";
            $query->where(function ($q) use ($like) {
                $q->where('units.unit_name', 'LIKE', $like)
                ->orWhere('units.unit_postcode', 'LIKE', $like)
                ->orWhere('units.unit_website', 'LIKE', $like)
                ->orWhere('units.unit_notes', 'LIKE', $like)
                ->orWhere('offices.office_name', 'LIKE', $like)
                ->orWhereHas('contact', function ($c) use ($like) {
                    $c->where('contact_name', 'LIKE', $like)
                        ->orWhere('contact_email', 'LIKE', $like)
                        ->orWhere('contact_phone', 'LIKE', $like)
                        ->orWhere('contact_landline', 'LIKE', $like);
                });
            });
        }

       // Sorting
$orderColumnIndex = $request->input('order.0.column', 0);
$orderColumn = $request->input("columns.$orderColumnIndex.data", 'created_at');
$orderDir = $request->input('order.0.dir', 'desc');

// Mapping the Datatable virtual columns to real DB columns
$contactColumns = [
    'contact_email'   => 'contacts.contact_email',
    'contact_phone'   => 'contacts.contact_phone',
    'contact_landline'=> 'contacts.contact_landline',
];

if (array_key_exists($orderColumn, $contactColumns)) {
    $query->leftJoin('contacts', function ($join) {
        $join->on('contacts.contactable_id', '=', 'units.office_id')
             ->where('contacts.contactable_type', 'Horsefly\\Office');
    });

    $query->orderBy($contactColumns[$orderColumn], $orderDir);
}
else if ($orderColumn !== 'DT_RowIndex') {
    if ($orderColumn === 'office_name') {
        $query->orderBy('offices.office_name', $orderDir);
    } else {
        $query->orderBy('units.' . $orderColumn, $orderDir);
    }
}
else {
    $query->orderBy('units.created_at', 'desc');
}



        if ($request->ajax()) {
            return DataTables::eloquent($query)
                ->addIndexColumn()
                ->addColumn('office_name', fn($unit) => $unit->office_name ?? '-')
                ->filterColumn('office_name', fn($q, $keyword) => $q->where('offices.office_name', 'LIKE', "%{$keyword}%"))
                ->addColumn('unit_name', fn($unit) => $unit->formatted_unit_name)
                ->addColumn('unit_postcode', fn($unit) => $unit->formatted_postcode)
                ->addColumn('contact_email', fn($unit) => $unit->contact->pluck('contact_email')->filter()->implode('<br>') ?: '-')
                ->addColumn('contact_phone', fn($unit) => $unit->contact->pluck('contact_phone')->filter()->implode('<br>') ?: '-')
                ->addColumn('contact_landline', fn($unit) => $unit->contact->pluck('contact_landline')->filter()->implode('<br>') ?: '-')
                ->filterColumn('contact_email', fn($q, $keyword) => $q->orWhereHas('contact', fn($c) => $c->where('contact.contact_email', 'LIKE', "%{$keyword}%")))
                ->filterColumn('contact_phone', fn($q, $keyword) => $q->orWhereHas('contact', fn($c) => $c->where('contact.contact_phone', 'LIKE', "%{$keyword}%")))
                ->filterColumn('contact_landline', fn($q, $keyword) => $q->orWhereHas('contact', fn($c) => $c->where('contact.contact_landline', 'LIKE', "%{$keyword}%")))
                ->orderColumn('contact_email', function ($query, $order) {
                    $query->orderBy('contact.contact_email', $order);
                })
                ->orderColumn('contact_phone', function ($query, $order) {
                    $query->orderBy('contact.contact_phone', $order);
                })
                ->orderColumn('contact_landline', function ($query, $order) {
                    $query->orderBy('contact.contact_landline', $order);
                })
                ->addColumn('created_at', fn($unit) => $unit->formatted_created_at)
                ->addColumn('updated_at', fn($unit) => $unit->formatted_updated_at)
                ->addColumn('unit_notes', function ($unit) {
                    $notes = nl2br(e($unit->unit_notes));
                    return '<a href="#" title="Add Short Note" style="color:blue" onclick="addShortNotesModal(' . (int)$unit->id . ')">' . $notes . '</a>';
                })
                ->addColumn('status', fn($unit) => $unit->status
                    ? '<span class="badge bg-success">Active</span>'
                    : '<span class="badge bg-secondary">Inactive</span>')
                ->addColumn('action', function ($unit) {
                    $postcode = $unit->formatted_postcode;
                    $office_name = $unit->office_name ?? '-';
                    $status = $unit->status
                        ? '<span class="badge bg-success">Active</span>'
                        : '<span class="badge bg-secondary">Inactive</span>';

                    $html = '<div class="btn-group dropstart">
                                <button type="button" class="border-0 bg-transparent p-0" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <iconify-icon icon="solar:menu-dots-square-outline" class="align-middle fs-24 text-dark"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu">';
                    if (Gate::allows('unit-edit')) {
                        $html .= '<li><a class="dropdown-item" href="' . route('units.edit', ['id' => $unit->id]) . '">Edit</a></li>';
                    }
                    if (Gate::allows('unit-view')) {
                        $html .= '<li><a class="dropdown-item" href="#" onclick="showDetailsModal('
                            . (int)$unit->id . ', '
                            . '\'' . e($office_name) . '\', '
                            . '\'' . e($unit->unit_name) . '\', '
                            . '\'' . e($postcode) . '\', '
                            . '\'' . e($status) . '\')">View</a></li>';
                    }
                    if (Gate::allows('unit-view-notes-history') || Gate::allows('unit-view-manager-details')) {
                        $html .= '<li><hr class="dropdown-divider"></li>';
                    }
                    if (Gate::allows('unit-view-notes-history')) {
                        $html .= '<li><a class="dropdown-item" href="#" onclick="viewNotesHistory(' . $unit->id . ')">Notes History</a></li>';
                    }
                    if (Gate::allows('unit-view-manager-details')) {
                        $html .= '<li><a class="dropdown-item" href="#" onclick="viewManagerDetails(' . $unit->id . ')">Manager Details</a></li>';
                    }
                    $html .= '</ul></div>';

                    return $html;
                })
                ->rawColumns(['unit_notes', 'unit_name', 'contact_email', 'contact_landline', 'contact_phone', 'office_name', 'status', 'action'])
                ->make(true);
        }
    }
    public function storeUnitShortNotes(Request $request)
    {
        $user = Auth::user();

        $unit_id = $request->input('unit_id');
        $details = $request->input('details');
        $unit_notes = $details . ' --- By: ' . $user->name . ' Date: ' . now()->format('d-m-Y');

        $updateData = ['unit_notes' => $unit_notes];

        Unit::where('id', $unit_id)->update($updateData);

        // Disable previous module note
        ModuleNote::where([
            'module_noteable_id' => $unit_id,
            'module_noteable_type' => 'Horsefly\Unit'
        ])
            ->orderBy('id', 'desc')
            ->update(['status' => 0]);

        // Create new module note
        $moduleNote = ModuleNote::create([
            'details' => $unit_notes,
            'module_noteable_id' => $unit_id,
            'module_noteable_type' => 'Horsefly\Unit',
            'user_id' => $user->id,
            'status' => 1,
        ]);

        $moduleNote->update(['module_note_uid' => md5($moduleNote->id)]);

        // Log audit
        $unit = Unit::where('id', $unit_id)->select('unit_name', 'unit_notes', 'id')->first();
        $observer = new ActionObserver();
        $observer->customUnitAudit($unit, 'unit_notes');

        return redirect()->to(url()->previous());
    }
    public function unitDetails($id)
    {
        $unit = Unit::findOrFail($id);
        return view('units.details', compact('unit'));
    }
    public function edit($id)
    {
        $offices = Office::where('status', 1)->select('id','office_name')->get();
        $unit = Unit::find($id);
        $contacts = Contact::where('contactable_id',$unit->id)
                        ->where('contactable_type','Horsefly\Unit')
                        ->get();

        return view('units.edit', compact('offices','unit','contacts'));
    }
    public function update(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'office_id' => 'required',
            'unit_name' => 'required|string|max:255',
            'unit_postcode' => ['required', 'string', 'min:3', 'max:8', 'regex:/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d ]+$/'],
            'unit_notes' => 'required|string|max:255',

            // Contact person's details (Array validation)
            'contact_name' => 'required|array',
            'contact_name.*' => 'required|string|max:255',

            'contact_email' => 'required|array',
            'contact_email.*' => 'required|email|max:255',

            'contact_phone' => 'nullable|array',
            'contact_phone.*' => 'nullable|string|max:20',

            'contact_landline' => 'nullable|array',
            'contact_landline.*' => 'nullable|string|max:20',

            'contact_note' => 'nullable|array',
            'contact_note.*' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Get office data
            $unitData = $request->only([
                'office_id',
                'unit_name',
                'unit_postcode',
                'unit_website',
                'unit_notes',
            ]);

            // Get the office ID from the request
            $id = $request->input('unit_id');

            // Retrieve the office record
            $unit = Unit::find($id);

            // If the applicant doesn't exist, throw an exception
            if (!$unit) {
                throw new \Exception("Unit not found with ID: " . $id);
            }

            $postcode = $request->unit_postcode;

            if($postcode != $unit->unit_postcode){
                if (strlen($postcode) < 6) {
                    // Search in 'outpostcodes' table
                    $postcode_query = DB::table('outcodepostcodes')->where('outcode', $postcode)->first();
                } else {
                    // Search in 'postcodes' table
                    $postcode_query = DB::table('postcodes')->where('postcode', $postcode)->first();
                }

                if ($postcode_query) {
                    $unitData['lat'] = $postcode_query->lat;
                    $unitData['lng'] = $postcode_query->lng;
                }
            }

            $unitData['unit_notes'] = $unit_notes = $request->unit_notes . ' --- By: ' . Auth::user()->name . ' Date: ' . Carbon::now()->format('d-m-Y');

            // Update the applicant with the validated and formatted data
            $unit->update($unitData);

            ModuleNote::where([
                'module_noteable_id' => $id,
                'module_noteable_type' => 'Horsefly\Unit'
            ])
                ->where('status', 1)
                ->update(['status' => 0]);

            $moduleNote = ModuleNote::create([
                'details' => $unit_notes,
                'module_noteable_id' => $unit->id,
                'module_noteable_type' => 'Horsefly\Unit',
                'user_id' => Auth::id()
            ]);

            $moduleNote->update([
                'module_note_uid' => md5($moduleNote->id)
            ]);

            Contact::where('contactable_id', $unit->id)
                ->where('contactable_type', 'Horsefly\Unit')->delete();

            // Iterate through each contact provided in the request
            foreach ($request->input('contact_name') as $index => $contactName) {
                // Create contact data for each contact in the array
                $contactData = [
                    'contact_name' => $contactName,
                    'contact_email' => $request->input('contact_email')[$index],
                    'contact_phone' => preg_replace('/[^0-9]/', '', $request->input('contact_phone')[$index]),
                    'contact_landline' => $request->input('contact_landline')[$index]
                        ? preg_replace('/[^0-9]/', '', $request->input('contact_landline')[$index])
                        : null,
                    'contact_note' => $request->input('contact_note')[$index] ?? null,
                ];

                // Create each contact and associate it with the office
                $unit->contact()->create($contactData);
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Unit updated successfully',
                'redirect' => route('units.list')
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating unit: ' . $e->getMessage());
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the unit. Please try again.'
            ], 500);
        }
    }
    public function destroy($id)
    {
        $unit = Unit::findOrFail($id);
        $unit->delete();
        return redirect()->route('units.list')->with('success', 'Unit deleted successfully');
    }
    public function show($id)
    {
        $unit = Unit::findOrFail($id);
        return view('units.show', compact('unit'));
    }
    public function export(Request $request)
    {
        $type = $request->query('type', 'all'); // Default to 'all' if not provided
        
        return Excel::download(new UnitsExport($type), "units_{$type}.csv");
    }
}
