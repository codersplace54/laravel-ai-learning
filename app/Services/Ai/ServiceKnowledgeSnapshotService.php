<?php

namespace App\Services\Ai;

use App\Models\RenewalCycle;
use App\Models\RenewalFeeRule;
use App\Models\ServiceApprovalFlow;
use App\Models\ServiceFeeRule;
use App\Models\ServiceMaster;
use App\Models\ServiceQuestionnaire;

class ServiceKnowledgeSnapshotService
{
    private array $document_question_types = [
        'file',
        'upload',
        'document',
        'attachment',
        'image',
        'pdf',
    ];

    public function build(int $service_id): ?array
    {
        $service = ServiceMaster::with([
            'department:id,name',
        ])->find($service_id);

        if (!$service) {
            return null;
        }

        $questions = ServiceQuestionnaire::where('service_id', $service_id)
            ->where('status', 1)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        $service_fee_rules = ServiceFeeRule::with([
            'question:id,question_label',
            'conditionQuestion:id,question_label',
        ])
            ->where('service_id', $service_id)
            ->where('status', 1)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $approval_flow = ServiceApprovalFlow::with([
            'department:id,name',
        ])
            ->where('service_id', $service_id)
            ->orderBy('step_number')
            ->orderBy('id')
            ->get();

        $renewal_cycles = RenewalCycle::where('service_id', $service_id)
            ->where('is_active', 1)
            ->orderBy('id')
            ->get();

        $renewal_fee_rules = RenewalFeeRule::with([
            'question:id,question_label',
            'conditionQuestion:id,question_label',
        ])
            ->where('service_id', $service_id)
            ->where('status', 1)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $questionnaire = $questions
            ->map(fn ($question) => $this->format_question($question))
            ->values()
            ->toArray();

        $documents = $questions
            ->filter(function ($question) {
                return in_array(
                    strtolower((string) $question->question_type),
                    $this->document_question_types,
                    true
                );
            })
            ->map(fn ($question) => $this->format_document($question))
            ->values()
            ->toArray();

        $renewal_cycles_data = $renewal_cycles
            ->map(function ($cycle) use ($renewal_fee_rules) {
                $cycle_fee_rules = $renewal_fee_rules
                    ->where('renewal_cycle_id', $cycle->id)
                    ->map(fn ($rule) => $this->format_fee_rule($rule))
                    ->values()
                    ->toArray();

                return [
                    'id' => $cycle->id,
                    'title' => $cycle->renewal_title,
                    'period' => $cycle->renewal_period,
                    'custom_period' => $cycle->renewal_period_custom,
                    'target_days' => $cycle->renewal_target_days,
                    'renewal_window_days' => $cycle->renewal_window_days,

                    'fixed_start_date' => $this->date_value(
                        $cycle->fixed_renewal_start_date
                    ),

                    'fixed_end_date' => $this->date_value(
                        $cycle->fixed_renewal_end_date
                    ),

                    'before_expiry_days' => $cycle->before_date_of_expiry,

                    'late_fee_applicable' => $this->is_enabled(
                        $cycle->late_fee_applicable
                    ),

                    'late_fee_calculation_dynamic' => $this->is_enabled(
                        $cycle->late_fee_calculation_dynamic
                    ),

                    'late_fee_fixed_amount' => $cycle->late_fee_fixed_amount,
                    'late_fee_calculated_amount' => $cycle->late_fee_calculated_amount,
                    'late_fee_start_type' => $cycle->late_fee_start_type,

                    'late_fee_start_date' => $this->date_value(
                        $cycle->late_fee_start_date
                    ),

                    'allow_input_form' => $this->is_enabled(
                        $cycle->allow_renewal_input_form
                    ),

                    'fee_rules' => $cycle_fee_rules,
                ];
            })
            ->values()
            ->toArray();

        $unassigned_renewal_fee_rules = $renewal_fee_rules
            ->whereNull('renewal_cycle_id')
            ->map(fn ($rule) => $this->format_fee_rule($rule))
            ->values()
            ->toArray();

        return [
            'knowledge_key' => "service:{$service->id}",

            'service' => [
                'id' => $service->id,
                'name' => $service->service_title_or_description,

                'department' => [
                    'id' => $service->department_id,
                    'name' => $service->department->name ?? null,
                ],

                'service_type' => $service->noc_type,
                'service_mode' => $service->service_mode,
                'third_party_portal_name' => $service->third_party_portal_name,
                'third_party_payment_mode' => $service->third_party_payment_mode,

                'target_days' => $service->target_days,

                'is_deemed_approval' => $this->is_enabled(
                    $service->is_deemed_approval
                ),

                'allow_repeat_application' => $this->is_enabled(
                    $service->allow_repeat_application
                ),

                'has_input_form' => $this->is_enabled(
                    $service->has_input_form
                ),

                'caf_required' => $this->is_enabled(
                    $service->caf_depends
                ),

                'depends_on_services' => $this->decode_value(
                    $service->depends_on_services
                ),

                'is_active' => $this->is_enabled(
                    $service->is_active
                ),

                'status' => $service->status,
            ],

            'questionnaire' => [
                'total_questions' => count($questionnaire),
                'questions' => $questionnaire,
            ],

            'documents' => [
                'total_documents' => count($documents),

                'required' => array_values(array_filter(
                    $documents,
                    fn ($document) =>
                    $document['requirement_type'] === 'required'
                )),

                'optional' => array_values(array_filter(
                    $documents,
                    fn ($document) =>
                    $document['requirement_type'] === 'optional'
                )),

                'conditional' => array_values(array_filter(
                    $documents,
                    fn ($document) =>
                    $document['requirement_type'] === 'conditional'
                )),
            ],

            'fees' => [
                'payment_type' => $service->noc_payment_type,

                'rules' => $service_fee_rules
                    ->map(fn ($rule) => $this->format_fee_rule($rule))
                    ->values()
                    ->toArray(),
            ],

            'approval_flow' => [
                'target_days' => $service->target_days,

                'is_deemed_approval' => $this->is_enabled(
                    $service->is_deemed_approval
                ),

                'steps' => $approval_flow
                    ->map(function ($flow) {
                        return [
                            'id' => $flow->id,
                            'step_number' => $flow->step_number,
                            'step_type' => $flow->step_type,

                            'department' => [
                                'id' => $flow->department_id,
                                'name' => $flow->department->name ?? null,
                            ],

                            'hierarchy_level' => $flow->hierarchy_level,
                        ];
                    })
                    ->values()
                    ->toArray(),
            ],

            'renewal' => [
                'auto_renewal' => $this->is_enabled(
                    $service->auto_renewal
                ),

                'cycles' => $renewal_cycles_data,

                'unassigned_fee_rules' => $unassigned_renewal_fee_rules,
            ],

            'certificate' => [
                'certificate_name' => $service->noc_name,
                'certificate_short_name' => $service->noc_short_name,
                'certificate_type' => $service->noc_type,

                'generate_certificate_pdf' => $this->is_enabled(
                    $service->generate_pdf
                ),

                'generate_certificate_number' => $this->is_enabled(
                    $service->generate_id
                ),

                'certificate_number_format' => $service->generated_id_format,
                'validity' => $service->noc_validity,

                'fixed_expiry_date' => $this->date_value(
                    $service->fixed_expiry_date
                ),

                'show_valid_till' => $this->is_enabled(
                    $service->show_valid_till
                ),

                'valid_for_existing_license_upload' => $this->is_enabled(
                    $service->valid_for_upload
                ),

                'labels' => [
                    'certificate_date' => $service->label_noc_date,
                    'certificate_document' => $service->label_noc_doc,
                    'certificate_number' => $service->label_noc_no,
                    'valid_till' => $service->label_valid_till,
                ],
            ],

            'source_information' => [
                'service_updated_at' => $this->date_value(
                    $service->updated_at
                ),

                'question_count' => $questions->count(),
                'document_count' => count($documents),
                'fee_rule_count' => $service_fee_rules->count(),
                'approval_step_count' => $approval_flow->count(),
                'renewal_cycle_count' => $renewal_cycles->count(),
                'renewal_fee_rule_count' => $renewal_fee_rules->count(),
            ],
        ];
    }

