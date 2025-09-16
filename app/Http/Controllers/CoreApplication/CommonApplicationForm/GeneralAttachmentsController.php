<?php

namespace App\Http\Controllers\CoreApplication\CommonApplicationForm;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\GeneralAttachment;

class GeneralAttachmentsController extends Controller
{

    public function general_attachment_store_or_update(Request $request)
    {

        try {


            $user = Auth::user();
            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $general_attachment = GeneralAttachment::where('user_id', $user->id)->first();

            $rules = [
                'general_self_certification_form' => [
                    (!$general_attachment || !$general_attachment->general_self_certification_form) ? 'required' : 'nullable',
                    'file',
                    'mimes:pdf,jpg,jpeg,png',
                    'max:2048'
                ],
                'do_you_have_trees_in_the_land_for_industry' => 'required|in:YES,NO',
                'type_of_tree' => 'required_if:do_you_have_trees_in_the_land_for_industry,YES|in:EXEMPTED,NON_EXEMPTED',
                'self_certificate_format_3A' => 'required_if:type_of_tree,EXEMPTED|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'tree_registration_certificate' => 'required_if:type_of_tree,NON_EXEMPTED|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'owner_pan_pdf' => [
                    (!$general_attachment || !$general_attachment->owner_pan_pdf) ? 'required' : 'nullable',
                    'file',
                    'mimes:pdf,jpg,jpeg,png',
                    'max:2048'
                ],
                'owner_pan_number' => [
                    (!$general_attachment || !$general_attachment->owner_pan_pdf) ? 'required' : 'nullable',
                    'string',
                    'max:20'
                ],

                'owner_aadhar_pdf' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'owner_aadhar_number' => 'required_with:owner_aadhar_pdf|string|max:20',

                'udyog_aadhar' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'udyog_aadhar_number' => 'required_with:udyog_aadhar|string|max:20',
                'udyog_aadhar_registration_date' => 'required_with:udyog_aadhar|date',

                'gst_certificate_pdf' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'gst_number' => 'required_with:gst_certificate_pdf|string|max:20',

                'combined_plan_document' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',

                'unit_land_details_pdf' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'unit_registaration_details_pdf' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'unit_property_tax_clearance_certificate_pdf' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'unit_process_flow_chart_diagram_or_write_up_pdf' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'detailed_project_report_pdf' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'other_supporting_docuement1_pdf' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',

                'remove_self_certificate_format_3A' => 'nullable|in:delete',
                'remove_tree_registration_certificate' => 'nullable|in:delete',
                'remove_owner_aadhar_pdf' => 'nullable|in:delete',
                'remove_udyog_aadhar' => 'nullable|in:delete',
                'remove_gst_certificate_pdf' => 'nullable|in:delete',
                'remove_combined_plan_document' => 'nullable|in:delete',
                'remove_unit_land_details_pdf' => 'nullable|in:delete',
                'remove_unit_registaration_details_pdf' => 'nullable|in:delete',
                'remove_unit_property_tax_clearance_certificate_pdf' => 'nullable|in:delete',
                'remove_unit_process_flow_chart_diagram_or_write_up_pdf' => 'nullable|in:delete',
                'remove_detailed_project_report_pdf' => 'nullable|in:delete',
                'remove_other_supporting_docuement1_pdf' => 'nullable|in:delete'
            ];
            if ($request->save_data != 1) {
                $request->validate($rules);
            }


            DB::beginTransaction();


            $file_upload_fields = [
                'general_self_certification_form',
                'self_certificate_format_3A',
                'tree_registration_certificate',
                'owner_pan_pdf',
                'owner_aadhar_pdf',
                'udyog_aadhar',
                'gst_certificate_pdf',
                'combined_plan_document',
                'unit_land_details_pdf',
                'unit_registaration_details_pdf',
                'unit_property_tax_clearance_certificate_pdf',
                'unit_process_flow_chart_diagram_or_write_up_pdf',
                'detailed_project_report_pdf',
                'other_supporting_docuement1_pdf',
            ];


            $file_upload_delete_fields = [
                'self_certificate_format_3A',
                'tree_registration_certificate',
                'owner_aadhar_pdf',
                'udyog_aadhar',
                'gst_certificate_pdf',
                'combined_plan_document',
                'unit_land_details_pdf',
                'unit_registaration_details_pdf',
                'unit_property_tax_clearance_certificate_pdf',
                'unit_process_flow_chart_diagram_or_write_up_pdf',
                'detailed_project_report_pdf',
                'other_supporting_docuement1_pdf',
            ];


            $related_fields = [
                'owner_aadhar_pdf' => ['owner_aadhar_number'],
                'udyog_aadhar' => ['udyog_aadhar_number', 'udyog_aadhar_registration_date'],
                'gst_certificate_pdf' => ['gst_number'],
            ];


            $paths = [];


            // This part will execute for Store/ Update, If new file came it will delete then store the new one ...
            foreach ($file_upload_fields as $field) {
                if ($request->hasFile($field)) {
                    if ($general_attachment && $general_attachment->$field) {
                        Storage::disk('public')->delete($general_attachment->$field);
                    }
                    $file = $request->file($field);
                    $filename = $field . '.' . $file->getClientOriginalExtension();
                    $paths[$field] = $file->storeAs("uploads/$user->id/general_attachments", $filename, 'public');
                } else {
                    $paths[$field] = $general_attachment ? $general_attachment->$field : null;
                }
            }


            // This part will execute if general_attachment exists and updation will be done from here ...
            if ($general_attachment) {

                $update_data = [];

                foreach ($file_upload_fields as $field) {
                    if ($request->hasFile($field)) {
                        $update_data[$field] = $paths[$field];
                    }
                }

                if ($request->filled('type_of_tree')) {
                    $update_data['type_of_tree'] = $request->type_of_tree;
                }

                if ($request->filled('owner_pan_number')) {
                    $update_data['owner_pan_number'] = $request->owner_pan_number;
                }

                if ($request->filled('owner_aadhar_number')) {
                    $update_data['owner_aadhar_number'] = $request->owner_aadhar_number;
                }

                if ($request->filled('udyog_aadhar_number')) {
                    $update_data['udyog_aadhar_number'] = $request->udyog_aadhar_number;
                }

                if ($request->filled('gst_number')) {
                    $update_data['gst_number'] = $request->gst_number;
                }

                if ($request->filled('udyog_aadhar_registration_date')) {
                    $update_data['udyog_aadhar_registration_date'] = $request->udyog_aadhar_registration_date;
                }



                foreach ($file_upload_delete_fields as $field) {

                    if ($request->input("remove_$field") === 'delete' && !$request->hasFile($field)) {

                        if ($general_attachment->$field) {
                            Storage::disk('public')->delete($general_attachment->$field);
                        }

                        $update_data[$field] = null;

                        if (isset($related_fields[$field])) {
                            foreach ($related_fields[$field] as $related) {
                                $update_data[$related] = null;
                            }
                        }
                    }
                }


                $general_attachment->update($update_data);
            } else {
                $general_attachment = GeneralAttachment::create([
                    'user_id' => $user->id,
                    'general_self_certification_form' => $paths['general_self_certification_form'],
                    'do_you_have_trees_in_the_land_for_industry' => $request->do_you_have_trees_in_the_land_for_industry,
                    'type_of_tree' => $request->type_of_tree,
                    'self_certificate_format_3A' => $paths['self_certificate_format_3A'],
                    'tree_registration_certificate' => $paths['tree_registration_certificate'],
                    'owner_pan_pdf' => $paths['owner_pan_pdf'],
                    'owner_pan_number' => $request->owner_pan_number,
                    'owner_aadhar_pdf' => $paths['owner_aadhar_pdf'],
                    'owner_aadhar_number' => $request->owner_aadhar_number,
                    'udyog_aadhar' => $paths['udyog_aadhar'],
                    'udyog_aadhar_number' => $request->udyog_aadhar_number,
                    'gst_certificate_pdf' => $paths['gst_certificate_pdf'],
                    'gst_number' => $request->gst_number,
                    'udyog_aadhar_registration_date' => $request->udyog_aadhar_registration_date,
                    'combined_plan_document' => $paths['combined_plan_document'],
                    'unit_land_details_pdf' => $paths['unit_land_details_pdf'],
                    'unit_registaration_details_pdf' => $paths['unit_registaration_details_pdf'],
                    'unit_property_tax_clearance_certificate_pdf' => $paths['unit_property_tax_clearance_certificate_pdf'],
                    'unit_process_flow_chart_diagram_or_write_up_pdf' => $paths['unit_process_flow_chart_diagram_or_write_up_pdf'],
                    'detailed_project_report_pdf' => $paths['detailed_project_report_pdf'],
                    'other_supporting_docuement1_pdf' => $paths['other_supporting_docuement1_pdf'],
                ]);
            }

            $general_attachment = $this->get_file_urls(
                $general_attachment,
                [
                    'general_self_certification_form',
                    'self_certificate_format_3A',
                    'tree_registration_certificate',
                    'owner_pan_pdf',
                    'owner_aadhar_pdf',
                    'udyog_aadhar',
                    'gst_certificate_pdf',
                    'combined_plan_document',
                    'unit_land_details_pdf',
                    'unit_registaration_details_pdf',
                    'unit_property_tax_clearance_certificate_pdf',
                    'unit_process_flow_chart_diagram_or_write_up_pdf',
                    'detailed_project_report_pdf',
                    'other_supporting_docuement1_pdf',
                ]
            );

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'General attachment saved successfully.',
                'data' => $general_attachment,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {


            DB::rollBack();

            Log::error('Error saving general attachment: ' . $e->getMessage());

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error_message' => $e->getMessage()
            ], 500);
        }
    }


    public function general_attachment_view()
    {

        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Unauthenticated user.'
                ], 401);
            }

            $general_attachment = GeneralAttachment::where('user_id', $user->id)->first();

            if (!$general_attachment) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No general attachment found for this user.'
                ], 404);
            }

            $general_attachment = $this->get_file_urls(
                $general_attachment,
                [
                    'general_self_certification_form',
                    'self_certificate_format_3A',
                    'tree_registration_certificate',
                    'owner_pan_pdf',
                    'owner_aadhar_pdf',
                    'udyog_aadhar',
                    'gst_certificate_pdf',
                    'combined_plan_document',
                    'unit_land_details_pdf',
                    'unit_registaration_details_pdf',
                    'unit_property_tax_clearance_certificate_pdf',
                    'unit_process_flow_chart_diagram_or_write_up_pdf',
                    'detailed_project_report_pdf',
                    'other_supporting_docuement1_pdf',
                ]
            );


            return response()->json([
                'status' => 1,
                'message' => 'General attachment fetched successfully.',
                'data' => $general_attachment,
            ], 200);
        } catch (\Exception $e) {


            Log::error('Error fetching general attachment: ' . $e->getMessage());

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching.',
            ], 500);
        }
    }


    private function get_file_urls($data, $fields)
    {

        $array = $data->toArray();


        foreach ($fields as $field) {


            if (!empty($array[$field])) {


                $array[$field] = asset('storage/' . $array[$field]);
            }
        }


        return $array;
    }

    public function get_user_caf_generalAttachment_details(Request $request)
    {

        try {


            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Unauthenticated user.'
                ], 401);
            }

            $request->validate([
                'user_id' => 'required|exists:users,id',
            ]);

            $general_attachment = GeneralAttachment::where('user_id', $request->user_id)->first();

            if (!$general_attachment) {
                return response()->json([
                    'status' => 0,
                    'message' => 'No general attachment found for this user.'
                ], 404);
            }

            $general_attachment = $this->get_file_urls(
                $general_attachment,
                [
                    'general_self_certification_form',
                    'self_certificate_format_3A',
                    'tree_registration_certificate',
                    'owner_pan_pdf',
                    'owner_aadhar_pdf',
                    'udyog_aadhar',
                    'gst_certificate_pdf',
                    'combined_plan_document',
                    'unit_land_details_pdf',
                    'unit_registaration_details_pdf',
                    'unit_property_tax_clearance_certificate_pdf',
                    'unit_process_flow_chart_diagram_or_write_up_pdf',
                    'detailed_project_report_pdf',
                    'other_supporting_docuement1_pdf',
                ]
            );


            return response()->json([
                'status' => 1,
                'message' => 'General attachment fetched successfully.',
                'data' => $general_attachment,
            ], 200);
        } catch (\Exception $e) {


            Log::error('Error fetching general attachment: ' . $e->getMessage());

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching.',
            ], 500);
        }
    }
}
