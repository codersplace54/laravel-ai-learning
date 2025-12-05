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

    public function array(): array
    {
        return $this->applications->map(function ($app) {

            $amount = !empty($app->effective_fee)
                ? $app->effective_fee
                : ($app->total_fee ?? 0);

            return [
                'ID'                 => $app->id,
                'Application Number' => $app->applicationId,
                'Business'           => $app->user->name_of_enterprise ?? null,
                'Email'              => $app->user->email_id ?? null,
                'Mobile'             => $app->user->mobile_no ?? null,
                'Amount'             => $amount,
                'Payment Time'       => $app->payment_time ?? null,
                'Expiry Date'        => $app->NOC_expiry_date ?? null,
                'Status'             => $app->payment_status,
                'GRN_number'         => $app->GRN_number ?? null,
                'Comments'           => $app->comments,
            ];
        })->toArray();
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
            'GRN_number',
            'Method',
            'Comments'
        ];
    }
}
