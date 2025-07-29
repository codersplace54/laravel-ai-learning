<?php

namespace App\Http\Controllers\ServiceMaster;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\ServiceMaster;


class ServiceMasterController extends Controller
{

    public function service_master_store(Request $request)
    {

        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'department_id' => 'required|integer',
                'service_title_or_description' => 'required|string|max:255',
                'noc_name' => 'required|string|max:255',
                'noc_short_name' => 'required|string|max:255',
                'noc_type' => 'required|in:CFE,CFO,Renewal,Special,Others',
                'noc_payment_type' => 'required|in:Estimated,Hardcoded,Calculated',
                'target_days' => 'nullable|integer',
                'has_input_form' => 'required|in:yes,no',
                'depends_on_services' => 'nullable|string',
                'generate_id' => 'required|in:yes,no',
                'generate_pdf' => 'required|in:yes,no',
                'generated_id_format' => 'nullable|string|max:255',
                'label_noc_date' => 'nullable|string|max:255',
                'label_noc_doc' => 'nullable|string|max:255',
                'label_noc_no' => 'nullable|string|max:255',
                'label_valid_till' => 'nullable|string|max:255',
                'show_letter_date' => 'required|in:yes,no',
                'auto_renewal' => 'required|in:yes,no',
                'external_data_share' => 'required|in:yes,no',
                'noc_validity' => 'nullable|integer',
                'valid_for_upload' => 'required|in:yes,no',
                'nsw_license_id' => 'nullable|string|max:100',
                'status' => 'nullable|integer'
            ]);

            DB::beginTransaction();

            $service_master = ServiceMaster::create([
                'department_id' => $request->department_id,
                'service_title_or_description' => $request->service_title_or_description,
                'noc_name' => $request->noc_name,
                'noc_short_name' => $request->noc_short_name,
                'noc_type' => $request->noc_type,
                'noc_payment_type' => $request->noc_payment_type,
                'target_days' => $request->target_days,
                'has_input_form' => $request->has_input_form,
                'depends_on_services' => $request->depends_on_services,
                'generate_id' => $request->generate_id,
                'generate_pdf' => $request->generate_pdf,
                'generated_id_format' => $request->generated_id_format,
                'label_noc_date' => $request->label_noc_date,
                'label_noc_doc' => $request->label_noc_doc,
                'label_noc_no' => $request->label_noc_no,
                'label_valid_till' => $request->label_valid_till,
                'show_valid_till' => $request->show_valid_till,
                'auto_renewal' => $request->auto_renewal,
                'external_data_share' => $request->external_data_share,
                'noc_validity' => $request->noc_validity,
                'valid_for_upload' => $request->valid_for_upload,
                'nsw_license_id' => $request->nsw_license_id,
                'status' => $request->status ?? 1,
            ]);

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Service master created successfully.',
                'data' => $service_master
            ]);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Failed to create service master.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
