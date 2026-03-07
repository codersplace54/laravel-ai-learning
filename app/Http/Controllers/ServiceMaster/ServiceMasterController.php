<?php

namespace App\Http\Controllers\ServiceMaster;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use App\Models\ServiceMaster;
use Illuminate\Support\Facades\Schema;
use App\Models\ServiceThirdPartyParam;
use App\Models\ServiceApprovalFlow;
use App\Models\User;
use App\Exports\ServiceMasterExport;
use App\Models\UnitDetail;
use App\Models\UserServiceApplication;
use Maatwebsite\Excel\Facades\Excel;


class ServiceMasterController extends Controller
{

    public function service_master_store(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $admin = Auth::user();

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
                'depends_on_services' => 'nullable|array',
                'depends_on_services.*' => 'string',
                'generate_id' => 'required|in:yes,no',
                'generate_pdf' => 'required|in:yes,no',
                'generated_id_format' => 'required|string|max:255',
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
                'verification_token' => 'nullable|string',
                'is_special' => 'nullable|in:yes,no',

                'service_mode' => 'nullable|in:native,third_party',
                'third_party_portal_name' => 'nullable|string',
                'third_party_redirect_url' => 'nullable|string',
                'third_party_method' => 'nullable|in:GET,POST',
                'third_party_return_url' => 'nullable|string',
                'third_party_status_api_url' => 'nullable|string',
                'third_party_payment_mode' => 'nullable|in:unified,external',
                'is_active' => 'nullable|integer',
                'fixed_expiry_date' => 'nullable|date',
                'egras_scheme_code' => 'nullable|string',
                'caf_depends' => 'nullable|in:yes,no',
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
                'depends_on_services' => json_encode($request->depends_on_services),
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
                'verification_token' => $request->verification_token,
                'is_special' => $request->is_special,
                'fixed_expiry_date' => $request->fixed_expiry_date,

                'service_mode' => $request->service_mode ?? "native",
                'third_party_portal_name' => $request->third_party_portal_name,
                'third_party_payment_mode' => $request->third_party_payment_mode ?? "unified",
                'third_party_redirect_url' => $request->third_party_redirect_url,
                'third_party_method' => $request->third_party_method ?? 'POST',
                'third_party_return_url' => $request->third_party_return_url,
                'third_party_status_api_url' => $request->third_party_status_api_url,
                'is_active' => $request->is_active ?? 1,
                'created_by' => $admin->email_id,
                'egras_scheme_code' => $request->egras_scheme_code ?? "1475-00-106-21-06",
                'caf_depends' => $request->caf_depends,
            ]);

            $service_master->depends_on_services = json_decode($service_master->depends_on_services, true);

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

            $admin = Auth::user();

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
                'depends_on_services' => 'nullable|array',
                'depends_on_services.*' => 'string',
                'generate_id' => 'required|in:yes,no',
                'generate_pdf' => 'required|in:yes,no',
                'generated_id_format' => 'required|string|max:255',
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
                'verification_token' => 'nullable|string',
                'is_special' => 'nullable|in:yes,no',
                'fixed_expiry_date' => 'nullable|date',

                'service_mode' => 'nullable|in:native,third_party',
                'third_party_portal_name' => 'nullable|string',
                'third_party_redirect_url' => 'nullable|string',
                'third_party_method' => 'nullable|in:GET,POST',
                'third_party_return_url' => 'nullable|string',
                'third_party_status_api_url' => 'nullable|string',
                'third_party_payment_mode' => 'nullable|in:unified,external',
                'is_active' => 'nullable|integer',
                'egras_scheme_code' => 'nullable|string',
                'caf_depends' => 'nullable|in:yes,no',
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
                'depends_on_services' => $request->depends_on_services ?? [],
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
                'verification_token' => $request->verification_token,
                'is_special' => $request->is_special,
                'fixed_expiry_date' => $request->fixed_expiry_date,

                'service_mode' => $request->service_mode,
                'third_party_portal_name' => $request->third_party_portal_name,
                'third_party_payment_mode' => $request->third_party_payment_mode ?? $service->third_party_payment_mode,
                'third_party_redirect_url' => $request->third_party_redirect_url,
                'third_party_method' => $request->third_party_method ?? $service->third_party_method,
                'third_party_return_url' => $request->third_party_return_url,
                'third_party_status_api_url' => $request->third_party_status_api_url,
                'is_active' => $request->is_active ?? $service->is_active,
                'updated_by' => $admin->email_id,
                'egras_scheme_code' => $request->egras_scheme_code ?? $service->egras_scheme_code ?? "1475-00-106-21-06",
                'caf_depends' => $request->caf_depends,
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
            $Service_third_party_param = ServiceThirdPartyParam::where('service_id', $request->id)->first();

            if (!$service) {

                DB::rollBack();

                return response()->json([
                    'status' => 0,
                    'message' => 'Service master not found.'
                ], 404);
            }

            $service->delete();

            if ($Service_third_party_param)
                $Service_third_party_param->delete();

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
                'department_name' => $service->department->name ?? null,
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

    public function fetch_all_services(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $user = Auth::user();

            $department_id = $request->department_id ?? null;
            $is_caf_filled = false;

            if ($user->user_type === 'individual') {
                $is_caf_filled = UnitDetail::where('user_id', $user->id)->exists();
            }

            $services = ServiceMaster::with([
                'department:id,name',
                'applications' => function ($query) use ($user) {
                    $query->where('user_id', $user->id)->select('id', 'service_id', 'status');
                },
                'third_party_param'
            ])
                ->select([
                    'id',
                    'service_title_or_description',
                    'department_id',
                    'noc_type',
                    'target_days',
                    'noc_payment_type',
                    'allow_repeat_application',
                    'service_mode',
                    'third_party_portal_name',
                    'third_party_redirect_url',
                    'third_party_return_url',
                    'third_party_status_api_url',
                    'created_by',
                    'updated_by',
                    'status',
                    'verification_token',
                    'is_special',
                    'caf_depends'
                ])
                ->when($department_id, function ($query) use ($department_id) {
                    $query->where('department_id', $department_id);
                })

                ->when($user->user_type === 'individual', function ($query) {
                    $query->where('status', 1);
                })
                ->get();


            $services = $services->map(function ($service) use ($user, $is_caf_filled) {
                $service_depends_and_caf_filled = ($service->caf_depends === 'yes') ? $is_caf_filled : true;

                $data = [
                    'id' => $service->id,
                    'service_title_or_description' => $service->service_title_or_description,
                    'department_id' => $service->department_id,
                    'department_name' =>  $service->department->name,
                    'noc_type' => $service->noc_type,
                    'target_days' => $service->target_days .' days',
                    'noc_payment_type' => $service->noc_payment_type,
                    'application_id' => $service->applications->first() ? $service->applications->first()->id : null,
                    'application_status' => $service->applications->first() ? $service->applications->first()->status : null,
                    'allow_repeat_application' => $service->allow_repeat_application,
                    'service_mode' => $service->service_mode,
                    'created_by' => $service->created_by,
                    'updated_by' => $service->updated_by,
                    'status' => $service->status,
                    'is_special' => $service->is_special,
                    'verification_token' => $service->verification_token,
                    'is_caf_filled' => $service_depends_and_caf_filled,

                ];

                if ($service->service_mode === 'third_party') {
                    $params = collect($service->third_party_param);
                    $post_params = [];

                    foreach ($params as $param) {
                        $param_name = $param->param_name;
                        $value = null;

                        if (isset($user->{$param_name}) && !is_null($user->{$param_name})) {
                            $value = $user->{$param_name};
                        } elseif (!empty($param->default_source_table) && !empty($param->default_source_column)) {
                            try {
                                $query = DB::table($param->default_source_table);

                                if ($param->default_source_table === 'users') {
                                    $query->where('id', $user->id);
                                } else {
                                    $query->where('user_id', $user->id);
                                }

                                $value = $query->value($param->default_source_column);
                            } catch (\Exception $e) {
                                $value = null;
                            }
                        } elseif (!is_null($param->default_value)) {
                            $value = $param->default_value;
                        }

                        if (!is_null($value)) {
                            $post_params[$param_name] = $value;
                        }
                    }

                    if (!empty($service->verification_token)) {
                        $post_params['verification_token'] = base64_encode($service->verification_token);
                    }

                    if (!empty($service->third_party_return_url)) {
                        $post_params['returnUrl'] = $service->third_party_return_url;
                    }

                    $data['thirdPartyPortal'] = [
                        'thirdPartyPortalName' => $service->third_party_portal_name,
                        'thirdPartyRedirectUrl' => $service->third_party_redirect_url,
                        'thirdPartyReturnUrl' => $service->third_party_return_url,
                        'thirdPartyStatusApiUrl' => $service->third_party_status_api_url,
                        'thirdPartyPostParams' => $post_params,
                    ];
                }



                return $data;
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

    public function get_default_source_value(Request $request)
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

            if (!filter_var($value, FILTER_VALIDATE_URL) && preg_match('/\.[a-zA-Z0-9]+$/', $value)) {
                $value = asset('storage/' . ltrim($value, '/'));
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

    public function service_third_party_params_store(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $admin = Auth::user();

            $request->validate([
                'params' => 'required|array',
                'params.*.service_id' => 'required|integer|exists:service_masters,id',
                'params.*.param_name' => 'required|string',
                'params.*.param_type' => 'nullable|string|in:request,response',
                'params.*.param_required' => 'nullable|integer|in:0,1',
                'params.*.default_value' => 'nullable|string',
                'params.*.default_source_table' => 'nullable|string',
                'params.*.default_source_column' => 'nullable|string',
                'params.*.data_source' => 'nullable|string|in:user_input,system_generated',
                'params.*.description' => 'nullable|string',
            ]);

            DB::beginTransaction();

            $service_third_party_params = [];

            foreach ($request->params as $param) {

                $new_param = ServiceThirdPartyParam::create([
                    'service_id' => $param['service_id'] ?? null,
                    'param_name' => $param['param_name'] ?? null,
                    'param_type' => $param['param_type'] ?? null,
                    'param_required' => $param['param_required'] ?? null,
                    'default_value' => $param['default_value'] ?? null,
                    'default_source_table' => $param['default_source_table'] ?? null,
                    'default_source_column' => $param['default_source_column'] ?? null,
                    'data_source' => $param['data_source'] ?? null,
                    'description' => $param['description'] ?? null,
                    'created_by' => $admin->email_id,
                ]);

                $service_third_party_params[] = $new_param;
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

    public function service_third_party_params_update(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $admin = Auth::user();

            $request->validate([
                'params' => 'required|array',
                'params.*.id' => 'required|integer|exists:service_third_party_params,id',
                'params.*.service_id' => 'required|integer|exists:service_masters,id',
                'params.*.param_name' => 'required|string',
                'params.*.param_type' => 'nullable|string|in:request,response',
                'params.*.param_required' => 'nullable|integer|in:0,1',
                'params.*.default_value' => 'nullable|string',
                'params.*.default_source_table' => 'nullable|string',
                'params.*.default_source_column' => 'nullable|string',
                'params.*.data_source' => 'nullable|string|in:user_input,system_generated',
                'params.*.description' => 'nullable|string',
            ]);

            DB::beginTransaction();

            $updated_params = [];

            foreach ($request->params as $param) {
                $existing_param = ServiceThirdPartyParam::find($param['id']);

                if ($existing_param) {
                    $existing_param->update([
                        'service_id' => $param['service_id'],
                        'param_name' => $param['param_name'],
                        'param_type' => $param['param_type'] ?? null,
                        'param_required' => $param['param_required'] ?? null,
                        'default_value' => $param['default_value'] ?? null,
                        'default_source_table' => $param['default_source_table'] ?? null,
                        'default_source_column' => $param['default_source_column'] ?? null,
                        'data_source' => $param['data_source'] ?? null,
                        'description' => $param['description'] ?? null,
                        'updated_by' => $admin->email_id,
                    ]);

                    $updated_params[] = $existing_param;
                }
            }

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Service third party params updated successfully.',
                'data' => $updated_params
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Failed to update Service third party params.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function service_third_party_params_view(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id' => 'required|integer',
            ]);

            $third_party_parameters = ServiceThirdPartyParam::where('service_id', $request->service_id)->get();

            if ($third_party_parameters->isEmpty()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No third party parameters available for this service.',
                ], 404);
            }

            return response()->json([
                'status' => 1,
                'message' => 'Service details fetched successfully.',
                'data' => $third_party_parameters
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

    public function service_third_party_params_delete(Request $request)
    {

        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'id' => 'required|integer|exists:service_third_party_params,id',
            ]);

            DB::beginTransaction();

            $service = ServiceThirdPartyParam::where('id', $request->id)->first();

            if (!$service) {

                DB::rollBack();

                return response()->json([
                    'status' => 0,
                    'message' => 'Service third party params not found.'
                ], 404);
            }

            $service->delete();

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Service third party params deleted successfully.',
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

    public function update_service_status($id)
    {


        try {

            DB::beginTransaction();

            $service = ServiceMaster::findOrFail($id);

            $service->status = $service->status === 1 ? 0 : 1;
            $service->save();

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Status updated successfully.',
                'updated_status' => $service->status,
            ]);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while updating status.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function third_party_apply($id)
    {
        try {


            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $user = Auth::user();

            $service = ServiceMaster::with('third_party_param')->find($id);

            if (!$service || $service->service_mode !== 'third_party') {
                return response()->json(['status' => 0, 'message' => 'Invalid third-party service.'], 404);
            }

            $params = collect($service->third_party_param);
            $post_params = [];

            $post_params['user_id'] = $user->id;
            $post_params['bin'] = $user->bin ?? null;
            $post_params['service_id'] = $service->id;
            $phone = $user->mobile_no ?? null;

            foreach ($params as $param) {
                $paramName = $param->param_name;
                $value = null;

                if (isset($user->{$paramName}) && !is_null($user->{$paramName})) {
                    $value = $user->{$paramName};
                } elseif (!empty($param->default_source_table) && !empty($param->default_source_column)) {
                    try {
                        $query = DB::table($param->default_source_table);

                        if ($param->default_source_table === 'users') {
                            $query->where('id', $user->id);
                        } else {
                            $query->where('user_id', $user->id);
                        }

                        $value = $query->value($param->default_source_column);
                    } catch (\Exception $e) {
                        $value = null;
                    }
                } elseif (!is_null($param->default_value)) {
                    $value = $param->default_value;
                }

                if (!is_null($value)) {
                    $post_params[$paramName] = $value;
                }
            }

            if (!empty($service->verification_token)) {
                $secret_key = $service->verification_token;
                $application_date = now()->format('d-m-Y H:i:s');
                $payload = "phone={$phone}&application_date={$application_date}";

                $hmac_hash = hash_hmac('sha256', $payload, $secret_key);

                $encoded_token = base64_encode($secret_key . '|' . $phone . '|' . $application_date);

                $post_params['application_date'] = $application_date;
                $post_params['verification_Payload'] = $payload;
                $post_params['verification_HMAC'] = $hmac_hash;
                $post_params['verification_token'] = $encoded_token;
            }

            if (!empty($service->third_party_return_url)) {
                $post_params['returnUrl'] = $service->third_party_return_url;
            }

            $method = strtoupper($service->third_party_method ?? 'POST');

            $html = '<html><body onload="document.forms[0].submit()">';
            $html .= '<form method="' . e($method) . '" action="' . e($service->third_party_redirect_url) . '">';

            foreach ($post_params as $key => $value) {
                $html .= '<input type="hidden" name="' . e($key) . '" value="' . e($value) . '">';
            }

            $html .= '</form>';
            $html .= '<p>Redirecting to third-party portal...</p>';
            $html .= '</body></html>';

            return response($html);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    public function export_services()
    {
        try {


            return Excel::download(new ServiceMasterExport, 'service_masters.xlsx');
        } catch (\Exception $e) {


            return response()->json([
                'success' => false,
                'message' => 'Failed to generate Excel file',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function get_all_services(Request $request)
    {


        try {

            $query = ServiceMaster::select(
                'id',
                'service_title_or_description as service_name',
            )
                ->where('status', 1);

            if ($request->filled('department_id')) {
                $query->where('department_id', $request->department_id);
            }

            $services = $query
                ->orderBy('id', 'asc')
                ->get();

            return response()->json([
                'status' => 1,
                'data' => $services
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Failed to retrieve services.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
