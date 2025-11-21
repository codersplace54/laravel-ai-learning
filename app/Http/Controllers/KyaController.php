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
                ],
                [
                    'sector.required' => 'Sector is required.',
                ]
            );

            $industry_sectors = KyaMaster::query()
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

            $questions = KyaMaster::query()
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

            return response()->json([
                'status'   => true,
                'industry' => $request->industry,
                'risk_category' => $questions->first()->risk_category ?? null,
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
                    'ids'     => 'required|array',
                ],
                [
                    'id.required'     => 'Id is required.',
                ]
            );

            $records = KyaMaster::whereIn('id', $request->ids)->get();

            $mapped = $records->map(function ($item) {
                return [
                    'id'                => $item->id,
                    'approval_name'     => $item->approval_name,
                    'stage_of_business' => $item->stage_of_business,
                    'sla_days'          => $item->sla_days,
                    'steps'             => $item->steps,
                    'documents_required' => $item->documents_required,
                    'fees'              => $item->fees,
                    'legal_provision'   => $item->legal_provision,
                    'more_info'         => $item->more_info,
                ];
            });

            return response()->json([
                'status' => true,
                'records' => $mapped,
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
