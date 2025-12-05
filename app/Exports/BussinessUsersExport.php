<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class BussinessUsersExport implements FromCollection, WithHeadings
{
    protected $users;

    public function __construct($users)
    {
        $this->users = $users;
    }

    public function collection()
    {
        return $this->users->map(function ($user) {
            return [
                'ID' => $user->id,
                'Enterprise Name' => $user->name_of_enterprise,
                'Authorized Person' => $user->authorized_person_name,
                'Email' => $user->email_id,
                'Mobile' => $user->mobile_no,
                'PAN' => $user->pan,
                'Username' => $user->user_name,
                'BIN' => $user->bin,
                'District Code' => $user->district->district_code ?? null,
                'District Name' => $user->district->district_name ?? null,
                'Subdivision Code' => $user->subdivision->sub_lgd_code ?? null,
                'Subdivision Name' => $user->subdivision->sub_division ?? null,
                'ULB Code' => $user->ulb->ulb_lgd_code ?? null,
                'ULB Name' => $user->ulb->ulb_name ?? null,
                'Ward Code' => $user->ward->gp_vc_ward_lgd_code ?? null,
                'Ward Name' => $user->ward->name_of_gp_vc_or_ward ?? null,
                'Department Name' => $user->department_user->department->name ?? null,
                'Department ID' => $user->department_user->department_id ?? null,
                'Enterprise Address' => $user->registered_enterprise_address,
                'Enterprise City' => $user->registered_enterprise_city,
                'User Type' => $user->user_type,
                'Status' => $user->status,
                'Created At' => $user->created_at,
                'Updated At' => $user->updated_at,
                'Created By' => $user->department_user->created_by ?? null,
                'Updated By' => $user->department_user->updated_by ?? null,
            ];
        });
    }

    public function headings(): array
    {
        return [
            'ID', 'Enterprise Name', 'Authorized Person', 'Email', 'Mobile', 'PAN', 'Username', 'BIN',
            'District Code', 'District Name', 'Subdivision Code', 'Subdivision Name',
            'ULB Code', 'ULB Name', 'Ward Code', 'Ward Name',
            'Department Name', 'Department ID',
            'Enterprise Address', 'Enterprise City',
            'User Type', 'Hierarchy Level',
            'Status', 'Created At', 'Updated At', 'Created By', 'Updated By'
        ];
    }
}
