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

    public function fetch_user_clearances(Request $request)
    {


        try {

            if (!Auth::check()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Unauthenticated user.'
                ], 401);
            }

            $request->validate([
                'user_id' => 'required|integer',
            ]);

            $clearances = Clearance::with([
                'service:id,service_title_or_description',
                'service.department:id,name'
            ])
                ->where('user_id', $request->user_id)
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($clearance) {
                    return [
                        'id'                  => $clearance->id,
                        'application_id'      => $clearance->application_id,
                        'service_id'          => $clearance->service_id,
                        'service_name'        => optional($clearance->service)->service_title_or_description,
                        'department_name'     => optional($clearance->service?->department)->name,
                        'licence_number'      => $clearance->licence_number,
                        'licence_date'        => $clearance->licence_date,
                        'licence_valid_till'  => $clearance->licence_valid_till,
                        'status'              => $clearance->status,
                    ];
                });

            if ($clearances->isEmpty()) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'No clearance records found.',
                    'data'    => [],
                ]);
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Clearances fetched successfully.',
                'data'    => $clearances,
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

    public function fetch_clearance_details(Request $request)
    {
        try {

            if (!Auth::check()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Unauthenticated user.'
                ], 401);
            }

            $request->validate([
                'clearance_id' => 'required|integer|exists:clearances,id',
            ]);

            $clearance = Clearance::with([
                'service:id,service_title_or_description,department_id',
                'service.department:id,name'
            ])
                ->where('id', $request->clearance_id)
                ->first();

            if (!$clearance) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Clearance not found.',
                ], 404);
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Clearance details fetched successfully.',
                'data'    => [
                    'id'                 => $clearance->id,
                    'user_id'            => $clearance->user_id,
                    'application_id'     => $clearance->application_id,
                    'service_id'         => $clearance->service_id,
                    'service_name'       => optional($clearance->service)->service_title_or_description,
                    'department_id'      => optional($clearance->service)->department_id,
                    'department_name'    => optional($clearance->service?->department)->name,
                    'licence_number'     => $clearance->licence_number,
                    'licence_date'       => $clearance->licence_date,
                    'licence_valid_till' => $clearance->licence_valid_till,
                    'status'             => $clearance->status,
                    'created_at'         => $clearance->created_at,
                    'updated_at'         => $clearance->updated_at,
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
