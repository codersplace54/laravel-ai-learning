<?php

namespace App\Services\Ai;

use App\Models\UserServiceApplication;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ApplicationCollectionQueryService
{
    /**
     * These are database-level status groups.
     * They are not user sentence handlers.
     */
    private const STATUS_GROUPS = [
        'final_approved' => [
            'approved',
            'noc_issued',
        ],

        'in_process' => [
            'submitted',
            'under_review',
            're_submitted',
        ],

        'draft' => [
            'draft',
        ],

        'saved' => [
            'saved',
        ],

        'send_back' => [
            'send_back',
        ],

        'rejected' => [
            'rejected',
        ],

        'extra_payment' => [
            'extra_payment',
        ],
    ];

    /**
     * Execute any application collection question.
     */
    public function execute(
        int $user_id,
        array $plan,
        array $last_collection = []
    ): array {
        $answer_mode = $plan['answer_mode'] ?? 'list';
        $scope = $plan['scope'] ?? 'all_records';
        $filters = is_array($plan['filters'] ?? null)
            ? $plan['filters']
            : [];

        $resolved_question = trim(
            (string) ($plan['resolved_question'] ?? '')
        );

        $metric = $plan['metric'] ?? null;

        $application_ids = UserServiceApplication::where('user_id', $user_id)
            ->latest('id')
            ->pluck('id');

        $paid_amounts = \DB::table('payment_orders')
            ->whereIn('payment_status', ['paid', 'success'])
            ->get(['application_id', 'payment_amount'])
            ->flatMap(function ($order) {
                $ids = json_decode($order->application_id, true);
                if (!is_array($ids)) $ids = [$ids];
                return collect($ids)->map(fn($id) => ['id' => (int)$id, 'amount' => (float)$order->payment_amount]);
            })
            ->groupBy('id')
            ->map(fn($rows) => $rows->sum('amount'));

        $all_applications = UserServiceApplication::with([
            'service:id,service_title_or_description',
        ])
            ->where('user_id', $user_id)
            ->latest('id')
            ->get([
                'id',
                'applicationId',
                'service_id',
                'status',
                'payment_status',
                'paid_amount',
                'application_date',
                'created_at',
                'NOC_expiry_date',
            ])
            ->each(function ($app) use ($paid_amounts) {
                if (($app->paid_amount ?? 0) <= 0 && isset($paid_amounts[$app->id])) {
                    $app->paid_amount = $paid_amounts[$app->id];
                }
            });

        /*
         * Decide which records are the base records.
         */
        $base_applications = $all_applications;

        if ($scope === 'previous_result') {
            $previous_ids = collect(
                $last_collection['application_ids'] ?? []
            )
                ->map(fn($id) => (int) $id)
                ->filter()
                ->values()
                ->all();

            if (empty($previous_ids)) {
                return [
                    'success' => false,
                    'message' => 'I understood that you are referring to the previous application list, but no previous application list is available in this chat.',
                    'last_collection' => null,
                ];
            }

            $base_applications = $all_applications
                ->whereIn('id', $previous_ids)
                ->values();
        }

        /*
         * Never silently ignore unsupported filters.
         * But instead of failing, just ignore unknown filters and let AI handle it.
         */
        $supported_filter_keys = [
            'status_group',
            'payment_status',
            'submission_year',
            'service_id',
        ];

        // Strip unsupported filters silently — AI will handle the nuance
        $filters = array_intersect_key($filters, array_flip($supported_filter_keys));

        $matching_applications = $base_applications;

        /*
         * Status filter
         */
        if (!empty($filters['status_group'])) {
            // AI sometimes returns an array — take the first element
            $raw_status_group = $filters['status_group'];
            if (is_array($raw_status_group)) {
                $raw_status_group = $raw_status_group[0] ?? '';
            }
            $status_group = strtolower(trim((string) $raw_status_group));

            // Normalize renewal-related aliases to 'expired'
            if (in_array($status_group, ['renewal_pending', 'renewal_eligible', 'needs_renewal'], true)) {
                $status_group = 'expired';
            }

            $valid_status_groups = array_merge(
                array_keys(self::STATUS_GROUPS),
                [
                    'noc_issued',
                    'expired',
                ]
            );

            if (!in_array($status_group, $valid_status_groups, true)) {
                return [
                    'success' => false,
                    'message' =>
                    "I understood that you want applications with status group **{$status_group}**, but that status group is not supported safely.",
                    'last_collection' => null,
                ];
            }

            $matching_applications = $matching_applications
                ->filter(function ($application) use ($status_group) {
                    if ($status_group === 'noc_issued') {
                        return $application->status === 'noc_issued';
                    }

                    if ($status_group === 'expired') {
                        if ($application->status === 'expired') {
                            return true;
                        }

                        if (!$application->NOC_expiry_date) {
                            return false;
                        }

                        try {
                            return Carbon::parse(
                                $application->NOC_expiry_date
                            )
                                ->startOfDay()
                                ->lt(now()->startOfDay());
                        } catch (\Throwable $e) {
                            return false;
                        }
                    }

                    return in_array(
                        $application->status,
                        self::STATUS_GROUPS[$status_group],
                        true
                    );
                })
                ->values();
        }

        /*
         * Payment status filter
         */
        if (!empty($filters['payment_status'])) {
            $payment_status = strtolower(
                trim((string) $filters['payment_status'])
            );

            if (
                !in_array(
                    $payment_status,
                    ['pending', 'paid', 'failed'],
                    true
                )
            ) {
                return [
                    'success' => false,
                    'message' =>
                    "I understood that you want applications with payment status **{$payment_status}**, but that payment status is not valid.",
                    'last_collection' => null,
                ];
            }

            $matching_applications = $matching_applications
                ->filter(
                    fn($application) =>
                    strtolower(
                        (string) $application->payment_status
                    ) === $payment_status
                )
                ->values();
        }

        /*
         * Submission year filter
         */
        if (!empty($filters['submission_year'])) {
            $submission_year = (int) $filters['submission_year'];

            if (
                $submission_year < 2000
                || $submission_year > ((int) date('Y') + 1)
            ) {
                return [
                    'success' => false,
                    'message' =>
                    "The submission year **{$submission_year}** is not valid.",
                    'last_collection' => null,
                ];
            }

            $matching_applications = $matching_applications
                ->filter(function ($application) use ($submission_year) {
                    $date = $application->application_date
                        ?? $application->created_at;

                    if (!$date) {
                        return false;
                    }

                    try {
                        return Carbon::parse($date)->year
                            === $submission_year;
                    } catch (\Throwable $e) {
                        return false;
                    }
                })
                ->values();
        }

        /*
         * Service filter
         */
        if (!empty($filters['service_id'])) {
            $service_id = (int) $filters['service_id'];

            $matching_applications = $matching_applications
                ->filter(
                    fn($application) =>
                    (int) $application->service_id === $service_id
                )
                ->values();
        }

        $base_count = $base_applications->count();
        $matching_count = $matching_applications->count();

        /*
         * Store the result for follow-ups such as:
         * "Are these all expired?"
         */
        $new_last_collection = [
            'type' => 'application',

            'application_ids' => $matching_applications
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->values()
                ->all(),

            'base_application_ids' => $base_applications
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->values()
                ->all(),

            'count' => $matching_count,
            'filters' => $filters,
            'answer_mode' => $answer_mode,
            'resolved_question' => $resolved_question,
        ];

        /*
         * COUNT
         */
        if ($answer_mode === 'count') {
            return [
                'success' => true,
                'message' =>
                "**{$matching_count} applications** match your request.",
                'last_collection' => $new_last_collection,
            ];
        }

        /*
         * ALL MATCH
         */
        if ($answer_mode === 'all_match') {
            if ($base_count === 0) {
                return [
                    'success' => true,
                    'message' =>
                    'There are no applications available to check.',
                    'last_collection' => $new_last_collection,
                ];
            }

            if (empty($filters)) {
                return [
                    'success' => false,
                    'message' =>
                    'I understood that you want to know whether all applications match a condition, but the condition was not provided clearly.',
                    'last_collection' => null,
                ];
            }

            if ($matching_count === $base_count) {
                $message =
                    "Yes. All **{$base_count} applications** match that condition.";
            } else {
                $not_matching_count =
                    $base_count - $matching_count;

                $message =
                    "No. **{$matching_count} out of {$base_count} applications** match that condition. "
                    . "**{$not_matching_count} applications do not.**";
            }

            return [
                'success' => true,
                'message' => $message,
                'last_collection' => $new_last_collection,
            ];
        }

        /*
         * AGGREGATE / FACT / COMPARISON
         * PHP cannot safely compute these — pass full application data to AI.
         */
        if (in_array($answer_mode, ['aggregate', 'fact', 'comparison'], true)) {
            $applications_data = $all_applications->map(fn($a) => [
                'id'             => $a->id,
                'application_number' => $a->applicationId,
                'service_name'   => $a->service->service_title_or_description ?? null,
                'status'         => $a->status,
                'payment_status' => $a->payment_status,
                'paid_amount'    => (float) ($a->paid_amount ?? 0),
                'application_date' => optional($a->application_date)->toDateString() ?? optional($a->created_at)->toDateString(),
                'noc_expiry_date'  => $a->NOC_expiry_date,
            ])->values()->all();

            return [
                'success'         => true,
                'answer_from_ai'  => true,
                'applications'    => $applications_data,
                'total_count'     => count($applications_data),
                'message'         => null,
                'last_collection' => $new_last_collection,
            ];
        }

        /*
         * LIST
         */
        if ($answer_mode === 'list') {
            if ($matching_applications->isEmpty()) {
                return [
                    'success' => true,
                    'message' =>
                    'I could not find any applications matching that condition.',
                    'last_collection' => $new_last_collection,
                ];
            }

            $lines = [
                "I found **{$matching_count} matching applications**:",
            ];

            foreach (
                $matching_applications->values()
                as $index => $application
            ) {
                $number = $index + 1;

                $service_name =
                    $application->service
                    ->service_title_or_description
                    ?? 'Service name unavailable';

                $paid = (float) ($application->paid_amount ?? 0);
                $paid_display = $paid > 0
                    ? '₹' . rtrim(rtrim(number_format($paid, 2, '.', ''), '0'), '.')
                    : 'not paid';

                $lines[] =
                    "{$number}. **{$application->applicationId}**\n"
                    . "   - Service: {$service_name}\n"
                    . "   - Status: **{$application->status}**\n"
                    . "   - Payment: {$application->payment_status}\n"
                    . "   - Paid Amount: {$paid_display}";
            }

            return [
                'success' => true,
                'message' => implode("\n\n", $lines),
                'last_collection' => $new_last_collection,
            ];
        }

        /*
         * Safe fallback for an unexpected mode.
         */
        return [
            'success' => false,
            'message' =>
            'I understood your application question'
                . ($resolved_question !== ''
                    ? " as: **{$resolved_question}**"
                    : '')
                . ', but I could not map it to a safe collection operation. No application data was changed.',
            'last_collection' => null,
        ];
    }
}
