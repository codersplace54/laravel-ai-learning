<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\KyaMaster;
use App\Models\UserKya;
use Illuminate\Http\Request;
use Exception;
use Illuminate\Support\Facades\Auth;
class KyaController extends Controller
{
    /**
     * Fetch all unique Sectors
     */
    public function get_sectors(Request $request)
    {
        try {
            $sectors = KyaMaster::query()
                ->where('approval_type', 'industry')
                ->whereNotNull('sector')
                ->distinct()
                ->orderBy('sector')
                ->pluck('sector')
                ->values();

            return response()->json([
                'status'  => true,
                'sectors' => $sectors,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to fetch sectors.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch Risk Categories for a selected Sector
     */
    public function get_risk_categories(Request $request)
    {
        try {
            $request->validate(
                [
                    'sector' => 'required|string',
                ],
                [
                    'sector.required' => 'Sector is required.',
                ]
            );

            $riskCategories = KyaMaster::query()
                ->where('approval_type', 'industry')
                ->where('sector', $request->sector)
                ->whereNotNull('risk_category')
                ->distinct()
                ->orderBy('risk_category')
                ->pluck('risk_category')
                ->values();

            return response()->json([
                'status'          => true,
                'sector'          => $request->sector,
                'risk_categories' => $riskCategories,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to fetch risk categories.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch Industry Sectors for a given Sector & Risk
     */
    public function get_industry_sectors(Request $request)
    {
        try {
            $request->validate(
                [
                    'sector' => 'required|string',
                ],
                [
                    'sector.required' => 'Sector is required.',
                ]
            );

            $industry_sectors = KyaMaster::query()
                ->where('approval_type', 'industry')
                ->where('sector', $request->sector)
                ->whereNotNull('industry_sector')
                ->distinct()
                ->orderBy('industry_sector')
                ->pluck('industry_sector')
                ->values();

            return response()->json([
                'status'           => true,
                'sector'           => $request->sector,
                'industry_sectors' => $industry_sectors,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to fetch industry sectors.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch Questions for a Sector/Risk/Industry
     */
    public function get_questions(Request $request)
    {
        try {
            $request->validate(
                [
                    'industry' => 'required|string',
                ],
                [
                    'industry.required' => 'Industry sector is required.',
                ]
            );

            $industry_questions = KyaMaster::query()
                ->where('approval_type', 'industry')
                ->where('industry_sector', $request->industry)
                ->orderBy('serial_no')
                ->get([
                    'id',
                    'serial_no',
                    'question',
                    'department',
                    'approval_name',
                    'stage_of_business',
                    'sla_days',
                    'risk_category',
                ]);

            $utility_questions = KyaMaster::query()
                ->where('approval_type', 'utility')
                ->orderBy('serial_no')
                ->get([
                    'id',
                    'serial_no',
                    'question',
                    'department',
                    'approval_name',
                    'stage_of_business',
                    'sla_days',
                ]);


            return response()->json([
                'status'   => true,
                'industry' => $request->industry,
                'risk_category' => $industry_questions->first()->risk_category ?? null,
                'industry'     => $industry_questions,
                'utility'     => $utility_questions,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (Exception $e) {

            return response()->json([
                'status'  => false,
                'message' => 'Failed to fetch questions.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Return Approval Details for a selected question/record
     */
    public function get_approval_details(Request $request)
    {
        try {
            $request->validate([
                'ids'         => 'nullable|array', 
                'utility_ids' => 'nullable|array', 
            ]);

            $industry_ids = $request->ids ?? [];
            $utility_ids  = $request->utility_ids ?? [];

            $industry_records = [];
            if (!empty($industry_ids)) {
                $industry_rows = KyaMaster::where('approval_type', 'industry')
                    ->whereIn('id', $industry_ids)
                    ->get();

                foreach ($industry_rows as $row) {
                    $industry_records[] = [
                        'id'                 => $row->id,
                        'approval_name'      => $row->approval_name,
                        'stage_of_business'  => $row->stage_of_business,
                        'sla_days'           => $row->sla_days,
                        'steps'              => $row->steps,
                        'documents_required' => $row->documents_required,
                        'fees'               => $row->fees,
                        'legal_provision'    => $row->legal_provision,
                        'more_info'          => $row->more_info,
                    ];
                }
            }

            $utility_records = [];
            if (!empty($utility_ids)) {
                $utility_rows = KyaMaster::where('approval_type', 'utility')
                    ->whereIn('id', $utility_ids)
                    ->get();

                foreach ($utility_rows as $row) {
                    $utility_records[] = [
                        'id'                 => $row->id,
                        'approval_name'      => $row->approval_name,
                        'stage_of_business'  => $row->stage_of_business,
                        'sla_days'           => $row->sla_days,
                        'steps'              => $row->steps,
                        'documents_required' => $row->documents_required,
                        'fees'               => $row->fees,
                        'legal_provision'    => $row->legal_provision,
                        'more_info'          => $row->more_info,
                    ];
                }
            }

            return response()->json([
                'status'  => true,
                'records' => $industry_records,
                'utilities_data'  => $utility_records,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve approval details.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function user_kya_store(Request $request)
    {
        try {
            $request->validate([
                'data'    => 'required',
            ]);

            $user_id = Auth::id();

            $existing = UserKya::where('user_id', $user_id)->first();

            if ($existing) {
                $existing->update(
                    [
                        'data' => json_encode($request->data)
                    ]);
            } else {
                UserKya::create([
                    'user_id' => $user_id,
                    'data'    => json_encode($request->data)
                ]);
            }

            return response()->json(['status' => true, 'message' => 'KYA data saved.']);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status' => false, 'errors' => $e->errors()], 422);
        } catch (Exception $e) {
            return response()->json(['status' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function user_kya_view(Request $request)
    {
        try {
            $user_id = Auth::id();

            $kya = UserKya::where('user_id', $user_id)->first();

            if (!$kya) {
                return response()->json(['status' => false, 'message' => 'No KYA data found.'], 404);
            }

            return response()->json([
                'status' => true,
                'data'   => json_decode($kya->data,true),
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status' => false, 'errors' => $e->errors()], 422);
        } catch (Exception $e) {
            return response()->json(['status' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
