<?php

namespace App\Http\Controllers\ServiceMaster;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\ServiceMaster;
use Illuminate\Support\Facades\Schema;
use App\Models\ServiceThirdPartyParam;


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
                'noc_payment_type' => 'nullable|in:Estimated,Hardcoded,Calculated',
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
                'status' => 'nullable|integer',

                'service_mode' => 'nullable|in:native,third_party',
                'third_party_portal_name' => 'nullable|string',
                'third_party_redirect_url' => 'nullable|string',
                'third_party_return_url' => 'nullable|string',
                'third_party_status_api_url' => 'nullable|string',
                'third_party_payment_mode' => 'nullable|in:unified,external',
                'is_active' => 'nullable|integer'
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

                'service_mode' => $request->service_mode ?? "native",
                'third_party_portal_name' => $request->third_party_portal_name,
                'third_party_redirect_url' => $request->third_party_redirect_url,
                'third_party_return_url' => $request->third_party_return_url,
                'third_party_status_api_url' => $request->third_party_status_api_url,
                'is_active' => $request->is_active ?? 1
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
                'noc_payment_type' => 'nullable|in:Estimated,Hardcoded,Calculated',
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
                'status' => 'nullable|integer',

                'service_mode' => 'nullable|in:native,third_party',
                'third_party_portal_name' => 'nullable|string',
                'third_party_redirect_url' => 'nullable|string',
                'third_party_return_url' => 'nullable|string',
                'third_party_status_api_url' => 'nullable|string',
                'third_party_payment_mode' => 'nullable|in:unified,external',
                'is_active' => 'nullable|integer'
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

                'service_mode' => $request->service_mode,
                'third_party_portal_name' => $request->third_party_portal_name,
                'third_party_redirect_url' => $request->third_party_redirect_url,
                'third_party_return_url' => $request->third_party_return_url,
                'third_party_status_api_url' => $request->third_party_status_api_url,
                'is_active' => $request->is_active ?? $service->is_active,
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
            $third_party_parameters = ServiceThirdPartyParam::where('service_id', $request->service_id)->first();

            return response()->json([
                'status' => 1,
                'message' => 'Service details fetched successfully.',
                'data' => $service,
                'third_party_parameters' => $third_party_parameters
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

            $user = Auth::user();

            $services = ServiceMaster::with(['department:id,name', 'applications' => function ($query) use ($user) {
                $query->where('user_id', $user->id)->select('id', 'service_id', 'status');
            }])->get(['id', 'service_title_or_description', 'department_id', 'noc_type', 'target_days', 'noc_payment_type', 'allow_repeat_application', 'service_mode', 'third_party_portal_name']);

            $services = $services->map(function ($service) {
                return [
                    'id' => $service->id,
                    'service_title_or_description' => $service->service_title_or_description,
                    'department_id' => $service->department_id,
                    'department_name' =>  $service->department->name,
                    'noc_type' => $service->noc_type,
                    'target_days' => $service->target_days,
                    'noc_payment_type' => $service->noc_payment_type,
                    'application_id' => $service->applications->first() ? $service->applications->first()->id : null,
                    'application_status' => $service->applications->first() ? $service->applications->first()->status : null,
                    'allow_repeat_application' => $service->allow_repeat_application,
                    'service_mode' => $service->service_mode,
                    'third_party_portal_name' => $service->third_party_portal_name,
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

    public function getDefaultSourceValue(Request $request)

    {


        $request->validate([
            'user_id' => 'required|integer',
            'default_source_table' => 'required|string',
            'default_source_column' => 'required|string',
        ]);

        $userId = $request->input('user_id');
        $table = $request->input('default_source_table');
        $column = $request->input('default_source_column');

        // Optional: Whitelist for security
        // $allowedTables = ['unit_details'];
        // $allowedColumns = ['unit_name'];

        // if (!in_array($table, $allowedTables) || !in_array($column, $allowedColumns)) {
        //     return response()->json(['error' => 'Invalid table or column'], 400);
        // }

        if (!Schema::hasTable($table)) {
            return response()->json(['error' => 'Table does not exist'], 400);
        }

        if (!Schema::hasColumn($table, $column)) {
            return response()->json(['error' => 'Column does not exist'], 400);
        }

        try {
            $value = DB::table($table)
                ->where('user_id', $userId)
                ->value($column);

            if (is_null($value)) {
                return response()->json(['message' => 'No data found for this user_id'], 404);
            }

            return response()->json([
                'value' => $value,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Server Error: ' . $e->getMessage()
            ], 500);
        }
    }

    public function store_service_third_party_params(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
                'param_name'            => 'nullable|string',
                'param_type'     => 'nullable|string|in:request,response',
                'param_required' => 'nullable|integer|in:0,1',
                'default_value' => 'nullable|string',
                'default_source_table' => 'nullable|string',
                'default_source_column' => 'nullable|string',
                'data_source'     => 'nullable|string|in:user_input,system_generated',
                'description' => 'nullable|string',
            ]);

            DB::beginTransaction();

            $service_third_party_params = ServiceThirdPartyParam::where('service_id', $request->service_id)->first();

            if ($service_third_party_params) {

                $service_third_party_params->update([
                    'service_id'         => $request->service_id,
                    'param_name'         => $request->param_name,
                    'param_type'         => $request->param_type,
                    'param_required'     => $request->param_required,
                    'default_value'      => $request->default_value,
                    'default_source_table'   => $request->default_source_table,
                    'default_source_column' => $request->default_source_column,
                    'data_source'           => $request->data_source,
                    'description'        => $request->description,
                ]);
            } else {

                $service_third_party_params = ServiceThirdPartyParam::create([
                    'service_id'        => $request->service_id,
                    'param_name'        => $request->param_name,
                    'param_type'        => $request->param_type,
                    'param_required'    => $request->param_required,
                    'default_value'     => $request->default_value,
                    'default_source_table'   => $request->default_source_table,
                    'default_source_column' => $request->default_source_column,
                    'data_source'       => $request->data_source,
                    'description'       => $request->description
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Service third party params created successfully.',
                'data' => $service_third_party_params
            ]);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Failed to create Service third party params.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
