<?php

namespace App\Services\Ai;

class ServiceKnowledgeDocumentService
{
    public function __construct(
        private ServiceKnowledgeSnapshotService $snapshot_service
    ) {}

    public function build(int $service_id): ?array
    {
        $snapshot = $this->snapshot_service->build($service_id);

        if (!$snapshot) {
            return null;
        }

        $sections = [];

        $sections[] = $this->make_section(
            $snapshot,
            'overview',
            'Service Overview',
            $this->build_overview($snapshot)
        );

        $questionnaire = $this->build_questionnaire($snapshot);

        if ($questionnaire !== null) {
            $sections[] = $this->make_section(
                $snapshot,
                'questionnaire',
                'Application Form and Questionnaire',
                $questionnaire
            );
        }

        $documents = $this->build_documents($snapshot);

        if ($documents !== null) {
            $sections[] = $this->make_section(
                $snapshot,
                'documents',
                'Required and Optional Documents',
                $documents
            );
        }

        $fees = $this->build_fees($snapshot);

        if ($fees !== null) {
            $sections[] = $this->make_section(
                $snapshot,
                'fees',
                'Service Fees and Payment Rules',
                $fees
            );
        }

        $approval_flow = $this->build_approval_flow($snapshot);

        if ($approval_flow !== null) {
            $sections[] = $this->make_section(
                $snapshot,
                'approval_flow',
                'Approval Flow and Processing',
                $approval_flow
            );
        }

        $renewal = $this->build_renewal($snapshot);

        if ($renewal !== null) {
            $sections[] = $this->make_section(
                $snapshot,
                'renewal',
                'Renewal Rules',
                $renewal
            );
        }

        $certificate = $this->build_certificate($snapshot);

        if ($certificate !== null) {
            $sections[] = $this->make_section(
                $snapshot,
                'certificate',
                'Certificate and NOC Information',
                $certificate
            );
        }

        return [
            'service_id' => data_get($snapshot, 'service.id'),
            'service_name' => data_get($snapshot, 'service.name'),

            'department_id' => data_get(
                $snapshot,
                'service.department.id'
            ),

            'department_name' => data_get(
                $snapshot,
                'service.department.name'
            ),

            'source_updated_at' => data_get(
                $snapshot,
                'source_information.service_updated_at'
            ),

            'total_sections' => count($sections),
            'sections' => $sections,
        ];
    }

    private function make_section(
        array $snapshot,
        string $section_type,
        string $section_title,
        string $content
    ): array {
        $service_id = (int) data_get(
            $snapshot,
            'service.id'
        );

        $service_name = (string) data_get(
            $snapshot,
            'service.name',
            'Service'
        );

        $department_id = data_get(
            $snapshot,
            'service.department.id'
        );

        $department_name = data_get(
            $snapshot,
            'service.department.name'
        );

        $knowledge_key = "service:{$service_id}:{$section_type}";

        $content = trim($content);

        return [
            'knowledge_key' => $knowledge_key,
            'entity_type' => 'service',
            'entity_id' => $service_id,
            'service_id' => $service_id,
            'service_name' => $service_name,
            'department_id' => $department_id,
            'department_name' => $department_name,
            'section_type' => $section_type,
            'section_title' => $section_title,
            'title' => "{$service_name} - {$section_title}",
            'language' => 'en',
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'source_updated_at' => data_get(
                $snapshot,
                'source_information.service_updated_at'
            ),
        ];
    }

