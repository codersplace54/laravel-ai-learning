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


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'department_id' => 'required|integer|exists:departments,id',
                'service_title_or_description' => 'required|string|max:255',
                'noc_name' => 'required|string|max:255',
                'noc_short_name' => 'required|string|max:255',
                'noc_type' => 'required|in:CFE,CFO,Renewal,Special,Others',
                'noc_payment_type' => 'required|in:Estimated,Hardcoded,Calculated',
                'target_days' => 'nullable|integer',
                'allow_repeat_application' => 'required|in:yes,no',
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
                'added_by' => Auth::id(),
                'department_id' => $request->department_id,
                'service_title_or_description' => $request->service_title_or_description,
                'noc_name' => $request->noc_name,
                'noc_short_name' => $request->noc_short_name,
                'noc_type' => $request->noc_type,
                'noc_payment_type' => $request->noc_payment_type,
                'target_days' => $request->target_days,
                'allow_repeat_application' => $request->allow_repeat_application,
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

    public function service_master_update(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'id' => 'required|integer|exists:service_masters,id',
                'department_id' => 'required|exists:departments,id',
                'service_title_or_description' => 'required|string|max:255',
                'noc_name' => 'required|string|max:255',
                'noc_short_name' => 'required|string|max:255',
                'noc_type' => 'required|in:CFE,CFO,Renewal,Special,Others',
                'noc_payment_type' => 'required|in:Estimated,Hardcoded,Calculated',
                'target_days' => 'nullable|integer',
                'allow_repeat_application' => 'required|in:yes,no',
                'has_input_form' => 'required|in:yes,no',
                'depends_on_services' => 'nullable|string',
                'generate_id' => 'required|in:yes,no',
                'generate_pdf' => 'required|in:yes,no',
                'generated_id_format' => 'nullable|string|max:255',
                'label_noc_date' => 'nullable|string|max:255',
                'label_noc_doc' => 'nullable|string|max:255',
                'label_noc_no' => 'nullable|string|max:255',
                'label_valid_till' => 'nullable|string|max:255',
                'show_valid_till' => 'required|in:yes,no',
                'auto_renewal' => 'required|in:yes,no',
                'external_data_share' => 'required|in:yes,no',
                'noc_validity' => 'nullable|integer',
                'valid_for_upload' => 'required|in:yes,no',
                'nsw_license_id' => 'nullable|string|max:100',
                'status' => 'nullable|integer'
            ]);

            DB::beginTransaction();

            $service = ServiceMaster::where('id', $request->id)->first();

            if (!$service) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Service master not found.'
                ], 404);
            }

            $service->update([
                'department_id' => $request->department_id,
                'service_title_or_description' => $request->service_title_or_description,
                'noc_name' => $request->noc_name,
                'noc_short_name' => $request->noc_short_name,
                'noc_type' => $request->noc_type,
                'noc_payment_type' => $request->noc_payment_type,
                'target_days' => $request->target_days,
                'allow_repeat_application' => $request->allow_repeat_application,
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
                'status' => $request->status ?? $service->status,
            ]);

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Service master updated successfully.',
                'data' => $service
            ]);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Failed to update service master.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function service_master_delete(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'id' => 'required|integer|exists:service_masters,id',
            ]);

            DB::beginTransaction();

            $service = ServiceMaster::where('id', $request->id)->first();

            if (!$service) {

                DB::rollBack();

                return response()->json([
                    'status' => 0,
                    'message' => 'Service master not found.'
                ], 404);
            }

            $service->delete();

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Service Master deleted successfully.',
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

    public function fetch_service_details(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
            ]);

            $service = ServiceMaster::where('id', $request->service_id)->first();

            return response()->json([
                'status' => 1,
                'message' => 'Service details fetched successfully.',
                'data' => $service,
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

    public function fetch_all_services()
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $services = ServiceMaster::with('department:id,name')
                ->get(['id', 'service_title_or_description', 'department_id', 'noc_type', 'target_days', 'noc_payment_type', 'allow_repeat_application']);

            $services = $services->map(function ($service) {
                return [
                    'id' => $service->id,
                    'service_title_or_description' => $service->service_title_or_description,
                    'department_id' => $service->department_id,
                    'department_name' =>  $service->department->name,
                    'noc_type' => $service->noc_type,
                    'target_days' => $service->target_days,
                    'noc_payment_type' => $service->noc_payment_type,
                    'allow_repeat_application' => $service->allow_repeat_application,
                ];
            });

            return response()->json([
                'status' => 1,
                'message' => 'Services fetched successfully.',
                'data' => $services,
            ]);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