    private function format_question(
        ServiceQuestionnaire $question
    ): array {
        return [
            'id' => $question->id,
            'label' => trim((string) $question->question_label),
            'type' => $question->question_type,

            'is_required' => $this->is_enabled(
                $question->is_required
            ),

            'options' => $this->decode_value(
                $question->options
            ),

            'default_value' => $question->default_value,
            'display_order' => $question->display_order,
            'group_label' => $question->group_label,

            'is_section' => $this->is_enabled(
                $question->is_section
            ),

            'section_name' => $question->section_name,
            'condition_label' => $question->condition_label,

            'display_rule' => $this->decode_value(
                $question->display_rule
            ),

            'validation_required' => $this->is_enabled(
                $question->validation_required
            ),

            'validation_rule' => $this->decode_value(
                $question->validation_rule
            ),

            'approved_services' => $this->decode_value(
                $question->approved_services
            ),

            'sample_format' => $question->sample_format,
        ];
    }

    private function format_document(
        ServiceQuestionnaire $question
    ): array {
        $display_rule = $this->decode_value(
            $question->display_rule
        );

        $has_condition =
            !empty($question->condition_label)
            || !empty($display_rule);

        $requirement_type = match (true) {
            $has_condition => 'conditional',

            $this->is_enabled($question->is_required) =>
            'required',

            default =>
            'optional',
        };

        return [
            'question_id' => $question->id,
            'label' => trim((string) $question->question_label),
            'question_type' => $question->question_type,
            'requirement_type' => $requirement_type,
            'condition_label' => $question->condition_label,
            'display_rule' => $display_rule,
            'sample_format' => $question->sample_format,
            'display_order' => $question->display_order,
            'group_label' => $question->group_label,
        ];
    }

