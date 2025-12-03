<?php

namespace App\Exports;

use App\Models\UserServiceApplication;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ApplicationsExport implements FromArray, WithHeadings
{
    protected $applications;

    public function __construct($applications)
    {
        $this->applications = $applications;
    }

    public function headings(): array
    {
        return [
            'ID',
            'Application Number',
            'Business',
            'Email',
            'Mobile',
            'Amount',
            'Payment Time',
            'Expiry Date',
            'Status',
            'Method',
            'Comments'
        ];
    }

    public function array(): array
    {
        return $this->applications;
    }
}
