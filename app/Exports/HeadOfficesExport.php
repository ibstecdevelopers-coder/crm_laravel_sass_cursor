<?php

namespace App\Exports;

use Horsefly\Office;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class HeadOfficesExport implements FromCollection, WithHeadings
{
    protected $type;

    public function __construct(string $type = 'all')
    {
        $this->type = $type;
    }

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        switch ($this->type) {
            case 'emails':
                return Office::select(
                        'offices.id', 
                        'offices.office_name', 
                        'offices.office_postcode',
                        'contacts.contact_email',
                        'offices.created_at'
                    )
                    ->leftJoin('contacts', 'offices.id', '=', 'contacts.contactable_id')
                    ->where('contacts.contactable_type', 'Horsefly\\Office')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'office_name' => ucwords(strtolower($item->office_name)),
                            'office_postcode' => strtoupper($item->office_postcode),
                            'contact_email' => $item->contact_email,
                            'created_at' => $item->created_at ? $item->created_at->format('d M Y, h:i A') : 'N/A',
                        ];
                    });
                
            case 'noLatLong':
                return Office::select(
                        'id',
                        'office_name',
                        'office_postcode',
                        'office_lat',
                        'office_lng',
                        'created_at'
                    )
                    ->whereIn('office_lng', ['0', '', null])
                    ->whereIn('office_lat', ['0', '', null])
                    ->get() // execute the query to get a collection
                    ->map(function ($item) {
                        return [
                            'id'              => $item->id,
                            'office_name'     => ucwords(strtolower($item->office_name)),
                            'office_postcode' => strtoupper($item->office_postcode),
                            'office_lat'      => $item->office_lat,
                            'office_lng'      => $item->office_lng,
                            'created_at'      => $item->created_at
                                                    ? $item->created_at->format('d M Y, h:i A')
                                                    : 'N/A',
                        ];
                    });
                
            case 'all':
                return Office::select(
                        'offices.id', 
                        'offices.office_name', 
                        'contacts.contact_name', 
                        'contacts.contact_email',
                        'contacts.contact_phone',
                        'contacts.contact_landline',
                        'offices.created_at'
                    )
                    ->leftJoin('contacts', 'offices.id', '=', 'contacts.contactable_id')
                    ->where('contacts.contactable_type', 'Horsefly\\Office')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'office_name' => ucwords(strtolower($item->office_name)),
                            'office_postcode' => strtoupper($item->office_postcode),
                            'contact_name' => ucwords(strtolower($item->contact_name)),
                            'contact_email' => $item->contact_email,
                            'contact_phone' => $item->contact_phone,
                            'contact_landline' => $item->contact_landline,
                            'created_at' => $item->created_at ? $item->created_at->format('d M Y, h:i A') : 'N/A',
                        ];
                    });
                
            default:
                return collect(); // Return empty collection instead of null
        }
    }

    public function headings(): array
    {
        switch ($this->type) {
            case 'emails':
                return ['ID', 'Head Office Name', 'Postcode', 'Contact Email', 'Created At'];
            case 'noLatLong':
                return ['ID', 'Head Office Name', 'Postcode', 'Latitude', 'Longitude', 'Created At'];
            case 'all':
                return ['ID', 'Head Office Name', 'Postcode', 'Contact Name', 'Contact Email', 'Contact Phone', 'Contact Landline', 'Created At'];
            default:
                return [];
        }
    }
}
