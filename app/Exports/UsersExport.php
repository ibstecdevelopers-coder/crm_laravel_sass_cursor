<?php

namespace App\Exports;

use Horsefly\User;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
class UsersExport implements FromCollection, WithHeadings
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
                return User::select(
                        'id', 
                        'name', 
                        'email',
                        'is_active',
                        'created_at'
                    )
                    ->get()
                    ->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'name' => ucwords(strtolower($item->name)),
                            'email' => ucwords(strtolower($item->email)),
                            'status' => ucwords(strtolower($item->is_active ? 'Active' : 'Inactive')),
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
                return ['ID', 'User Name', 'Email', 'Status', 'Created At'];
            default:
                return [];
        }
    }
}
