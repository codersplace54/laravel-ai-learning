<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\Clearance;
use Illuminate\Support\Facades\Auth;
use App\Models\UserServiceApplication;

class ClearanceController extends Controller
{
    // public function get_approved_services(Request $request)
    // {


    //     try {


    //         if (!Auth::check()) {
    //             return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
    //         }

    //         $services = Clearance::with([
    //             'service:id,service_title_or_description'
    //         ])
    //             ->get()
    //             ->pluck('service')
    //             ->filter()
    //             ->unique('id')
    //             ->map(function ($service) {
    //                 return [
    //                     'service_id'   => $service->id,
    //                     'service_name' => $service->service_title_or_description,
    //                 ];
    //             })
    //             ->values();

    //         return response()->json([
    //             'status' => 1,
    //             'message' => 'Licence Approved services fetched successfully.',
    //             'data' => $services,
    //         ]);
    //     } catch (\Exception $e) {

    //         return response()->json([
    //             'status' => 0,
    //             'message' => 'Something went wrong.',
    //             'error' => $e->getMessage(),
    //         ], 500);
    //     }
    // }

    public function get_approved_services(Request $request)
    {


        try {

            if (!Auth::check()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Unauthenticated user.'
                ], 401);
            }

            $services = UserServiceApplication::with([
                'service:id,service_title_or_description'
            ])
                ->whereNotNull('license_id')
                ->whereNotNull('NOC_generationDate')
                ->where('status', 'noc_issued')
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
                'status'  => 1,
                'message' => 'Licence Approved services fetched successfully.',
                'data'    => $services,
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
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

            $licence_number = UserServiceApplication::where('service_id', $request->service_id)
                ->where('user_id', $request->user_id)
                ->whereNotNull('license_id')
                ->where('status', 'noc_issued')
                ->pluck('license_id');

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

            $applications = UserServiceApplication::with([
                'service:id,service_title_or_description,department_id',
                'service.department:id,name'
            ])
                ->where('user_id', $request->user_id)
                ->whereNotNull('license_id')
                ->where('status', 'noc_issued')
                ->orderByDesc('updated_at')
                ->get()
                ->map(function ($application) {
                    return [
                        'application_id'      => $application->id,
                        'service_id'          => $application->service_id,
                        'service_name'        => optional($application->service)->service_title_or_description,
                        'department_name'     => optional($application->service?->department)->name,
                        'licence_number'      => $application->license_id,
                        'licence_date'        => $application->NOC_generationDate ?? null,
                        'licence_valid_till'  => $application->NOC_expiry_date ?? null,
                        'status'              => 'active',
                    ];
                });

            if ($applications->isEmpty()) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'No clearance records found.',
                    'data'    => [],
                ]);
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Clearances fetched successfully.',
                'data'    => $applications,
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

    //  public function fetch_clearance_details(Request $request)
    // {
    //     try {

    //         if (!Auth::check()) {
    //             return response()->json([
    //                 'status' => 0,
    //                 'message' => 'Unauthenticated user.'
    //             ], 401);
    //         }

    //         $request->validate([
    //             'clearance_id' => 'required|integer|exists:clearances,id',
    //         ]);

    //         $clearance = Clearance::with([
    //             'service:id,service_title_or_description,department_id',
    //             'service.department:id,name'
    //         ])
    //             ->where('id', $request->clearance_id)
    //             ->first();

    //         if (!$clearance) {
    //             return response()->json([
    //                 'status'  => 0,
    //                 'message' => 'Clearance not found.',
    //             ], 404);
    //         }

    //         return response()->json([
    //             'status'  => 1,
    //             'message' => 'Clearance details fetched successfully.',
    //             'data'    => [
    //                 'id'                 => $clearance->id,
    //                 'user_id'            => $clearance->user_id,
    //                 'application_id'     => $clearance->application_id,
    //                 'service_id'         => $clearance->service_id,
    //                 'service_name'       => optional($clearance->service)->service_title_or_description,
    //                 'department_id'      => optional($clearance->service)->department_id,
    //                 'department_name'    => optional($clearance->service?->department)->name,
    //                 'licence_number'     => $clearance->licence_number,
    //                 'licence_date'       => $clearance->licence_date,
    //                 'licence_valid_till' => $clearance->licence_valid_till,
    //                 'status'             => $clearance->status,
    //                 'created_at'         => $clearance->created_at,
    //                 'updated_at'         => $clearance->updated_at,
    //             ],
    //         ]);
    //     } catch (\Illuminate\Validation\ValidationException $e) {

    //         return response()->json([
    //             'status'  => 0,
    //             'message' => 'Validation failed.',
    //             'error'   => $e->errors(),
    //         ], 422);
    //     } catch (\Exception $e) {

    //         return response()->json([
    //             'status'  => 0,
    //             'message' => 'Something went wrong.',
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }

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
                'application_id' => 'required|integer|exists:user_service_applications,id',
            ]);

            $application = UserServiceApplication::with([
                'service:id,service_title_or_description,department_id',
                'service.department:id,name'
            ])
                ->where('id', $request->application_id)
                ->first();

            if (!$application) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Clearance not found.',
                ], 404);
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Clearance details fetched successfully.',
                'data'    => [
                    'user_id'            => $application->user_id,
                    'application_id'     => $application->id,
                    'application_number' =>  $application->applicationId,
                    'NOC_certificate'    => $application->noc_certificate_url,
                    'service_id'         => $application->service_id,
                    'service_name'       => optional($application->service)->service_title_or_description,
                    'department_id'      => optional($application->service)->department_id,
                    'department_name'    => optional($application->service?->department)->name,
                    'licence_number'     => $application->license_id,
                    'licence_date'       => $application->NOC_generationDate,
                    'licence_valid_till' => $application->NOC_expiry_date,
                    'status'             => $application->status,
                    'created_at'         => $application->created_at,
                    'updated_at'         => $application->updated_at,
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
