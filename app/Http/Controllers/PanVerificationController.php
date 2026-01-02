<?php

namespace App\Http\Controllers;

use App\Services\PanVerificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Exception;

class PanVerificationController extends Controller
{
    private PanVerificationService $pan_service;

    public function __construct(PanVerificationService $pan_service)
    {
        $this->pan_service = $pan_service;
    }

    /**
     * Verify PAN details
     */
    public function verify_pan(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'pan' => 'required|string|size:10|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/',
                'name' => 'required|string|max:85',
                'father_name' => 'nullable|string|max:75',
                'dob' => 'required|string|regex:/^\d{2}\/\d{2}\/\d{4}$/'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $response = $this->pan_service->verify_single_pan(
                $request->input('pan'),
                $request->input('name'),
                $request->input('father_name', ''),
                $request->input('dob')
            );

            $parsed_response = $this->pan_service->parse_response($response);

            return response()->json([
                'success' => true,
                'message' => 'PAN verification completed',
                'data' => $parsed_response
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'PAN verification failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify multiple PANs (batch verification)
     */
    public function verify_multiple_pans(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'pan_data' => 'required|array|min:1|max:5',
                'pan_data.*.pan' => 'required|string|size:10|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/',
                'pan_data.*.name' => 'required|string|max:85',
                'pan_data.*.father_name' => 'nullable|string|max:75',
                'pan_data.*.dob' => 'required|string|regex:/^\d{2}\/\d{2}\/\d{4}$/'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $pan_data_list = collect($request->input('pan_data'))->map(function ($item) {
                return [
                    'pan' => strtoupper(trim($item['pan'])),
                    'name' => strtoupper(trim($item['name'])),
                    'fathername' => strtoupper(trim($item['father_name'] ?? '')),
                    'dob' => trim($item['dob'])
                ];
            })->toArray();

            $results = [];
            foreach ($pan_data_list as $pan_data) {
                try {
                    $response = $this->pan_service->verify_pan($pan_data);
                    $parsed_response = $this->pan_service->parse_response($response);
                    $results[] = $parsed_response;
                } catch (Exception $e) {
                    $results[] = [
                        'success' => false,
                        'message' => 'Verification failed for PAN: ' . $pan_data['pan'],
                        'error' => $e->getMessage()
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Batch PAN verification completed',
                'data' => $results
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Batch PAN verification failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get PAN verification status codes and descriptions
     */
    public function get_status_codes(): JsonResponse
    {
        $status_codes = [
            'response_codes' => [
                '1' => 'Success',
                '2' => 'System Error',
                '3' => 'Authentication Failure',
                '4' => 'User not authorized',
                '5' => 'No PANs Entered or Number of PANs exceeds the limit (5)',
                '6' => 'User validity has expired',
                '8' => 'Not enough balance',
                '9' => 'Not an HTTPs request',
                '10' => 'POST method not used',
                '12' => 'Invalid version number entered'
            ],
            'pan_status_codes' => [
                'E' => 'Existing and Valid',
                'F' => 'Marked as Fake',
                'X' => 'Marked as Deactivated',
                'D' => 'Deleted',
                'N' => 'Record (PAN) Not Found in ITD Database/Invalid PAN',
                'EA' => 'Existing and Valid but event marked as "Amalgamation"',
                'EC' => 'Existing and Valid but event marked as "Acquisition"',
                'ED' => 'Existing and Valid but event marked as "Death"',
                'EI' => 'Existing and Valid but event marked as "Dissolution"',
                'EL' => 'Existing and Valid but event marked as "Liquidated"',
                'EM' => 'Existing and Valid but event marked as "Merger"',
                'EP' => 'Existing and Valid but event marked as "Partition"',
                'ES' => 'Existing and Valid but event marked as "Split"',
                'EU' => 'Existing and Valid but event marked as "Under Liquidation"'
            ]
        ];

        return response()->json([
            'success' => true,
            'message' => 'Status codes retrieved successfully',
            'data' => $status_codes
        ]);
    }
}