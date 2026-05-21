<?php

namespace App\Http\Controllers;

use App\Models\InvestmentApplication;
use App\Models\DepartmentUser;
use App\Jobs\SendWhatsAppNotification;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

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
                'dpr_or_other_documents'     => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
            ]);

            $dpr_or_other_documents = null;
            if ($request->file('dpr_or_other_documents')) {
                $file = $request->file('dpr_or_other_documents');
                $filename = uniqid() . '.' . $file->getClientOriginalExtension();
                $dpr_or_other_documents = $file->storeAs('uploads/' . Auth::id() . '/investment_applications', $filename, 'public');
            }

            $application = InvestmentApplication::create([
                'user_id'                    => Auth::id(),
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
                'dpr_or_other_documents'     => $dpr_or_other_documents,
                'status'                     => 'pending',
            ]);

            $application->update([
                'query_id' => 'TR' . str_pad($application->id, 7, '0', STR_PAD_LEFT),
            ]);

            $user = Auth::user();
            $phone = $user->whatsapp_no ?? $user->mobile_no;
            if ($phone) {
                SendWhatsAppNotification::dispatch($phone, 'investor_application_received_v1', [$application->query_id]);
            }

            $template = config('sms_templates.investor_application_received');
            $message = str_replace('{REQUEST_ID}', $application->query_id, $template['message']);
            SmsService::send($user->mobile_no, $message, $template['template_id']);

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
                'status'    => 'nullable|in:pending,under_review,approved,rejected',
                'search'    => 'nullable|string|max:255',
                'per_page'  => 'nullable|integer|min:1|max:100',
                'date_from' => 'nullable|date',
                'date_to'   => 'nullable|date|after_or_equal:date_from',
            ]);

            $user    = Auth::user();
            $perPage = $request->per_page ?? 10;

            $base = InvestmentApplication::query();

            if ($user->user_type === 'admin') {
                // admin sees all
            } elseif ($user->user_type === 'department') {
                $dept_user = DepartmentUser::where('user_id', $user->id)
                    ->whereIn('hierarchy_level', ['state1', 'state2', 'state3'])
                    ->where('is_active', 1)
                    ->first();

                if (!$dept_user) {
                    return response()->json(['status' => 0, 'message' => 'Access denied. Only state level department users can view investment applications.'], 403);
                }

                $base->whereHas('departments', function ($dq) use ($dept_user) {
                    $dq->where('departments.id', $dept_user->department_id);
                });
            } else {
                $base->where('user_id', $user->id);
            }

            if ($request->filled('date_from') && $request->filled('date_to')) {
                $base->whereBetween('created_at', [$request->date_from, $request->date_to]);
            } elseif ($request->filled('date_from')) {
                $base->whereDate('created_at', '>=', $request->date_from);
            } elseif ($request->filled('date_to')) {
                $base->whereDate('created_at', '<=', $request->date_to);
            }

            if ($request->filled('search')) {
                $s = $request->search;
                $base->where(function ($qq) use ($s) {
                    $qq->where('project_title', 'like', "%{$s}%")
                        ->orWhere('gstin', 'like', "%{$s}%")
                        ->orWhere('query_id', 'like', "%{$s}%")
                        ->orWhereHas('user', function ($u) use ($s) {
                            $u->where('authorized_person_name', 'like', "%{$s}%")
                                ->orWhere('email_id', 'like', "%{$s}%")
                                ->orWhere('mobile_no', 'like', "%{$s}%");
                        });
                });
            }

            $counts = (clone $base)->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $summary = [
                'total'        => (clone $base)->count(),
                'pending'      => $counts['pending'] ?? 0,
                'under_review' => $counts['under_review'] ?? 0,
                'approved'     => $counts['approved'] ?? 0,
                'rejected'     => $counts['rejected'] ?? 0,
            ];

            $q = (clone $base)->with([
                'user:id,authorized_person_name,email_id,mobile_no',
                'departments:id,name',
                'actionTaker:id,authorized_person_name',
            ])->orderBy('id', 'desc');

            if ($request->filled('status')) {
                $q->where('status', $request->status);
            }

            $data  = $q->paginate($perPage);
            $items = collect($data->items())->map(function ($item) {
                $item->connectivity_needs = $item->connectivity_needs ? json_decode($item->connectivity_needs) : [];
                $item->is_action_taken = !is_null($item->action_taken_by);
                $item->dpr_or_other_documents = $item->dpr_or_other_documents ? asset('storage/' . $item->dpr_or_other_documents) : null;
                return $item;
            });

            return response()->json([
                'status'     => 1,
                'message'    => 'Investment applications fetched successfully',
                'summary'    => $summary,
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

            $application = InvestmentApplication::with([
                'user:id,authorized_person_name,email_id,mobile_no',
                'departments:id,name',
                'actionTaker:id,authorized_person_name',
            ])->where('id', $request->id)->first();

            $application->connectivity_needs = $application->connectivity_needs ? json_decode($application->connectivity_needs) : [];
            $application->is_action_taken = !is_null($application->action_taken_by);
            $application->dpr_or_other_documents = $application->dpr_or_other_documents ? asset('storage/' . $application->dpr_or_other_documents) : null;

            return response()->json(['status' => 1, 'message' => 'Application details fetched', 'data' => $application], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Failed to fetch application', 'error' => $e->getMessage()], 500);
        }
    }

    public function investment_application_assign(Request $request)
    {
        try {
            $request->validate([
                'id'             => 'required|exists:investment_applications,id',
                'department_ids' => 'required|array|min:1',
                'department_ids.*' => 'exists:departments,id',
                'remark'         => 'nullable|string',
            ]);

            $application = InvestmentApplication::where('id',$request->id)->first();
            $application->departments()->sync($request->department_ids);

            $application->update([
                'status' => 'under_review',
                'remark' => $request->remark,
            ]);

            return response()->json([
                'status'  => 1,
                'message' => 'Application assigned to department(s) successfully',
                'data'    => $application->fresh()->load('departments:id,name'),
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Failed to assign application', 'error' => $e->getMessage()], 500);
        }
    }

    public function investment_application_update_status(Request $request)
    {
        try {
            $request->validate([
                'id'     => 'required|integer',
                'status' => 'required|in:pending,under_review,approved,rejected',
                'remark' => 'nullable|string',
            ]);
            
            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated.'], 401);
            }

            if ($user->user_type === 'department') {
                $dept_user = DepartmentUser::where('user_id', $user->id)
                    ->whereIn('hierarchy_level', ['state1', 'state2', 'state3'])
                    ->where('is_active', 1)
                    ->first();

                if (!$dept_user) {
                    return response()->json(['status' => 0, 'message' => 'Access denied.'], 403);
                }

                $application = InvestmentApplication::whereHas('departments', function ($dq) use ($dept_user) {
                    $dq->where('departments.id', $dept_user->department_id);
                })->where('id', $request->id)->first();

                if (!$application) {
                    return response()->json(['status' => 0, 'message' => 'Application not found or not assigned to your department.'], 404);
                }
            } else {
                $application = InvestmentApplication::where('id', $request->id)->first();;
            }

            $application->update([
                'status'          => $request->status,
                'remark'          => $request->remark,
                'action_taken_by' => $user->id,
            ]);

            $application->load('user');
            $phone = $application->user->whatsapp_no ?? $application->user->mobile_no ?? null;
            if ($phone) {
                SendWhatsAppNotification::dispatch($phone, 'investor_app_update_v1', [
                    $application->query_id,
                    $request->remark ?? 'No remark provided',
                ]);
            }

            $template = config('sms_templates.investor_application_update');
            $message = str_replace('{REQUEST_ID}', $application->query_id, $template['message']);
            SmsService::send($application->user->mobile_no, $message, $template['template_id']);

            return response()->json(['status' => 1, 'message' => 'Status updated successfully', 'data' => $application->fresh()], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'message' => 'Failed to update status', 'error' => $e->getMessage()], 500);
        }
    }
}
