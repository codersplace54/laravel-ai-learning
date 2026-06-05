<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ApplicationStuckToolService
{
    public function run_tool(string $tool_name, array $arguments): array
    {
        return match ($tool_name) {
            'find_application' => $this->find_application_tool($arguments),
            'get_application_details' => $this->get_application_details_tool($arguments),
            'get_user_details' => $this->get_user_details_tool($arguments),
            'get_service_details' => $this->get_service_details_tool($arguments),
            'get_payment_details' => $this->get_payment_details_tool($arguments),
            'get_assignment_flow' => $this->get_assignment_flow_tool($arguments),
            'get_workflow_history' => $this->get_workflow_history_tool($arguments),
            'get_service_approval_flow' => $this->get_service_approval_flow_tool($arguments),
            'get_basic_system_checks' => $this->get_basic_system_checks_tool($arguments),
            default => throw new RuntimeException('Unknown tool name: ' . $tool_name),
        };
    }

    private function find_application_tool(array $arguments): array
    {
        $search_type = $arguments['search_type'] ?? null;
        $search_value = $arguments['search_value'] ?? null;

        if (!$search_type || !$search_value) {
            throw new RuntimeException('search_type and search_value are required.');
        }

        $application = $this->find_application($search_type, $search_value);

        return [
            'found' => $application !== null,
            'application' => $application ? (array) $application : null,
        ];
    }

    private function get_application_details_tool(array $arguments): array
    {
        $application_id = $this->require_application_id($arguments);

        return [
            'application' => $this->get_application_details($application_id),
        ];
    }

    private function get_user_details_tool(array $arguments): array
    {
        $user_id = $arguments['user_id'] ?? null;

        if (!$user_id) {
            throw new RuntimeException('user_id is required.');
        }

        return [
            'user' => $this->get_user_details((int) $user_id),
        ];
    }

    private function get_service_details_tool(array $arguments): array
    {
        $service_id = $arguments['service_id'] ?? null;

        if (!$service_id) {
            throw new RuntimeException('service_id is required.');
        }

        return [
            'service' => $this->get_service_details((int) $service_id),
        ];
    }

    private function get_payment_details_tool(array $arguments): array
    {
        $application_id = $this->require_application_id($arguments);

        return [
            'payments' => $this->get_payment_details($application_id),
        ];
    }

    private function get_assignment_flow_tool(array $arguments): array
    {
        $application_id = $this->require_application_id($arguments);

        return [
            'assignment_flow' => $this->get_assignment_flow($application_id),
        ];
    }

    private function get_workflow_history_tool(array $arguments): array
    {
        $application_id = $this->require_application_id($arguments);

        return [
            'workflow_history' => $this->get_workflow_history($application_id),
        ];
    }

    private function get_service_approval_flow_tool(array $arguments): array
    {
        $service_id = $arguments['service_id'] ?? null;

        if (!$service_id) {
            throw new RuntimeException('service_id is required.');
        }

        return [
            'service_approval_flow' => $this->get_service_approval_flow((int) $service_id),
        ];
    }

    private function get_basic_system_checks_tool(array $arguments): array
    {
        $application_id = $this->require_application_id($arguments);

        return [
            'system_checks' => $this->get_basic_system_checks($application_id),
        ];
    }

    private function require_application_id(array $arguments): int
    {
        $application_id = $arguments['application_id'] ?? null;

        if (!$application_id) {
            throw new RuntimeException('application_id is required.');
        }

        return (int) $application_id;
    }

    private function find_application(string $search_type, string $search_value): ?object
    {
        if ($search_type === 'application_id') {
            return DB::table('user_service_applications')
                ->where('id', $search_value)
                ->select([
                    'id',
                    'applicationId',
                    'service_id',
                    'user_id',
                    'status',
                    'payment_status',
                    'created_at',
                    'updated_at',
                ])
                ->first();
        }

        if ($search_type === 'applicationId') {
            return DB::table('user_service_applications')
                ->where('applicationId', $search_value)
                ->select([
                    'id',
                    'applicationId',
                    'service_id',
                    'user_id',
                    'status',
                    'payment_status',
                    'created_at',
                    'updated_at',
                ])
                ->first();
        }

        if ($search_type === 'mobile') {
            return DB::table('user_service_applications as usa')
                ->join('users as u', 'u.id', '=', 'usa.user_id')
                ->where('u.mobile_no', $search_value)
                ->select([
                    'usa.id',
                    'usa.applicationId',
                    'usa.service_id',
                    'usa.user_id',
                    'usa.status',
                    'usa.payment_status',
                    'usa.created_at',
                    'usa.updated_at',
                ])
                ->latest('usa.id')
                ->first();
        }

        if ($search_type === 'order_id') {
            return DB::table('user_service_applications as usa')
                ->join('payment_orders as po', function ($join) {
                    $join->whereRaw('JSON_CONTAINS(po.application_id, CAST(usa.id AS JSON))');
                })
                ->where('po.order_id', $search_value)
                ->select([
                    'usa.id',
                    'usa.applicationId',
                    'usa.service_id',
                    'usa.user_id',
                    'usa.status',
                    'usa.payment_status',
                    'usa.created_at',
                    'usa.updated_at',
                ])
                ->first();
        }

        if ($search_type === 'grn') {
            return DB::table('user_service_applications as usa')
                ->join('payment_orders as po', function ($join) {
                    $join->whereRaw('JSON_CONTAINS(po.application_id, CAST(usa.id AS JSON))');
                })
                ->where('po.GRN_number', $search_value)
                ->select([
                    'usa.id',
                    'usa.applicationId',
                    'usa.service_id',
                    'usa.user_id',
                    'usa.status',
                    'usa.payment_status',
                    'usa.created_at',
                    'usa.updated_at',
                ])
                ->first();
        }

        return null;
    }

    private function get_application_details(int $application_id): ?array
    {
        $application = DB::table('user_service_applications')
            ->where('id', $application_id)
            ->select([
                'id',
                'applicationId',
                'service_id',
                'user_id',
                'application_date',
                'status',
                'payment_status',
                'effective_fee',
                'final_fee',
                'total_fee',
                'paid_amount',
                'created_at',
                'updated_at',
            ])
            ->first();

        return $application ? (array) $application : null;
    }

    private function get_user_details(int $user_id): ?array
    {
        $user = DB::table('users')
            ->where('id', $user_id)
            ->select([
                'id',
                'user_name',
                'authorized_person_name',
                'mobile_no',
                'email_id',
                'user_type',
                'status',
                'created_at',
            ])
            ->first();

        if (!$user) {
            return null;
        }

        return [
            'id' => $user->id,
            'user_name' => $user->user_name,
            'authorized_person_name' => $user->authorized_person_name,
            'mobile_no' => $this->mask_mobile($user->mobile_no),
            'email_id' => $this->mask_email($user->email_id),
            'user_type' => $user->user_type,
            'status' => $user->status,
            'created_at' => $user->created_at,
        ];
    }

    private function get_service_details(int $service_id): ?array
    {
        $service = DB::table('service_masters as s')
            ->leftJoin('departments as d', 'd.id', '=', 's.department_id')
            ->where('s.id', $service_id)
            ->select([
                's.id',
                's.department_id',
                's.service_title_or_description',
                'd.name as department_name',
            ])
            ->first();

        return $service ? (array) $service : null;
    }

    private function get_payment_details(int $application_id): array
    {
        return DB::table('payment_orders')
            ->where(function ($query) use ($application_id) {
                $query->whereJsonContains('application_id', $application_id)
                    ->orWhereJsonContains('application_id', (string) $application_id);
            })
            ->orderByDesc('id')
            ->get([
                'id',
                'application_id',
                'user_id',
                'order_id',
                'payment_amount',
                'payment_status',
                'GRN_number',
                'gateway_response',
                'payment_datetime',
                'created_at',
                'updated_at',
            ])
            ->map(fn ($payment) => (array) $payment)
            ->toArray();
    }

    private function get_assignment_flow(int $application_id): array
    {
        return DB::table('application_workflow_assignments')
            ->where('application_id', $application_id)
            ->orderBy('id')
            ->get()
            ->map(fn ($assignment) => (array) $assignment)
            ->toArray();
    }

    private function get_workflow_history(int $application_id): array
    {
        return DB::table('application_workflow_history')
            ->where('application_id', $application_id)
            ->orderBy('id')
            ->get()
            ->map(fn ($history) => (array) $history)
            ->toArray();
    }

    private function get_service_approval_flow(int $service_id): array
    {
        return DB::table('service_approval_flows')
            ->where('service_id', $service_id)
            ->orderBy('id')
            ->get()
            ->map(fn ($approval) => (array) $approval)
            ->toArray();
    }

    private function get_basic_system_checks(int $application_id): array
    {
        $application = DB::table('user_service_applications')
            ->where('id', $application_id)
            ->first();

        $payment_count = DB::table('payment_orders')
            ->where(function ($query) use ($application_id) {
                $query->whereJsonContains('application_id', $application_id)
                    ->orWhereJsonContains('application_id', (string) $application_id);
            })
            ->count();

        $successful_payment_count = DB::table('payment_orders')
            ->where(function ($query) use ($application_id) {
                $query->whereJsonContains('application_id', $application_id)
                    ->orWhereJsonContains('application_id', (string) $application_id);
            })
            ->whereIn('payment_status', ['success', 'paid', 'completed'])
            ->count();

        $assignment_count = DB::table('application_workflow_assignments')
            ->where('application_id', $application_id)
            ->count();

        $workflow_history_count = DB::table('application_workflow_history')
            ->where('application_id', $application_id)
            ->count();

        return [
            'has_application' => $application !== null,
            'has_payment_order' => $payment_count > 0,
            'has_successful_payment' => $successful_payment_count > 0,
            'has_assignment_flow' => $assignment_count > 0,
            'has_workflow_history' => $workflow_history_count > 0,
            'application_status' => $application?->status,
            'application_payment_status' => $application?->payment_status,
            'paid_amount_is_empty' => empty($application?->paid_amount),
            'created_at_is_empty' => empty($application?->created_at),
        ];
    }

    private function mask_mobile(?string $mobile_no): ?string
    {
        if (!$mobile_no || strlen($mobile_no) < 4) {
            return $mobile_no;
        }

        return str_repeat('*', strlen($mobile_no) - 4) . substr($mobile_no, -4);
    }

    private function mask_email(?string $email_id): ?string
    {
        if (!$email_id || !Str::contains($email_id, '@')) {
            return $email_id;
        }

        [$name, $domain] = explode('@', $email_id, 2);

        return substr($name, 0, 2) . '***@' . $domain;
    }
}