<?php

namespace App\Http\Controllers;

use App\Models\DepartmentGovernmentOrder;
use App\Models\InvestorQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GovernmentOrderController extends Controller
{
    public function government_orders_list()
    {
        try {
            $orders = DepartmentGovernmentOrder::query()
                ->orderBy('sl_no', 'asc')
                ->get();

            $orders->transform(function ($item) {
                $item->url = $item->url ? asset('storage/' . $item->url) : null;
                return $item;
            });

            return response()->json([
                'status'  => 1,
                'message' => 'Government orders fetched successfully',
                'data'    => $orders,
            ], 200);

        } catch (\Exception $e) {
            
            return response()->json([
                'status'  => 0,
                'message' => 'Failed to fetch government orders',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
