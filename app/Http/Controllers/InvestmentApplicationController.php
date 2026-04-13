<?php

namespace App\Http\Controllers;

use App\Models\InvestmentApplication;
use Illuminate\Http\Request;

class InvestmentApplicationController extends Controller
{
    public function investment_application_store(Request $request)
    {
        try {
            $request->validate([
                'aadhaar_or_business_id'     => 'nullable|string|max:50',
                'registered_office_address'  => 'nullable|string',
                'communication_address'      => 'nullable|string',
                'sector'                     => 'nullable|string|max:100',
                'legal_structure'            => 'nullable|string|max:100',
                'registration_no'            => 'nullable|string|max:100',
                'year_of_establishment'      => 'nullable|digits:4|integer',
                'gstin'                      => 'nullable|string|max:20',
                'industry_category'          => 'nullable|string|max:100',
                'brief_proposal'             => 'nullable|string',
                'project_title'              => 'nullable|string|max:255',
                'sub_sector'                 => 'nullable|string|max:100',
                'investment_value'           => 'nullable|numeric|min:0',
                'employment_to_be_generated' => 'nullable|integer|min:0',
                'nature_of_activity'         => 'nullable|string|max:100',
                'proposed_land_type'         => 'nullable|string|max:100',
                'area_required'              => 'nullable|numeric|min:0',
                'location_lat'               => 'nullable|numeric',
                'location_lng'               => 'nullable|numeric',
                'location_address'           => 'nullable|string|max:500',
                'connectivity_needs'         => 'nullable|array',
                'connectivity_needs.*'       => 'nullable|string',
                'other_requirements'         => 'nullable|string',
                'heard_from'                 => 'required',
            ]);

            $application = InvestmentApplication::create([
                'user_id'                    => auth()->id(),
                'aadhaar_or_business_id'     => $request->aadhaar_or_business_id,
                'registered_office_address'  => $request->registered_office_address,
                'communication_address'      => $request->communication_address,
                'sector'                     => $request->sector,
                'legal_structure'            => $request->legal_structure,
                'registration_no'            => $request->registration_no,
                'year_of_establishment'      => $request->year_of_establishment,
                'gstin'                      => $request->gstin,
                'industry_category'          => $request->industry_category,
                'brief_proposal'             => $request->brief_proposal,
                'project_title'              => $request->project_title,
                'sub_sector'                 => $request->sub_sector,
                'investment_value'           => $request->investment_value,
                'employment_to_be_generated' => $request->employment_to_be_generated,
                'nature_of_activity'         => $request->nature_of_activity,
                'proposed_land_type'         => $request->proposed_land_type,
                'area_required'              => $request->area_required,
                'location_lat'               => $request->location_lat,
                'location_lng'               => $request->location_lng,
                'location_address'           => $request->location_address,
                'connectivity_needs'         => $request->connectivity_needs ? json_encode($request->connectivity_needs) : null,
                'other_requirements'         => $request->other_requirements,
                'heard_from'                 => $request->heard_from,
                'status'                     => 'pending',
            ]);

            $application->update([
                'query_id' => 'TR' . str_pad($application->id, 7, '0', STR_PAD_LEFT),
            ]);

            return response()->json([
                'status'  => 1,
                'message' => 'Investment application submitted successfully',
                'data'    => $application,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Failed to submit application', 'error' => $e->getMessage()], 500);
        }
    }

    public function investment_applications_list(Request $request)
    {
        try {
            $request->validate([
                'status'   => 'nullable|in:pending,under_review,approved,rejected',
                'search'   => 'nullable|string|max:255',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            $perPage = $request->per_page ?? 10;

            $q = InvestmentApplication::query()->with('user:id,authorized_person_name,email_id,mobile_no')->orderBy('id', 'desc');

            if ($request->filled('status')) {
                $q->where('status', $request->status);
            }

            if ($request->filled('search')) {
                $s = $request->search;
                $q->where(function ($qq) use ($s) {
                    $qq->where('project_title', 'like', "%{$s}%")
                        ->orWhere('gstin', 'like', "%{$s}%")
                        ->orWhereHas('user', function ($u) use ($s) {
                            $u->where('authorized_person_name', 'like', "%{$s}%")
                                ->orWhere('email_id', 'like', "%{$s}%")
                                ->orWhere('mobile_no', 'like', "%{$s}%");
                        });
                });
            }

            $data = $q->paginate($perPage);

            $items = collect($data->items())->map(function ($item) {
                $item->connectivity_needs = $item->connectivity_needs ? json_decode($item->connectivity_needs) : [];
                return $item;
            });

            return response()->json([
                'status'     => 1,
                'message'    => 'Investment applications fetched successfully',
                'data'       => $items,
                'pagination' => [
                    'current_page' => $data->currentPage(),
                    'last_page'    => $data->lastPage(),
                    'per_page'     => $data->perPage(),
                    'total'        => $data->total(),
                ],
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Failed to fetch applications', 'error' => $e->getMessage()], 500);
        }
    }

    public function investment_application_details(Request $request)
    {
        try {
            $request->validate(['id' => 'required|exists:investment_applications,id']);

            $application = InvestmentApplication::with('user:id,authorized_person_name,email_id,mobile_no')->where('id',$request->id)->first();
            $application->connectivity_needs = $application->connectivity_needs ? json_decode($application->connectivity_needs) : [];

            return response()->json(['status' => 1, 'message' => 'Application details fetched', 'data' => $application], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Failed to fetch application', 'error' => $e->getMessage()], 500);
        }
    }

    public function investment_application_update_status(Request $request)
    {
        try {
            $request->validate([
                'id'         => 'required|exists:investment_applications,id',
                'status'     => 'required|in:pending,under_review,approved,rejected',
                'admin_note' => 'nullable|string',
            ]);

            $application = InvestmentApplication::where('id',$request->id)->first();
            $application->update([
                'status'     => $request->status,
                'admin_note' => $request->admin_note,
            ]);

            return response()->json(['status' => 1, 'message' => 'Status updated successfully', 'data' => $application->fresh()], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Failed to update status', 'error' => $e->getMessage()], 500);
        }
    }
}
