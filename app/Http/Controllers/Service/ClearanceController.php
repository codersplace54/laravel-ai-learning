<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Clearance;
use Illuminate\Support\Facades\Auth;

class ClearanceController extends Controller
{
    public function get_approved_services(Request $request)
    {


        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $services = Clearance::with([
                'service:id,service_title_or_description'
            ])
                ->get()
                ->pluck('service')
                ->filter()
                ->unique('id')
                ->map(function ($service) {
                    return [
                        'service_id'   => $service->id,
                        'service_name' => $service->service_title_or_description,
                    ];
                })
                ->values();

            return response()->json([
                'status' => 1,
                'message' => 'Licence Approved services fetched successfully.',
                'data' => $services,
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function fetch_licence_numbers(Request $request)
    {
        try {

            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer',
                'user_id'    => 'required|integer',
            ]);

            $licence_number = Clearance::where('service_id', $request->service_id)
                ->where('user_id', $request->user_id)
                ->pluck('licence_number');

            if (!$licence_number) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Licence number not found.',
                ], 404);
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Licence number fetched successfully.',
                'data'    => [
                    'licence_number' => $licence_number
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'error'   => $e->errors(),
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
