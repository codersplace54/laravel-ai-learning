<?php

namespace App\Services\Ai;

use App\Models\UserServiceApplication;
use App\Models\ServiceMaster;
use App\Models\ServiceQuestionnaire;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChatLiveDataService
{
    public function fetch_application_context(int $application_id, int $user_id): ?array
    {
        $application = UserServiceApplication::with([
            'service:id,service_title_or_description,noc_validity,fixed_expiry_date,department_id',
            'service.renewalCycles',
            'service.department:id,name',
        ])
            ->where('id', $application_id)
            ->where('user_id', $user_id)
            ->select([
                'id',
                'applicationId',
                'user_id',
                'service_id',
                'status',
                'payment_status',
                'total_fee',
                'final_fee',
                'effective_fee',
                'paid_amount',
                'GRN_number',
                'created_at',
                'updated_at',
                'application_date',
                'NOC_certificate',
                'NOC_rejection_certificate',
                'NOC_generationDate',
                'NOC_expiry_date',
                'renewal_cycle_id',
                'renewal',
                'renewalYear',
                'PreviousNOCexpiryDate',
                'external_valid_till',
                'external_noc_number',
                'is_third_party',
                'previous_application_id',
            ])
            ->first();

        if (!$application) {
            return null;
        }

        $latest_assignment = DB::table('application_workflow_assignments as awa')
            ->leftJoin('departments as d', 'd.id', '=', 'awa.department_id')
            ->where('awa.application_id', $application->id)
            ->orderByDesc('awa.id')
            ->select([
                'awa.id',
                'awa.step_number',
                'awa.step_type',
                'awa.department_id',
                'd.name as department_name',
                'awa.hierarchy_level',
                'awa.status',
                'awa.action_taken_by',
                'awa.action_taken_at',
                'awa.remarks',
                'awa.created_at',
                'awa.updated_at',
            ])
            ->first();

        $recent_assignments = DB::table('application_workflow_assignments as awa')
            ->leftJoin('departments as d', 'd.id', '=', 'awa.department_id')
            ->where('awa.application_id', $application->id)
            ->orderByDesc('awa.id')
            ->limit(8)
            ->select([
                'awa.id',
                'awa.step_number',
                'awa.step_type',
                'awa.department_id',
                'd.name as department_name',
                'awa.hierarchy_level',
                'awa.status',
                'awa.action_taken_by',
                'awa.action_taken_at',
                'awa.remarks',
                'awa.created_at',
                'awa.updated_at',
            ])
            ->get();

        $approval_flow = DB::table('service_approval_flows')
            ->where('service_id', $application->service_id)
            ->orderBy('step_number')
            ->get();

        $latest_payment = DB::table('payment_orders')
            ->whereJsonContains('application_id', $application->id)
            ->orderByDesc('id')
            ->first();

        $latest_send_back = DB::table('application_workflow_assignments as awa')
            ->leftJoin('departments as d', 'd.id', '=', 'awa.department_id')
            ->where('awa.application_id', $application->id)
            ->where('awa.status', 'send_back')
            ->orderByDesc('awa.action_taken_at')
            ->orderByDesc('awa.id')
            ->select([
                'awa.id',
                'awa.step_number',
                'awa.step_type',
                'awa.department_id',
                'd.name as department_name',
                'awa.status',
                'awa.action_taken_by',
                'awa.action_taken_at',
                'awa.remarks',
            ])
            ->first();

        $approved_at = DB::table('application_workflow_assignments')
            ->where('application_id', $application->id)
            ->where('status', 'approved')
            ->whereNotNull('action_taken_at')
            ->orderByDesc('action_taken_at')
            ->value('action_taken_at');

        $total_fee     = (float) ($application->total_fee ?? 0);
        $effective_fee = (float) ($application->effective_fee ?? 0);
        $paid_amount   = (float) ($application->paid_amount ?? 0);
        $payment_amount = $latest_payment ? (float) ($latest_payment->payment_amount ?? 0) : 0;
        $is_zero_fee   = $total_fee <= 0 && $effective_fee <= 0 && $paid_amount <= 0;

        $amount_to_pay = null;
        if (!$is_zero_fee && $application->payment_status === 'pending') {
            $amount_to_pay = $effective_fee ?: ($payment_amount ?: (max($total_fee - $paid_amount, 0) ?: null));
        }

        $waiting_on = $this->compute_waiting_on($application, $latest_assignment, $approval_flow);

        $timeline = [['type' => 'application_created', 'title' => 'Application created', 'date' => optional($application->created_at)->toDateTimeString()]];
        foreach ($recent_assignments->reverse()->values() as $a) {
            $timeline[] = [
                'type'            => 'assignment',
                'step_number'     => $a->step_number ?? null,
                'step_type'       => $a->step_type ?? null,
                'department_name' => $a->department_name ?? null,
                'status'          => $a->status ?? null,
                'remarks'         => $a->remarks ?? null,
                'action_taken_at' => $a->action_taken_at ?? null,
                'created_at'      => $a->created_at ?? null,
            ];
        }

        $payment_status = strtolower((string) $application->payment_status);
        $latest_payment_status = strtolower((string) ($latest_payment->payment_status ?? ''));

        $current_payment_state = match (true) {
            $payment_status === 'paid' =>
            'payment_completed',

            $payment_status === 'failed' =>
            'payment_failed',

            $payment_status === 'pending' && $latest_payment_status === 'paid' =>
            'payment_success_but_application_not_updated',

            default =>
            'payment_pending',
        };

        // Never show payable amount after payment is completed.
        if ($payment_status === 'paid') {
            $amount_to_pay = null;
        }

        return [
            'application' => [
                'id'                 => $application->id,
                'application_number' => $application->applicationId,
                'service_id'         => $application->service_id,
                'service_name'       => $application->service->service_title_or_description ?? null,
                'status'             => $application->status,
                'payment_status'     => $application->payment_status,
                'created_at'         => optional($application->created_at)->toDateTimeString(),
                'application_date'   => optional($application->application_date)->toDateTimeString(),
                'approved_at'        => $approved_at,
                'updated_at'         => optional($application->updated_at)->toDateTimeString(),
            ],
            'waiting_on'          => $waiting_on,
            'latest_assignment'   => $latest_assignment,
            'recent_assignments'  => $recent_assignments,
            'send_back_context'   => $latest_send_back ? [
                'was_sent_back'   => true,
                'remarks'         => $latest_send_back->remarks,
                'department_name' => $latest_send_back->department_name ?? null,
                'step_number'     => $latest_send_back->step_number ?? null,
                'sent_back_at'    => $latest_send_back->action_taken_at ?? null,
            ] : ['was_sent_back' => false, 'remarks' => null],
            'payment_context' => [
                'payment_status' => $payment_status,
                'current_state'  => $current_payment_state,

                // Keep both temporarily if old code still uses is_zero_fee.
                'is_zero_fee'             => $is_zero_fee,
                'is_zero_fee_application' => $is_zero_fee,

                'total_fee'     => $total_fee,
                'effective_fee' => $effective_fee,

                'paid_amount' => $paid_amount,
                'paid_amount_display' => $paid_amount > 0
                    ? '₹' . rtrim(rtrim(number_format($paid_amount, 2, '.', ''), '0'), '.')
                    : null,

                'amount_to_pay' => $amount_to_pay,
                'amount_to_pay_display' => $amount_to_pay !== null
                    ? '₹' . rtrim(rtrim(number_format($amount_to_pay, 2, '.', ''), '0'), '.')
                    : null,

                'grn_number'            => $application->GRN_number,
                'latest_payment_status' => $latest_payment->payment_status ?? null,
                'latest_payment_amount' => $payment_amount,
            ],
            'certificate_context' => [
                'certificate_available' => !empty($application->NOC_certificate),
                'noc_generation_date'   => $application->NOC_generationDate ?? null,
                'noc_expiry_date'       => $application->NOC_expiry_date ?? null,
                'external_valid_till'   => $application->external_valid_till ?? null,
                'external_noc_number'   => $application->external_noc_number ?? null,
                'noc_mode'              => $application->NOC_mode ?? null,
                'license_id'            => $application->license_id ?? null,
            ],
            'renewal_context' => [
                'renewal'                  => $application->renewal,
                'renewal_year'             => $application->renewalYear ?? null,
                'previous_application_id'  => $application->previous_application_id ?? null,
                'noc_expiry_date'          => $application->NOC_expiry_date ?? null,
                'previous_noc_expiry_date' => $application->PreviousNOCexpiryDate ?? null,
            ],
            'timeline' => $timeline,
        ];
    }

    public function fetch_service_document_context(int $service_id): ?array
    {
        $service = ServiceMaster::find($service_id, ['id', 'service_title_or_description']);

        if (!$service) {
            return null;
        }

        $file_types = ['file', 'upload', 'document', 'attachment', 'image', 'pdf'];

        $questions = ServiceQuestionnaire::where('service_id', $service_id)
            ->where('status', 1)
            ->whereIn('question_type', $file_types)
            ->orderBy('display_order')
            ->get(['id', 'question_label', 'question_type', 'is_required', 'display_rule', 'condition_label']);

        $required = [];
        $optional = [];
        $conditional = [];

        foreach ($questions as $q) {
            $doc = ['label' => trim($q->question_label)];
            $has_rule = !empty($q->display_rule) && $q->display_rule !== 'null';
            $has_condition = !empty($q->condition_label) && $q->condition_label !== 'null';

            if ($has_rule || $has_condition) {
                $conditional[] = $doc;
            } elseif ($q->is_required === 'yes' || $q->is_required == 1 || $q->is_required === true) {
                $required[] = $doc;
            } else {
                $optional[] = $doc;
            }
        }

        return [
            'service_id'             => $service_id,
            'service_name'           => $service->service_title_or_description,
            'required_documents'     => $required,
            'optional_documents'     => $optional,
            'conditional_documents'  => $conditional,
            'source'                 => 'live_db',
        ];
    }

    public function fetch_user_applications(int $user_id, ?string $filter_status = null): array
    {
        $query = UserServiceApplication::with(['service:id,service_title_or_description'])
            ->where('user_id', $user_id)
            ->latest('id')
            ->limit(100);

        $applications = $query->get(['id', 'applicationId', 'service_id', 'status', 'payment_status', 'created_at']);

        if ($filter_status) {
            $applications = $applications->filter(fn($a) => $this->matches_filter($a->status, $filter_status))->values();
        }

        return $applications->take(15)->map(fn($a) => [
            'id'           => $a->id,
            'title'        => $a->applicationId ?: ('Application #' . $a->id),
            'subtitle'     => ($a->service->service_title_or_description ?? 'Service') . ' — ' . ($a->status ?? ''),
            'status'       => $a->status,
            'service_name' => $a->service->service_title_or_description ?? null,
        ])->values()->toArray();
    }

    public function fetch_account_context(int $user_id): ?array
    {
        $user = User::find($user_id, ['id', 'name_of_enterprise', 'user_name', 'email_id', 'mobile_no', 'status', 'created_at']);

        if (!$user) {
            return null;
        }

        return [
            'id'         => $user->id,
            'name'       => $user->name ?? null,
            'username'   => $user->user_name ?? null,
            'email'      => $user->email ?? null,
            'mobile'     => $user->mobile_no ?? null,
            'status'     => $user->status ?? null,
            'created_at' => optional($user->created_at)->toDateTimeString(),
        ];
    }

    public function resolve_application_by_number(string $number, int $user_id): ?UserServiceApplication
    {
        $normalized = strtoupper(str_replace([' ', '_'], ['', '-'], $number));

        return UserServiceApplication::where('user_id', $user_id)
            ->where(function ($q) use ($normalized) {
                $q->whereRaw("REPLACE(UPPER(`applicationId`), ' ', '') = ?", [$normalized])
                    ->orWhereRaw("UPPER(`applicationId`) LIKE ?", ['%' . $normalized . '%']);
            })
            ->latest('id')
            ->first();
    }

    public function resolve_service_by_name(string $query): array
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9 ]/i', ' ', $query));
        $tokens = array_filter(explode(' ', $normalized), fn($t) => strlen($t) >= 3);

        if (empty($tokens)) {
            return ['status' => 'not_found'];
        }

        $services = ServiceMaster::get(['id', 'service_title_or_description']);

        $scored = $services->map(function ($s) use ($normalized, $tokens) {
            $title = strtolower(preg_replace('/[^a-z0-9 ]/i', ' ', $s->service_title_or_description));
            $score = 0;

            if (str_contains($title, $normalized)) {
                $score += 120;
            }

            $matched = 0;
            foreach ($tokens as $token) {
                if (str_contains($title, $token)) {
                    $score += 40;
                    $matched++;
                } elseif (levenshtein($token, $title) <= max(1, (int) floor(strlen($token) * 0.35))) {
                    $score += 20;
                    $matched++;
                }
            }

            if ($matched === count($tokens)) {
                $score += 60;
            }

            return ['id' => $s->id, 'title' => $s->service_title_or_description, 'score' => $score];
        })
            ->filter(fn($i) => $i['score'] >= 40)
            ->sortByDesc('score')
            ->values();

        if ($scored->isEmpty()) {
            return ['status' => 'not_found'];
        }

        $top    = $scored->first();
        $second = $scored->get(1);

        if (!$second || ($top['score'] >= 140 && ($top['score'] - $second['score']) >= 60)) {
            return ['status' => 'found', 'service_id' => $top['id'], 'service_name' => $top['title']];
        }

        return [
            'status'  => 'multiple',
            'options' => $scored->take(5)->map(fn($i) => [
                'id'       => $i['id'],
                'title'    => $i['title'],
                'subtitle' => 'Service',
            ])->values()->toArray(),
        ];
    }

    private function compute_waiting_on($application, $latest_assignment, $approval_flow): string
    {
        $status         = $application->status ?? '';
        $payment_status = $application->payment_status ?? '';

        if (in_array($status, ['approved', 'noc_issued', 'completed', 'certificate_issued'])) return 'none';
        if ($status === 'draft') return 'applicant';
        if ($status === 'send_back') return 'applicant';
        if ($payment_status === 'pending') return 'applicant';

        if ($latest_assignment) {
            $as = $latest_assignment->status ?? '';
            if ($as === 'pending' && empty($latest_assignment->action_taken_at)) return 'department';
            if ($as === 'send_back') return 'applicant';
        }

        return 'system';
    }

    private function matches_filter(string $status, string $filter): bool
    {
        $s = strtolower($status);
        return match ($filter) {
            'pending'      => str_contains($s, 'pending') || str_contains($s, 'send_back'),
            'approved'     => str_contains($s, 'approved') || str_contains($s, 'completed') || str_contains($s, 'noc_issued'),
            'rejected'     => str_contains($s, 'rejected'),
            'under_review' => str_contains($s, 'review') || str_contains($s, 'process') || str_contains($s, 'submitted'),
            default        => true,
        };
    }
}
