<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\ServiceMaster;

class ServiceTemplateController extends Controller
{
    public function service_template_show(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Unauthenticated user.',
                ], 401);
            }

            $request->validate([
                'service_id' => 'required|integer|exists:service_masters,id',
            ]);

            DB::beginTransaction();

            $service = ServiceMaster::select('id', 'form_template')->findOrFail($request->integer('service_id'));

            $data = [
                'service_id'    => $service->id,
                'form_template' => $service->form_template,
            ];

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Service template fetched successfully.',
                'data'    => $data,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function service_template_store(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'service_id'    => 'required|integer|exists:service_masters,id',
                'form_template' => 'required|string|max:10485760',
            ]);

            DB::beginTransaction();

            $service = ServiceMaster::findOrFail($request->service_id);
            $service->form_template = $request->input('form_template');
            $service->save();

            $data = [
                'service_id'    => $service->id,
                'form_template' => $service->form_template,
            ];

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Service template saved successfully.',
                'service_id'    => $service->id,
                'form_template'    => $service->form_template,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

}
