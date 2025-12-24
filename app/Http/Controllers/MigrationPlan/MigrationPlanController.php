<?php

namespace App\Http\Controllers\MigrationPlan;

use App\Http\Controllers\Controller;
use App\Models\MigrationPlan;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB as FacadesDB;

class MigrationPlanController extends Controller
{
    public function migration_notice()
    {
        try {

        $unavailable_services = MigrationPlan::query()
            ->whereNotNull('unavailability_from')
            ->orderBy('unavailability_from')
            ->get()
            ->map(function($plan) {
                return [
                    'id'                   => $plan->id,
                    'service_name'         => $plan->service_name,
                    'unavailability_from'  => $plan->unavailability_from ? $plan->unavailability_from->format('d-M-Y h:i A') : null,
                ];
            })
            ->values();

        $migrated_services = MigrationPlan::query()
            ->whereNotNull('hyperlink')
            ->orderBy('service_name')
            ->get()
            ->map(function($plan) {
                return [
                    'id'                => $plan->id,
                    'service_name'      => $plan->service_name,
                    'availability_from' => $plan->availability_from ? $plan->availability_from->format('d-M-Y h:i A') : null,
                    'hyperlink'         => $plan->hyperlink,
                ];
            })
            ->values();

        return response()->json([
            'status'  => 1,
            'message' => 'Migration notice data fetched successfully.',
            'data'    => [
                'unavailable_services' => $unavailable_services,
                'migrated_services'    => $migrated_services,
            ],
        ]);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
