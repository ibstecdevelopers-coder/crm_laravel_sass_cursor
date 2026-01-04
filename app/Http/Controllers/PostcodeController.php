<?php

namespace App\Http\Controllers;

use Horsefly\CVNote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Horsefly\Sale;
use Horsefly\Applicant;
use Horsefly\Office;
use Horsefly\Unit;
use Carbon\Carbon;
use Horsefly\JobCategory;
use Horsefly\JobTitle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Traits\Geocode;

class PostcodeController extends Controller
{
    use Geocode;

    public function index()
    {
        $jobCategories = JobCategory::where('is_active', 1)->orderBy('name', 'asc')->get();

        return view('postcode-finder.list', compact('jobCategories'));
    }
    public function getPostcodeResults(Request $request)
    {
        $today = Carbon::parse(date("Y-m-d"));

        // Validate the request
        $validator = Validator::make($request->all(), [
            'postcode' => 'required',
            'radius' => 'required',
            'job_category_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Please fix the errors in the form'
            ], 422);
        }

        $postcode = $request->Input('postcode');
        $radius = $request->Input('radius');
        $job_category_id = $request->Input('job_category_id');

        $job_result = null;
        $lati = null;
        $longi = null;

        // Helper function to check if lat/lng are valid
        $isValidCoordinates = function ($lat, $lng) {
            return !empty($lat) && $lat != null && !empty($lng) && $lng != null;
        };

        // Retrieve job result matching the postcode in Sale
        $job_result = Sale::where('sale_postcode', $postcode)
            ->first();

        // If not found in Sale or lat/lng are invalid, try in Applicant
        if (!$job_result || !$isValidCoordinates($job_result->lat, $job_result->lng)) {
            $job_result = Applicant::where('applicant_postcode', $postcode)
                ->first();

            if(!$job_result || !$isValidCoordinates($job_result->lat, $job_result->lng)){
                try {
                    $result = $this->geocode($postcode);

                    // If geocode fails, throw
                    if (!isset($result['lat']) || !isset($result['lng'])) {
                        throw new \Exception('Geolocation failed. Latitude and longitude not found.');
                    }

                    $lati = $result['lat'];
                    $longi = $result['lng'];

                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unable to locate address: ' . $e->getMessage()
                    ], 400);
                }

            }else{
                $lati = $job_result->lat;
                $longi = $job_result->lng;
            }

        }else{
            $lati = $job_result->lat;
            $longi = $job_result->lng;
        }

        // Initialize coordinate results
        $data['cordinate_results'] = [];

        // Get coordinate results based on distance and job category
        $data['cordinate_results'] = $this->distance($lati, $longi, $radius, $job_category_id);

        if ($data['cordinate_results']->isNotEmpty()) {
            foreach ($data['cordinate_results'] as &$job) {
                $sent_cv_limit = CVNote::where(['sale_id' => $job->id, 'status' => 1])->count();
                $job['sent_cv_count'] = $sent_cv_limit;
                $job['cv_limit_remains'] = $job->cv_limit - $sent_cv_limit;
                $newDate = Carbon::parse($job->created_at);
                $different_days = $today->diffInDays($newDate);

                $office_id = $job['office_id'];
                $unit_id = $job['unit_id'];
                $category_id = $job['job_category_id'];
                $title_id = $job['job_title_id'];

                $office = Office::select("office_name")
                    ->where(["id" => $office_id, "status" => 1])
                    ->first();
                $office = $office->office_name;

                $unit = Unit::select("unit_name")
                    ->where(["id" => $unit_id, "status" => 1])
                    ->first();
                $unit = $unit->unit_name;
                
                $jobCategory = JobCategory::select("name")
                    ->where(["id" => $category_id])
                    ->first();
                $jobCategory = $jobCategory->name;
                
                $jobTitle = JobTitle::select("name")
                    ->where(["id" => $title_id])
                    ->first();
                $jobTitle = $jobTitle->name;

                $job['office_name'] = $office;
                $job['unit_name'] = $unit;
                $job['job_category'] = $jobCategory;
                $job['job_title'] = $jobTitle;

                $job['days_diff'] = $different_days <= 7 ? 'true' : 'false';
            }
        } else {
            $data['cordinate_results'] = [];
        }

        return response()->json([
            'success' => true,
            'data' => $data,
            'postcode' => $postcode,
            'radius' => $radius,
            'job_category_id' => $job_category_id
        ]);
    }
    function distance($lat, $lon, $radius, $job_category_id)
    {
        $location_distance = Sale::selectRaw("
            *, 
            (ACOS(SIN(? * PI() / 180) * SIN(lat * PI() / 180) + 
            COS(? * PI() / 180) * COS(lat * PI() / 180) * 
            COS((? - lng) * PI() / 180)) * 6371) AS distance
        ", [$lat, $lat, $lon])
        ->having("distance", "<", $radius)  // No need to convert radius anymore
        ->orderBy("distance")
        ->where("status", 1)
        ->where("is_on_hold", 0)
        ->where('job_category_id', $job_category_id)
        ->get();

        return $location_distance;
    }



}
