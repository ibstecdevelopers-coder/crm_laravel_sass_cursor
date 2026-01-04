<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;

class EmailExport implements FromCollection
{
    protected $emails;

    public function __construct(array $emails)
    {
        $this->emails = $emails;
    }

    public function collection()
    {
        // Return a collection of arrays, each array is a row in CSV
        return collect($this->emails)->map(function ($email) {
            return ['Email' => trim($email)];
        });
    }
}
