<?php

namespace App\Http\Controllers\Incentive;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\Proforma;

class ProformaController extends Controller
{
    public function proforma_store(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'scheme_id'     => 'required|integer|exists:schemes,id',
                'code'          => 'required|string|max:64|' .
                    Rule::unique('proformas', 'code')->where(
                        fn($q) => $q->where('scheme_id', $request->scheme_id)
                    ),
                'title'         => 'required|string|max:200',
                'proforma_type' => 'required|in:eligibility,claim',
                'claim_type'    => ['nullable',
                    Rule::requiredIf($request->proforma_type === 'claim'),
                    'in:one_time,monthly,quarterly,half_yearly,annually,biennially,triennially,quinquenially',
                ],
                'description'   => 'nullable|string',
                'max_claim_count' => 'required|integer',
                'display_order' => 'nullable|integer',
                'status'        => 'nullable|integer|in:0,1',
                'depends_on_proforma_ids'   => 'nullable|array',
                'depends_on_proforma_ids.*' => 'integer|' .
                    Rule::exists('proformas', 'id')->where(
                        fn($q) =>
                        $q->where('scheme_id', $request->scheme_id)
                            ->where('proforma_type', 'eligibility')
                    ),
            ]);


            DB::beginTransaction();

            $depends_on_proforma_ids = $request->filled('depends_on_proforma_ids') ? json_encode($request->depends_on_proforma_ids) : null;

            $user = Auth::user();

            $proforma = Proforma::create([
                'scheme_id'     => $request->scheme_id,
                'code'          => $request->code,
                'title'         => $request->title,
                'proforma_type' => $request->proforma_type,
                'claim_type'    => $request->claim_type,
                'description'   => $request->description,
                'display_order' => $request->display_order,
                'max_claim_count' => $request->max_claim_count,
                'status'        => $request->status ?? 1,
                'depends_on_proforma_ids' => $depends_on_proforma_ids,
                'created_by'    => $user->email_id
            ]);

            $proforma->depends_on_proforma_ids = $proforma->depends_on_proforma_ids ? json_decode($proforma->depends_on_proforma_ids) : null;

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Proforma created successfully.',
                'data'    => $proforma,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {

            DB::rollBack();

            return response()->json(['status' => 0, 'message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json(['status' => 0, 'message' => 'Something went wrong.', 'error' => $e->getMessage()], 500);
        }
    }


    public function proforma_update(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $scheme_id = $request->scheme_id;

            if (!$scheme_id) {
                $scheme_id = Proforma::where('id', $request->proforma_id)->value('scheme_id');
            }

            $request->validate([
                'proforma_id'   => 'required|integer|exists:proformas,id',
                'scheme_id'     => 'sometimes|integer|exists:schemes,id',
                'code'          => [
                    'sometimes',
                    'string',
                    'max:64',
                    Rule::unique('proformas', 'code')
                        ->ignore($request->proforma_id)
                        ->where(fn($q) => $q->where('scheme_id', $scheme_id)),
                ],
                'title'         => 'sometimes|string|max:200',
                'proforma_type' => 'sometimes|in:eligibility,claim',
                'claim_type'    => 'nullable|in:one_time,monthly,quarterly,half_yearly,annually,biennially,triennially,quinquenially',
                'max_claim_count' => 'required|integer',
                'description'   => 'nullable|string',
                'display_order' => 'nullable|integer',
                'status'        => 'nullable|integer',
                'depends_on_proforma_ids.*' => 'integer|' .
                    Rule::exists('proformas', 'id')->where(
                        fn($q) =>
                        $q->where('scheme_id', $request->scheme_id)
                            ->where('proforma_type', 'eligibility')
                    ),
            ]);

            DB::beginTransaction();

            $user = Auth::user();
            $proforma = Proforma::where('id', $request->proforma_id)->first();

            $depends_on_proforma_ids = $request->filled('depends_on_proforma_ids') ? json_encode($request->depends_on_proforma_ids) : null;

            $proforma->update([
                'scheme_id'     => $request->scheme_id,
                'code'          => $request->code,
                'title'         => $request->title,
                'proforma_type' => $request->proforma_type,
                'claim_type'    => $request->claim_type,
                'max_claim_count' => $request->max_claim_count,
                'description'   => $request->description,
                'display_order' => $request->display_order,
                'status'        => $request->status,
                'depends_on_proforma_ids' => $depends_on_proforma_ids,
                'updated_by'    => $user->email_id
            ]);

            $proforma->depends_on_proforma_ids = $proforma->depends_on_proforma_ids ? json_decode($proforma->depends_on_proforma_ids) : null;

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Proforma updated successfully.',
                'data'    => $proforma,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {

            DB::rollBack();

            return response()->json(['status' => 0, 'message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json(['status' => 0, 'message' => 'Something went wrong.', 'error' => $e->getMessage()], 500);
        }
    }

    public function proforma_list(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'scheme_id'     => ['required', 'integer', 'exists:schemes,id'],
            ]);

            $proformas = Proforma::where('scheme_id', $request->scheme_id)
                ->orderBy('display_order')
                ->orderByDesc('id')
                ->get();

            foreach ($proformas as $proforma) {
                $proforma->depends_on_proforma_ids = $proforma->depends_on_proforma_ids
                    ? json_decode($proforma->depends_on_proforma_ids, true)
                    : null;
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Proformas fetched successfully.',
                'data'    => $proformas,
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function fetch_proforma_details(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'proforma_id' => 'required|integer|exists:proformas,id',
            ]);

            $proforma = Proforma::where('id', $request->proforma_id)->first();

            $proforma->depends_on_proforma_ids = $proforma->depends_on_proforma_ids
                ? json_decode($proforma->depends_on_proforma_ids, true)
                : null;

            return response()->json([
                'status' => 1,
                'message' => 'Proforma details fetched successfully.',
                'data' => $proforma,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function proforma_delete(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'id' => 'required|integer|exists:proformas,id',
            ]);

            DB::beginTransaction();

            $proforma = Proforma::where('id', $request->id)->first();

            $proforma->delete();

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Proforma deleted successfully.',
                'deleted_id' => $request->id
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