    private function build_overview(array $snapshot): string
    {
        $service = $snapshot['service'] ?? [];

        $lines = [];

        $lines[] = '# ' . ($service['name'] ?? 'Service');
        $lines[] = '';

        $lines[] = '## Service Information';
        $lines[] = '';

        $lines[] = '- Service name: ' .
            $this->display($service['name'] ?? null);

        $lines[] = '- Department: ' .
            $this->display(
                data_get($service, 'department.name')
            );

        $lines[] = '- Service type: ' .
            $this->display($service['service_type'] ?? null);

        $lines[] = '- Service mode: ' .
            $this->display($service['service_mode'] ?? null);

        if (!empty($service['third_party_portal_name'])) {
            $lines[] = '- Third-party portal: ' .
                $service['third_party_portal_name'];
        }

        if (!empty($service['target_days'])) {
            $lines[] = '- Configured target processing time: ' .
                $service['target_days'] . ' days';
        }

        $lines[] = '- CAF required: ' .
            $this->yes_no($service['caf_required'] ?? false);

        $lines[] = '- Application form available: ' .
            $this->yes_no($service['has_input_form'] ?? false);

        $lines[] = '- Repeat application allowed: ' .
            $this->yes_no(
                $service['allow_repeat_application'] ?? false
            );

        $lines[] = '- Deemed approval enabled: ' .
            $this->yes_no(
                $service['is_deemed_approval'] ?? false
            );

        $dependencies = $service['depends_on_services'] ?? null;

        if (!empty($dependencies)) {
            $lines[] = '- Dependent services: ' .
                $this->display($dependencies);
        }

        $lines[] = '';
        $lines[] = '## Important Rules';
        $lines[] = '';

        $lines[] = '- The configured target processing time is not a guaranteed approval date.';
        $lines[] = '- The user\'s actual application status must be checked from live application data.';
        $lines[] = '- The user\'s actual payment, certificate, expiry date and approval stage must not be inferred from this service guide.';

        return implode("\n", $lines);
    }

    private function build_questionnaire(
        array $snapshot
    ): ?string {
        $questions = data_get(
            $snapshot,
            'questionnaire.questions',
            []
        );

        if (!is_array($questions) || count($questions) === 0) {
            return null;
        }

        $document_question_ids = $this->document_question_ids(
            $snapshot
        );

        $form_questions = array_values(
            array_filter(
                $questions,
                function ($question) use ($document_question_ids) {
                    $question_id = (int) (
                        $question['id'] ?? 0
                    );

                    return !in_array(
                        $question_id,
                        $document_question_ids,
                        true
                    );
                }
            )
        );

        if (count($form_questions) === 0) {
            return null;
        }

        $service_name = data_get(
            $snapshot,
            'service.name',
            'this service'
        );

        $lines = [];

        $lines[] = "# {$service_name} - Application Form";
        $lines[] = '';
        $lines[] = 'The following fields are configured in the service application form.';
        $lines[] = '';

        foreach ($form_questions as $index => $question) {
            $label = $question['label']
                ?? 'Question ' . ($index + 1);

            $lines[] = '## ' . ($index + 1) . '. ' . $label;
            $lines[] = '';

            $lines[] = '- Field type: ' .
                $this->display(
                    $question['type'] ?? null
                );

            $lines[] = '- Required: ' .
                $this->yes_no(
                    $question['is_required'] ?? false
                );

            if (!empty($question['group_label'])) {
                $lines[] = '- Group: ' .
                    $question['group_label'];
            }

            if (!empty($question['section_name'])) {
                $lines[] = '- Section: ' .
                    $question['section_name'];
            }

            if (!empty($question['options'])) {
                $lines[] = '- Available options: ' .
                    $this->display(
                        $question['options']
                    );
            }

            if (
                array_key_exists('default_value', $question)
                && $question['default_value'] !== null
                && $question['default_value'] !== ''
            ) {
                $lines[] = '- Default value: ' .
                    $this->display(
                        $question['default_value']
                    );
            }

            if (!empty($question['condition_label'])) {
                $lines[] = '- Display condition: ' .
                    $this->display(
                        $question['condition_label']
                    );
            }

            if (!empty($question['display_rule'])) {
                $lines[] = '- Conditional display rule: ' .
                    $this->display(
                        $question['display_rule']
                    );
            }

            if (!empty($question['validation_rule'])) {
                $lines[] = '- Validation rule: ' .
                    $this->display(
                        $question['validation_rule']
                    );
            }

            if (!empty($question['sample_format'])) {
                $lines[] = '- Sample format is available.';
            }

            $lines[] = '';
        }

        $lines[] = '## AI Answer Rules';
        $lines[] = '';
        $lines[] = '- Do not invent a form field that is not present in this configuration.';
        $lines[] = '- Conditional fields should be explained as conditional, not mandatory for every applicant.';
        $lines[] = '- The user\'s submitted answer must be checked from live application data.';

        return implode("\n", $lines);
    }

