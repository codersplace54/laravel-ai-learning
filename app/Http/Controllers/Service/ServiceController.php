<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\ServiceMaster;
use App\Models\UserServiceApplication;

class ServiceController extends Controller
{
    public function get_total_services()
    {

        try {

            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $total_services = ServiceMaster::where('status', 1)->count();

            return response()->json([
                'status'        => 1,
                'message' => 'Total Services fetched successfully.',
                'total_services' => $total_services
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching total services',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_applications_count_by_service(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
            ]);

            $service = ServiceMaster::where('id', $request->service_id)
                ->where('status', 1)
                ->first();

            if (!$service) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Service not found or inactive.'
                ], 404);
            }

            $total_applications = UserServiceApplication::where('service_id', $request->service_id)->count();

            return response()->json([
                'status'            => 1,
                'message'           => 'Total applications per service fetched successfully',
                'service_id'         => $service->id,
                'service_name'       => $service->service_title_or_description,
                'total_applications' => $total_applications
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching the application count',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_total_fees_paid_all_services()
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }


            $total_fee_paid = UserServiceApplication::sum('final_fee');

            return response()->json([
                'status'      => 1,
                'message'     => 'Total fees for all services fetched successfully',
                'total_fee_paid'  => $total_fee_paid
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong while fetching total fees',
                'error'   => $e->getMessage()
            ], 500);
        }
    }


    public function get_total_fees_paid_per_service(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
            ]);

            $service = ServiceMaster::where('id', $request->service_id)
                ->where('status', 1)
                ->first();

            if (!$service) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Service not found or inactive.'
                ], 404);
            }

            $total_fees = UserServiceApplication::where('service_id', $request->service_id)->sum('final_fee');

            return response()->json([
                'status'                             => 1,
                'message'                            => 'Total fees per service fetched successfully',
                'service_id'                         => $service->id,
                'service_name'                       => $service->service_title_or_description,
                'total_fee_paid_per_service'         => $total_fees
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching the total fees per service',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_avg_fees_paid_per_service(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
            ]);

            $service = ServiceMaster::where('id', $request->service_id)
                ->where('status', 1)
                ->first();

            if (!$service) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Service not found or inactive.'
                ], 404);
            }

            $total_fees = UserServiceApplication::where('service_id', $request->service_id)->avg('final_fee');

            return response()->json([
                'status'                             => 1,
                'message'                            => 'Average fees per service fetched successfully',
                'service_id'                         => $service->id,
                'service_name'                       => $service->service_title_or_description,
                'total_fee_paid_per_service'         => $total_fees
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching the average fees per service',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_average_approval_timeline_per_service(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
            ]);

            $service = ServiceMaster::where('id', $request->service_id)
                ->where('status', 1)
                ->first();

            if (!$service) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Service not found or inactive.'
                ], 404);
            }

            $avg_approval_days = UserServiceApplication::where('service_id', $request->service_id)
                ->where('status', 'approved')
                ->selectRaw('AVG(DATEDIFF(updated_at, application_date)) as avg_days')
                ->value('avg_days');

            return response()->json([
                'status'                             => 1,
                'message'                            => 'Average fees per service fetched successfully',
                'service_id'                         => $service->id,
                'service_name'                       => $service->service_title_or_description,
                'avg_approval_days'                  => (int) $avg_approval_days
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching the average fees per service',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_approved_applications_per_service(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
            ]);

            $service = ServiceMaster::where('id', $request->service_id)
                ->where('status', 1)
                ->first();

            if (!$service) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Service not found or inactive.'
                ], 404);
            }

            $approved_applications = UserServiceApplication::where('service_id', $request->service_id)
                ->where('status', 'approved')
                ->count();

            return response()->json([
                'status'                             => 1,
                'message'                            => 'Approved application per service fetched successfully',
                'service_id'                         => $service->id,
                'service_name'                       => $service->service_title_or_description,
                'approved_applications'              => $approved_applications
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching the approved application per service',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function get_pending_applications_per_service(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
            ]);

            $service = ServiceMaster::where('id', $request->service_id)
                ->where('status', 1)
                ->first();

            if (!$service) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Service not found or inactive.'
                ], 404);
            }

            $pending_applications = UserServiceApplication::where('service_id', $request->service_id)
                ->where('payment_status', 'pending')
                ->count();

            return response()->json([
                'status'                             => 1,
                'message'                            => 'Payment pending application per service fetched successfully',
                'service_id'                         => $service->id,
                'service_name'                       => $service->service_title_or_description,
                'pending_applications'               => $pending_applications
            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching the Payment pending application per service',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
