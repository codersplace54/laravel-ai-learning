<?php

namespace App\Exports;

use App\Models\DepartmentUser;
use App\Models\ServiceQuestionnaire;
use App\Models\ApplicationWorkflowAssignment;
use App\Models\UserServiceApplication;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Illuminate\Support\Facades\Auth;

class ServiceApplicationExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    public function collection()
    {
        $user = Auth::user();

        $hierarchy_level = $user->department_user->hierarchy_level;

        $application_ids = ApplicationWorkflowAssignment::where('status', 'pending')
            ->where('hierarchy_level', $hierarchy_level)
            ->pluck('application_id');
        
        return UserServiceApplication::with([
            'user',
            'service',
            'workflow.department',
            'workflow.actionTaker'
        ])
            ->whereIn('id', $application_ids)
            ->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'User Name',
            'Service',
            'Renewal Cycle Title',
            'Renewal',
            'Renewal Year',
            'Application Number',
            'Application Date',
            'Status',
            'Application Data',
            'Applied Fee',
            'Approved Fee',
            'Payment Status',
            'Remarks',
            'Noc Application Date',
            'Noc Expiry Date',
            'Previous Noc Expiry Date',
            'Payment Transaction ID',
            'GRN Number',
            'Payment Time',
            'Extra Payment',
            'Comments',
            'NOC Certificate Generated',
            'NOC Rejection Certificate',
            'NOC Generated Date',
            'NOC Penalty Amount',
            'NOC Letter Number',
            'NOC Letter Date',
            'NSW_Application_Save_ID',
            'NSW_license_status',
            'NSW_Push_Document_ID',
            'external_application_id',
            'external_status',
            'external_payment_status',
            'external_max_processing_date',
            'external_noc_number',
            'external_valid_till',
            'external_remarks',
            'is_third_party',
            'final_fee',
            'total_fee',
            'current_step_number',
            'max_processing_date',
            'created_at',
            'updated_at',
            'Workflow History metadata'
        ];
    }


    public function map($application): array
    {
        $application_data = json_decode($application->application_data, true) ?: [];

        $formatted_application_data = [];

        $question_ids = array_keys($application_data);

        $questions = ServiceQuestionnaire::whereIn('id', $question_ids)->get()->keyBy('id');
        
        foreach ($application_data as $question_id => $answer) {
            $question = $questions->get($question_id);
            if ($question) {
                $formatted_application_data[$question->question_label] = $answer;
            }
        }

        $workflow_history = $application->workflow->map(function ($workflow) {
            return [
                'id' => $workflow->id,
                'step_number' => $workflow->step_number,
                'step_type' => $workflow->step_type,
                'department' => $workflow->department->name ?? null,
                'hierarchy_level' => $workflow->hierarchy_level,
                'action_taken_by' => $workflow->actionTaker->authorized_person_name ?? null,
                'action_taken_at' => $workflow->action_taken_at,
                'status' => $workflow->status,
                'remarks' => $workflow->remarks,
                'external_status' => $workflow->external_status,
                'external_payment_amount' => $workflow->external_payment_amount,
                'external_payment_status' => $workflow->external_payment_status,
                'external_noc_url' => $workflow->external_noc_url,
                'source' => $workflow->source,
            ];
        });

        return [
            $application->id,
            $application->user->authorized_person_name ?? null,
            $application->service->service_title_or_description ?? null,
            $application->renewal_cycle->renewal_title ?? null,
            $application->renewal,
            $application->renewalYear,
            $application->applicationId,
            $application->application_date,
            $application->status,
            $formatted_application_data,
            $application->applied_fee,
            $application->approved_fee,
            $application->payment_status,
            $application->remarks,
            $application->NOC_application_date,
            $application->NOC_expiry_date,
            $application->PreviousNOCexpiryDate,
            $application->payment_transId,
            $application->GRN_number,
            $application->payment_time,
            $application->extra_payment,
            $application->comments,
            $application->NOC_certificate ? 'yes' : 'no',
            $application->NOC_rejection_certificate,
            $application->NOC_generationDate,
            $application->NOC_penalty_amount,
            $application->NOC_letter_number,
            $application->NOC_letter_date,
            $application->NSW_Application_Save_ID,
            $application->NSW_license_status,
            $application->NSW_Push_Document_ID,
            $application->external_application_id,
            $application->external_status,
            $application->external_payment_status,
            $application->external_max_processing_date,
            $application->external_noc_number,
            $application->external_valid_till,
            $application->external_remarks,
            $application->is_third_party,
            $application->final_fee,
            $application->total_fee,
            $application->current_step_number,
            $application->max_processing_date,
            $application->created_at,
            $application->updated_at,
            $workflow_history
        ];
    }
}