    private function build_documents(
        array $snapshot
    ): ?string {
        $total_documents = (int) data_get(
            $snapshot,
            'documents.total_documents',
            0
        );

        if ($total_documents === 0) {
            return null;
        }

        $service_name = data_get(
            $snapshot,
            'service.name',
            'Service'
        );

        $required = data_get(
            $snapshot,
            'documents.required',
            []
        );

        $optional = data_get(
            $snapshot,
            'documents.optional',
            []
        );

        $conditional = data_get(
            $snapshot,
            'documents.conditional',
            []
        );

        $lines = [];

        $lines[] = "# {$service_name} - Documents";
        $lines[] = '';

        $this->append_document_group(
            $lines,
            'Required Documents',
            $required
        );

        $this->append_document_group(
            $lines,
            'Optional Documents',
            $optional
        );

        $this->append_document_group(
            $lines,
            'Conditional Documents',
            $conditional
        );

        $lines[] = '## AI Answer Rules';
        $lines[] = '';
        $lines[] = '- Required documents must be described as mandatory.';
        $lines[] = '- Optional documents must not be described as mandatory.';
        $lines[] = '- Conditional documents apply only when their configured condition is satisfied.';
        $lines[] = '- The user\'s actual uploaded or missing documents must be checked from live application data.';

        return implode("\n", $lines);
    }

    private function append_document_group(
        array &$lines,
        string $title,
        array $documents
    ): void {
        if (count($documents) === 0) {
            return;
        }

        $lines[] = "## {$title}";
        $lines[] = '';

        foreach ($documents as $index => $document) {
            $label = $document['label']
                ?? 'Document ' . ($index + 1);

            $lines[] = ($index + 1) . '. ' . $label;

            if (!empty($document['condition_label'])) {
                $lines[] = '   - Condition: ' .
                    $this->display(
                        $document['condition_label']
                    );
            }

            if (!empty($document['display_rule'])) {
                $lines[] = '   - Display rule: ' .
                    $this->display(
                        $document['display_rule']
                    );
            }

            if (!empty($document['sample_format'])) {
                $lines[] = '   - Sample format is available.';
            }
        }

        $lines[] = '';
    }

    private function build_fees(array $snapshot): ?string
    {
        $payment_type = data_get(
            $snapshot,
            'fees.payment_type'
        );

        $rules = data_get(
            $snapshot,
            'fees.rules',
            []
        );

        if (empty($payment_type) && count($rules) === 0) {
            return null;
        }

        $service_name = data_get(
            $snapshot,
            'service.name',
            'Service'
        );

        $lines = [];

        $lines[] = "# {$service_name} - Fees";
        $lines[] = '';
        $lines[] = '- Payment type: ' .
            $this->display($payment_type);
        $lines[] = '';

        if (count($rules) > 0) {
            $lines[] = '## Configured Fee Rules';
            $lines[] = '';

            foreach ($rules as $index => $rule) {
                $lines[] = '### Rule ' . ($index + 1);
                $lines[] = '';

                $this->append_fee_rule(
                    $lines,
                    $rule
                );

                $lines[] = '';
            }
        }

        $lines[] = '## Important Rules';
        $lines[] = '';
        $lines[] = '- These are the configured fee rules for the service.';
        $lines[] = '- The chatbot must not independently calculate the user\'s final payable amount from this text.';
        $lines[] = '- The actual fee must be calculated by the Laravel fee calculation process.';
        $lines[] = '- The actual paid amount and payment status must come from live payment data.';

        return implode("\n", $lines);
    }

