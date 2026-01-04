<?php

namespace App\Exports;

use Horsefly\IpAddress;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class IPAddressExport implements FromCollection, WithHeadings
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
            case 'all':
                return IpAddress::select(
                        'ip_addresses.id', 
                        'users.name', 
                        'ip_addresses.ip_address',
                        'ip_addresses.mac_address',
                        'ip_addresses.device_type',
                        'ip_addresses.status',
                        'ip_addresses.created_at'
                    )
                    ->join('users', 'ip_addresses.user_id', '=', 'users.id')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'name' => ucwords(strtolower($item->name)),
                            'ip_address' => $item->ip_address,
                            'mac_address' => $item->mac_address,
                            'device_type' => $item->device_type,
                            'status' => ucwords(strtolower($item->status ? 'Active' : 'Inactive')),
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
            case 'all':
                return ['ID', 'User Name', 'IP Address', 'MAC Address', 'Device Type', 'Status', 'Created At'];
            default:
                return [];
        }
    }
}
