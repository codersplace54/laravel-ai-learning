<?php

namespace App\Http\Controllers;

use App\Models\InvestorQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvestorQueryController extends Controller
{
    public function investor_query_store(Request $request)
    {
        try {
            $validated = $request->validate([
                'query_topic' => 'required|string',
                'company_name' => 'required|string',
                'company_address' => 'required|string',
                'city' => 'required|string',
                'state' => 'required|string',
                'present_activities' => 'nullable|string',
                'area_of_interest' => 'nullable|in:manufacturing,services,trading',
                'department_id' => 'nullable|exists:departments,id',
                'full_name' => 'required|string',
                'email' => 'required|email',
                'mobile' => 'required|string',
                'query_summary' => 'required|string',
                'query_details' => 'required|string',
                'attachment' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:20480',
                'reference_id' => 'nullable|string',
            ]);


            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $filename = uniqid() . '.' . $file->getClientOriginalExtension();
                $attachment = $file->storeAs('uploads/investor_queries', $filename, 'public');
            }

            $investorQuery = InvestorQuery::create([
                'query_topic'        => $request->query_topic,
                'company_name'       => $request->company_name,
                'company_address'    => $request->company_address,
                'city'               => $request->city,
                'state'              => $request->state,

                'present_activities' => $request->present_activities,
                'area_of_interest'   => $request->area_of_interest,
                'department_id'      => $request->department_id,

                'full_name'          => $request->full_name,
                'email'              => $request->email,
                'mobile'             => $request->mobile,

                'query_summary'      => $request->query_summary,
                'query_details'      => $request->query_details,

                'attachment'    => $attachment ?? null,
                'reference_id'       => $request->reference_id,

                'status'             => 'pending',
                'is_verified'        => 1,
            ]);

            return response()->json([
                'status' => 1,
                'message' => 'Investor query submitted successfully',
                'data' => $investorQuery
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Failed to submit query',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function get_previous_queries(Request $request)
    {
        try {

            $request->validate([
                'email' => 'required|email',
            ]);

            $queries = InvestorQuery::where('email', $request->email)
                ->orderBy('created_at', 'desc')
                ->get(['id', 'query_topic', 'query_summary', 'status', 'created_at']);

            return response()->json([
                'status' => 1,
                'message' => 'Previous queries fetched successfully',
                'data' => $queries
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Failed to fetch queries',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function investor_queries_list(Request $request)
    {
        try {
            $request->validate([
                'status'        => 'nullable|in:pending,resolved,closed',
                'department_id' => 'nullable|integer',
                'search'        => 'nullable|string|max:255',
                'per_page'      => 'nullable|integer|min:1|max:100',
            ]);

            $perPage = $request->per_page ?? 10;

            $q = InvestorQuery::query()->orderBy('id', 'desc');

            if ($request->filled('status')) {
                $q->where('status', $request->status);
            }

            if ($request->filled('department_id')) {
                $q->where('department_id', $request->department_id);
            }

            if ($request->filled('search')) {
                $s = $request->search;
                $q->where(function ($qq) use ($s) {
                    $qq->where('company_name', 'like', "%{$s}%")
                        ->orWhere('full_name', 'like', "%{$s}%")
                        ->orWhere('email', 'like', "%{$s}%")
                        ->orWhere('mobile', 'like', "%{$s}%")
                        ->orWhere('query_topic', 'like', "%{$s}%")
                        ->orWhere('query_summary', 'like', "%{$s}%");
                });
            }

            $data = $q->paginate($perPage);

            $data->getCollection()->transform(function ($item) {
                $item->attachment = $item->attachment
                    ? asset('storage/' . $item->attachment)
                    : null;
                return $item;
            });

            return response()->json([
                'status'  => 1,
                'message' => 'Investor queries fetched successfully',
                'data'    => $data->items(),
                'pagination' => [
                    'current_page' => $data->currentPage(),
                    'last_page' => $data->lastPage(),
                    'per_page' => $data->perPage(),
                    'total' => $data->total(),
                ],
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
            
        } catch (\Exception $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Failed to fetch queries',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function investor_queries_update_status(Request $request)
    {
        try {
            $request->validate([
                'id'      => 'required|exists:investor_queries,id',
                'status'      => 'required|in:pending,resolved,closed',
                'admin_note' => 'nullable|string',
            ]);

            $query = InvestorQuery::where('id', $request->id)->first();

            $query->update([
                'status'           => $request->status,
                'admin_note'      => $request->admin_note,
            ]);

            return response()->json([
                'status'  => 1,
                'message' => 'Status updated successfully',
                'data'    => $query->fresh(),
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Failed to update status',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