    private function append_fee_rule(
        array &$lines,
        array $rule
    ): void {
        $lines[] = '- Fee type: ' .
            $this->display(
                $rule['fee_type'] ?? null
            );

        if (
            array_key_exists('fixed_fee', $rule)
            && $rule['fixed_fee'] !== null
            && $rule['fixed_fee'] !== ''
        ) {
            $lines[] = '- Fixed fee: ' .
                $rule['fixed_fee'];
        }

        if (
            array_key_exists('minimum_fee', $rule)
            && $rule['minimum_fee'] !== null
            && $rule['minimum_fee'] !== ''
        ) {
            $lines[] = '- Minimum fee: ' .
                $rule['minimum_fee'];
        }

        if (!empty(data_get($rule, 'question.label'))) {
            $lines[] = '- Based on form field: ' .
                data_get($rule, 'question.label');
        }

        if (
            !empty(
                data_get(
                    $rule,
                    'condition_question.label'
                )
            )
        ) {
            $lines[] = '- Condition field: ' .
                data_get(
                    $rule,
                    'condition_question.label'
                );
        }

        $condition_parts = array_filter([
            $rule['pre_condition_operator'] ?? null,
            $rule['pre_condition_value'] ?? null,
            $rule['pre_start_value'] ?? null,
            $rule['pre_end_value'] ?? null,
            $rule['condition_operator'] ?? null,
            $rule['condition_value_start'] ?? null,
            $rule['condition_value_end'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        if (count($condition_parts) > 0) {
            $lines[] = '- Configured condition: ' .
                implode(' ', $condition_parts);
        }

        if (
            array_key_exists('calculated_fee', $rule)
            && $rule['calculated_fee'] !== null
            && $rule['calculated_fee'] !== ''
        ) {
            $lines[] = '- Calculated fee value: ' .
                $rule['calculated_fee'];
        }

        if (
            array_key_exists('fixed_calculated_fee', $rule)
            && $rule['fixed_calculated_fee'] !== null
            && $rule['fixed_calculated_fee'] !== ''
        ) {
            $lines[] = '- Fixed calculated fee: ' .
                $rule['fixed_calculated_fee'];
        }

        if (
            array_key_exists('per_unit_fee', $rule)
            && $rule['per_unit_fee'] !== null
            && $rule['per_unit_fee'] !== ''
        ) {
            $lines[] = '- Per-unit fee: ' .
                $rule['per_unit_fee'];
        }

        $lines[] = '- Multiple conditions enabled: ' .
            $this->yes_no(
                $rule['multi_condition'] ?? false
            );
    }

    private function build_approval_flow(
        array $snapshot
    ): ?string {
        $steps = data_get(
            $snapshot,
            'approval_flow.steps',
            []
        );

        $target_days = data_get(
            $snapshot,
            'approval_flow.target_days'
        );

        if (count($steps) === 0 && empty($target_days)) {
            return null;
        }

        $service_name = data_get(
            $snapshot,
            'service.name',
            'Service'
        );

        $lines = [];

        $lines[] = "# {$service_name} - Approval Flow";
        $lines[] = '';

        if (!empty($target_days)) {
            $lines[] = '- Configured target processing time: ' .
                $target_days . ' days';
        }

        $lines[] = '- Deemed approval enabled: ' .
            $this->yes_no(
                data_get(
                    $snapshot,
                    'approval_flow.is_deemed_approval',
                    false
                )
            );

        $lines[] = '';

        if (count($steps) > 0) {
            $lines[] = '## Configured Approval Steps';
            $lines[] = '';

            foreach ($steps as $index => $step) {
                $step_number = $step['step_number']
                    ?? ($index + 1);

                $department = data_get(
                    $step,
                    'department.name',
                    'Department not configured'
                );

                $lines[] = "### Step {$step_number}";
                $lines[] = '';
                $lines[] = '- Department: ' . $department;
                $lines[] = '- Step type: ' .
                    $this->display(
                        $step['step_type'] ?? null
                    );

                $lines[] = '- Hierarchy level: ' .
                    $this->display(
                        $step['hierarchy_level'] ?? null
                    );

                $lines[] = '';
            }
        }

        $lines[] = '## Important Rules';
        $lines[] = '';
        $lines[] = '- This section describes the normal configured approval flow.';
        $lines[] = '- It does not prove that a user\'s application is currently at a particular step.';
        $lines[] = '- A workflow-step approval must not automatically be described as final application approval.';
        $lines[] = '- The actual current department, officer and waiting stage must come from live workflow data.';

        return implode("\n", $lines);
    }

    private function build_renewal(
        array $snapshot
    ): ?string {
        $cycles = data_get(
            $snapshot,
            'renewal.cycles',
            []
        );

        $auto_renewal = data_get(
            $snapshot,
            'renewal.auto_renewal',
            false
        );

        $unassigned_rules = data_get(
            $snapshot,
            'renewal.unassigned_fee_rules',
            []
        );

        if (
            count($cycles) === 0
            && count($unassigned_rules) === 0
            && !$auto_renewal
        ) {
            return null;
        }

        $service_name = data_get(
            $snapshot,
            'service.name',
            'Service'
        );

        $lines = [];

        $lines[] = "# {$service_name} - Renewal";
        $lines[] = '';
        $lines[] = '- Automatic renewal enabled: ' .
            $this->yes_no($auto_renewal);
        $lines[] = '';

        foreach ($cycles as $index => $cycle) {
            $title = $cycle['title']
                ?? 'Renewal Cycle ' . ($index + 1);

            $lines[] = "## {$title}";
            $lines[] = '';

            $lines[] = '- Renewal period: ' .
                $this->display(
                    $cycle['period'] ?? null
                );

            if (!empty($cycle['custom_period'])) {
                $lines[] = '- Custom renewal period: ' .
                    $cycle['custom_period'];
            }

            if (!empty($cycle['target_days'])) {
                $lines[] = '- Renewal target processing time: ' .
                    $cycle['target_days'] . ' days';
            }

            if (!empty($cycle['renewal_window_days'])) {
                $lines[] = '- Renewal window: ' .
                    $cycle['renewal_window_days'] . ' days';
            }

            if (!empty($cycle['before_expiry_days'])) {
                $lines[] = '- Renewal before expiry: ' .
                    $cycle['before_expiry_days'];
            }

            if (!empty($cycle['fixed_start_date'])) {
                $lines[] = '- Fixed renewal start date: ' .
                    $cycle['fixed_start_date'];
            }

            if (!empty($cycle['fixed_end_date'])) {
                $lines[] = '- Fixed renewal end date: ' .
                    $cycle['fixed_end_date'];
            }

            $lines[] = '- Renewal input form enabled: ' .
                $this->yes_no(
                    $cycle['allow_input_form'] ?? false
                );

            $lines[] = '- Late fee applicable: ' .
                $this->yes_no(
                    $cycle['late_fee_applicable'] ?? false
                );

            if (!empty($cycle['late_fee_start_type'])) {
                $lines[] = '- Late fee start type: ' .
                    $cycle['late_fee_start_type'];
            }

            if (!empty($cycle['late_fee_start_date'])) {
                $lines[] = '- Late fee start date: ' .
                    $cycle['late_fee_start_date'];
            }

            if (
                array_key_exists(
                    'late_fee_fixed_amount',
                    $cycle
                )
                && $cycle['late_fee_fixed_amount'] !== null
                && $cycle['late_fee_fixed_amount'] !== ''
            ) {
                $lines[] = '- Fixed late fee amount: ' .
                    $cycle['late_fee_fixed_amount'];
            }

            $fee_rules = $cycle['fee_rules'] ?? [];

            if (count($fee_rules) > 0) {
                $lines[] = '';
                $lines[] = '### Renewal Fee Rules';
                $lines[] = '';

                foreach ($fee_rules as $rule_index => $rule) {
                    $lines[] = '#### Rule ' .
                        ($rule_index + 1);

                    $this->append_fee_rule(
                        $lines,
                        $rule
                    );

                    $lines[] = '';
                }
            }

            $lines[] = '';
        }

        if (count($unassigned_rules) > 0) {
            $lines[] = '## General Renewal Fee Rules';
            $lines[] = '';

            foreach ($unassigned_rules as $index => $rule) {
                $lines[] = '### Rule ' . ($index + 1);

                $this->append_fee_rule(
                    $lines,
                    $rule
                );

                $lines[] = '';
            }
        }

        $lines[] = '## Important Rules';
        $lines[] = '';
        $lines[] = '- The user\'s actual certificate expiry date must come from live certificate data.';
        $lines[] = '- The user\'s renewal eligibility must be checked using the selected renewal cycle and live licence details.';
        $lines[] = '- The actual renewal fee must be calculated by Laravel.';
        $lines[] = '- Do not promise that renewal is available when no active renewal cycle exists.';

        return implode("\n", $lines);
    }

    private function build_certificate(
        array $snapshot
    ): ?string {
        $certificate = $snapshot['certificate'] ?? [];

        $has_information = array_filter(
            $certificate,
            function ($value) {
                if (is_array($value)) {
                    return count(
                        array_filter(
                            $value,
                            fn ($item) =>
                            $item !== null
                            && $item !== ''
                            && $item !== false
                        )
                    ) > 0;
                }

                return $value !== null
                    && $value !== ''
                    && $value !== false;
            }
        );

        if (count($has_information) === 0) {
            return null;
        }

        $service_name = data_get(
            $snapshot,
            'service.name',
            'Service'
        );

        $lines = [];

        $lines[] = "# {$service_name} - Certificate";
        $lines[] = '';

        $lines[] = '- Certificate/NOC name: ' .
            $this->display(
                $certificate['certificate_name'] ?? null
            );

        $lines[] = '- Short name: ' .
            $this->display(
                $certificate['certificate_short_name'] ?? null
            );

        $lines[] = '- Certificate type: ' .
            $this->display(
                $certificate['certificate_type'] ?? null
            );

        $lines[] = '- Certificate PDF generation enabled: ' .
            $this->yes_no(
                $certificate['generate_certificate_pdf'] ?? false
            );

        $lines[] = '- Certificate number generation enabled: ' .
            $this->yes_no(
                $certificate['generate_certificate_number'] ?? false
            );

        if (!empty($certificate['certificate_number_format'])) {
            $lines[] = '- Certificate number format: ' .
                $certificate['certificate_number_format'];
        }

        if (!empty($certificate['validity'])) {
            $lines[] = '- Configured validity: ' .
                $certificate['validity'];
        }

        if (!empty($certificate['fixed_expiry_date'])) {
            $lines[] = '- Fixed expiry date: ' .
                $certificate['fixed_expiry_date'];
        }

        $lines[] = '- Show valid-till date: ' .
            $this->yes_no(
                $certificate['show_valid_till'] ?? false
            );

        $lines[] = '- Eligible for existing-licence upload: ' .
            $this->yes_no(
                $certificate['valid_for_existing_license_upload']
                ?? false
            );

        $labels = $certificate['labels'] ?? [];

        if (count(array_filter($labels)) > 0) {
            $lines[] = '';
            $lines[] = '## Configured Certificate Labels';
            $lines[] = '';

            foreach ($labels as $key => $label) {
                if ($label === null || $label === '') {
                    continue;
                }

                $lines[] = '- ' .
                    str_replace('_', ' ', ucfirst($key)) .
                    ': ' .
                    $label;
            }
        }

        $lines[] = '';
        $lines[] = '## Important Rules';
        $lines[] = '';
        $lines[] = '- This configuration describes the type of certificate or NOC issued for the service.';
        $lines[] = '- The actual certificate number, issue date, download link and expiry date must come from live application data.';
        $lines[] = '- Do not describe a certificate as issued only because certificate generation is enabled.';
        $lines[] = '- Final application status must be checked before claiming that the service is approved.';

        return implode("\n", $lines);
    }

    private function document_question_ids(
        array $snapshot
    ): array {
        $groups = [
            data_get($snapshot, 'documents.required', []),
            data_get($snapshot, 'documents.optional', []),
            data_get($snapshot, 'documents.conditional', []),
        ];

        $ids = [];

        foreach ($groups as $documents) {
            foreach ($documents as $document) {
                if (!empty($document['question_id'])) {
                    $ids[] = (int) $document['question_id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function yes_no(mixed $value): string
    {
        return $value ? 'Yes' : 'No';
    }

    private function display(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Not configured';
        }

        if (is_bool($value)) {
            return $this->yes_no($value);
        }

        if (is_array($value)) {
            $flattened = [];

            array_walk_recursive(
                $value,
                function ($item) use (&$flattened) {
                    if (
                        $item !== null
                        && $item !== ''
                    ) {
                        $flattened[] = (string) $item;
                    }
                }
            );

            return count($flattened) > 0
                ? implode(', ', $flattened)
                : 'Not configured';
        }

        return trim((string) $value);
    }
}