<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;

trait PaymentMapTrait
{
    public function payment_map_for_applications(array $application_ids): array
    {
        if (empty($application_ids)) return [];

        $payments = DB::table('payment_orders')
            ->select('application_id', 'order_id', 'payment_amount', 'payment_status', 'gateway', 'transaction_id', 'GRN_number', 'payment_datetime')
            ->whereNot('payment_status', "initiated")
            ->where(function ($q) use ($application_ids) {
                foreach ($application_ids as $id) {
                    $q->orWhereJsonContains('application_id', $id);
                }
            })
            ->get();

        $map = [];

        foreach ($payments as $p) {
            $ids = json_decode($p->application_id, true) ?: [];
            foreach (array_intersect($ids, $application_ids) as $app_id) {
                $map[$app_id][] = [
                    'order_id'         => $p->order_id,
                    'payment_amount'   => $p->payment_amount,
                    'payment_status'   => $p->payment_status,
                    'gateway'          => $p->gateway,
                    'transaction_id'   => $p->transaction_id,
                    'GRN_number'       => $p->GRN_number,
                    'payment_datetime' => $p->payment_datetime,
                ];
            }
        }

        return $map;
    }
}
