<?php

namespace App\Exports;

use App\Models\ServiceMaster;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class ServiceMasterExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    public function collection()
    {
        return ServiceMaster::with(['renewalCycles', 'service_fee_rule', 'questions', 'service_approval_flow', 'renewal_fee_rule'])->get();
    }

    public function headings(): array
    {
        return [
            'Service ID',
            'Service Name',
            'NOC Name',
            'NOC Short Name',
            'NOC type',
            'Allow Repeat Application',
            'Has Input Form',
            'Geneerated ID',
            'Generated PDF',
            'Show Valid Till',
            'External Data Share',
            'Valid For Upload',
            'status',
            'Created By',
            'Updated By',
            'Renewal Cycles',
            'Service Fee Rule',
            'Service Questionnaires',
            'Service Approval Flow',
            'Renewal Fee Rule',
        ];
    }

    public function map($service): array
    {

        $renewalCycles = $service->renewalCycles->map(function ($cycle) {
            return [
                'id'                          => $cycle->id,
                'renewal_title'               => $cycle->renewal_title,
                'renewal_period'              => $cycle->renewal_period,
                'renewal_period_custom'       => $cycle->renewal_period_custom,
                'renewal_target_days'         => $cycle->renewal_target_days,
                'renewal_window_days'         => $cycle->renewal_window_days,
                'fixed_renewal_start_date'    => $cycle->fixed_renewal_start_date,
                'fixed_renewal_end_date'      => $cycle->fixed_renewal_end_date,
                'late_fee_applicable'         => $cycle->late_fee_applicable,
                'late_fee_calculation_dynamic' => $cycle->late_fee_calculation_dynamic,
                'late_fee_fixed_amount'       => $cycle->late_fee_fixed_amount,
                'late_fee_calculated_amount'  => $cycle->late_fee_calculated_amount,
                'allow_renewal_input_form'    => $cycle->allow_renewal_input_form,
                'is_active'                   => $cycle->is_active,
                'created_at'                  => $cycle->created_at,
                'updated_at'                  => $cycle->updated_at,
                'created_by'                  => $cycle->created_by,
                'updated_by'                  => $cycle->updated_by,
            ];
        });

        $service_fee_rule = $service->service_fee_rule->map(function ($rule) {
            return [
                'id'                    => $rule->id,
                'renewal_cycle'      => $rule->renewalCycles->renewal_title ?? null,
                'fee_type'              => $rule->fee_type,
                'fixed_fee'             => $rule->fixed_fee,
                'question'           => $rule->questions->question_label ?? null,
                'condition_operator'    => $rule->condition_operator,
                'condition_value_start' => $rule->condition_value_start,
                'condition_value_end'   => $rule->condition_value_end,
                'calculated_fee'        => $rule->calculated_fee,
                'fixed_calculated_fee'  => $rule->fixed_calculated_fee,
                'per_unit_fee'          => $rule->per_unit_fee,
                'priority'              => $rule->priority,
                'status'                => $rule->status,
                'created_at'            => $rule->created_at,
                'updated_at'            => $rule->updated_at,
                'created_by'            => $rule->created_by,
                'updated_by'            => $rule->updated_by,
            ];
        });


        $questions = $service->questions->map(function ($q) {
            return [
                'id'                   => $q->id,
                'question_label'       => $q->question_label,
                'question_type'        => $q->question_type,
                'is_required'          => $q->is_required,
                'options'              => $q->options,
                'default_value'        => $q->default_value,
                'default_source_table' => $q->default_source_table,
                'default_source_column' => $q->default_source_column,
                'display_order'        => $q->display_order,
                'group_label'          => $q->group_label,
                'display_width'        => $q->display_width,
                'status'               => $q->status,
                'validation_required'  => $q->validation_required,
                'validation_rule'      => $q->validation_rule,
                'created_at'           => $q->created_at,
                'updated_at'           => $q->updated_at,
                'created_by'           => $q->created_by,
                'updated_by'           => $q->updated_by,
                'sample_format'        => $q->sample_format,
                'is_section'           => $q->is_section,
                'section_name'         => $q->section_name,
            ];
        });

        $service_approval_flow = $service->service_approval_flow->map(function ($flow) {
            return [
                'id'             => $flow->id,
                'step_number'    => $flow->step_number,
                'step_type'      => $flow->step_type,
                'department'      => $flow->department->name ?? null,
                'hierarchy_level' => $flow->hierarchy_level,
                'created_at'     => $flow->created_at,
                'updated_at'     => $flow->updated_at,
                'created_by'     => $flow->created_by,
                'updated_by'     => $flow->updated_by,
            ];
        });

        $renewal_fee_rule = $service->renewal_fee_rule->map(function ($rule) {
            return [
                'id'                   => $rule->id,
                'renewal_cycle'        => $rule->renewalCycles->renewal_title ?? null,
                'fee_type'             => $rule->fee_type,
                'fixed_fee'            => $rule->fixed_fee,
                'question'             => $rule->questions->question_label ?? null,
                'condition_operator'   => $rule->condition_operator,
                'condition_value_start' => $rule->condition_value_start,
                'condition_value_end'  => $rule->condition_value_end,
                'calculated_fee'       => $rule->calculated_fee,
                'fixed_calculated_fee' => $rule->fixed_calculated_fee,
                'per_unit_fee'         => $rule->per_unit_fee,
                'priority'             => $rule->priority,
                'status'               => $rule->status,
                'created_at'           => $rule->created_at,
                'updated_at'           => $rule->updated_at,
                'created_by'           => $rule->created_by,
                'updated_by'           => $rule->updated_by,
            ];
        });


        return [
            $service->id,
            $service->service_title_or_description,
            $service->noc_name,
            $service->noc_short_name,
            $service->noc_type,
            $service->allow_repeat_application,
            $service->has_input_form,
            $service->generate_id,
            $service->generate_pdf,
            $service->show_valid_till,
            $service->external_data_share,
            $service->valid_for_upload,
            $service->status,
            $service->created_by,
            $service->updated_by,
            json_encode($renewalCycles, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            json_encode($service_fee_rule, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            json_encode($service_approval_flow, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            json_encode($renewal_fee_rule, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ];
    }
}
