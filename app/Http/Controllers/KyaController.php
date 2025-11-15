<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\KyaMaster;
use Illuminate\Http\Request;
use Exception;

class KyaController extends Controller
{
    /**
     * Fetch all unique Sectors
     */
    public function get_sectors(Request $request)
    {
        try {
            $sectors = KyaMaster::query()
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
                    'risk'   => 'required|string',
                ],
                [
                    'sector.required' => 'Sector is required.',
                    'risk.required'   => 'Risk category is required.',
                ]
            );

            $industrySectors = KyaMaster::query()
                ->where('sector', $request->sector)
                ->where('risk_category', $request->risk)
                ->whereNotNull('industry_sector')
                ->distinct()
                ->orderBy('industry_sector')
                ->pluck('industry_sector')
                ->values();

            return response()->json([
                'status'           => true,
                'sector'           => $request->sector,
                'risk_category'    => $request->risk,
                'industry_sectors' => $industrySectors,
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
                    'sector'   => 'required|string',
                    'risk'     => 'required|string',
                    'industry' => 'required|string',
                ],
                [
                    'sector.required'   => 'Sector is required.',
                    'risk.required'     => 'Risk category is required.',
                    'industry.required' => 'Industry sector is required.',
                ]
            );

            $questions = KyaMaster::query()
                ->where('sector', $request->sector)
                ->where('risk_category', $request->risk)
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
                ]);

            return response()->json([
                'status'   => true,
                'sector'   => $request->sector,
                'risk'     => $request->risk,
                'industry' => $request->industry,
                'data'     => $questions,
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
            $request->validate(
                [
                    'id'     => 'required|integer|exists:kya_master,id',
                    'answer' => 'required|string|in:yes,no,YES,NO,Yes,No',
                ],
                [
                    'id.required'     => 'Id is required.',
                    'id.exists'       => 'No KYA record found for this ID.',
                    'answer.required' => 'Answer is required.',
                ]
            );

            $record = KyaMaster::findOrFail($request->id);

            return response()->json([
                'status'            => true,
                'id'                => $record->id,
                'approval_name'     => $record->approval_name,
                'stage_of_business' => $record->stage_of_business,
                'sla_days'          => $record->sla_days,
                'steps'             => $record->steps,
                'documents_required'=> $record->documents_required,
                'fees'              => $record->fees,
                'legal_provision'   => $record->legal_provision,
                'more_info'         => $record->more_info,
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
}
