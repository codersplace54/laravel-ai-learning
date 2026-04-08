<?php

namespace App\Http\Controllers;

use App\Models\InvestorQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

            $data = $investorQuery->toArray();

            $data['attachment'] = $investorQuery->attachment
                ? asset('storage/' . $investorQuery->attachment)
                : null;

            return response()->json([
                'status' => 1,
                'message' => 'Investor query submitted successfully',
                'data' => $data
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
                'attachment' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:20480',
            ]);

            $query = InvestorQuery::where('id', $request->id)->first();



            if ($request->hasFile('attachment')) {
                if ($query->attachment && Storage::disk('public')->exists($query->attachment)) {
                    Storage::disk('public')->delete($query->attachment);
                }

                $file = $request->file('attachment');
                $filename = uniqid() . '.' . $file->getClientOriginalExtension();
                $attachment = $file->storeAs('uploads/investor_queries', $filename, 'public');
            }

            $query->update([
                'status'           => $request->status,
                'admin_note'      => $request->admin_note,
                'attachment'  => $attachment ?? $query->attachment,
            ]);

            $data = $query->fresh()->toArray();

            $data['attachment'] = $query->attachment
                ? asset('storage/' . $query->attachment)
                : null;

            return response()->json([
                'status'  => 1,
                'message' => 'Status updated successfully',
                'data'    => $data,
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