    private function format_fee_rule($rule): array
    {
        return [
            'id' => $rule->id,
            'renewal_cycle_id' => $rule->renewal_cycle_id,
            'fee_type' => $rule->fee_type,
            'fixed_fee' => $rule->fixed_fee,
            'minimum_fee' => $rule->minimum_fee,
            'priority' => $rule->priority,

            'question' => [
                'id' => $rule->question_id,
                'label' => $rule->question->question_label ?? null,
            ],

            'condition_question' => [
                'id' => $rule->condition_label_question_id,
                'label' => $rule->conditionQuestion->question_label ?? null,
            ],

            'pre_condition_operator' => $rule->pre_condition_operator,
            'pre_condition_value' => $rule->pre_condition_value,
            'pre_start_value' => $rule->pre_start_value,
            'pre_end_value' => $rule->pre_end_value,

            'condition_operator' => $rule->condition_operator,
            'condition_value_start' => $rule->condition_value_start,
            'condition_value_end' => $rule->condition_value_end,

            'calculated_fee' => $rule->calculated_fee,
            'fixed_calculated_fee' => $rule->fixed_calculated_fee,
            'per_unit_fee' => $rule->per_unit_fee,

            'multi_condition' => $this->is_enabled(
                $rule->multi_condition
            ),
        ];
    }

    private function decode_value(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $value = trim($value);

        if ($value === '' || strtolower($value) === 'null') {
            return null;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $value;
    }

    private function is_enabled(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(
            strtolower(trim((string) $value)),
            ['1', 'yes', 'true', 'active'],
            true
        );
    }

    private function date_value(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }
}