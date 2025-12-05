<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\ServiceMaster;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Models\ApplicationWorkflowAssignment;
use App\Models\ApplicationWorkflowHistory;
use App\Models\ServiceApprovalFlow;
use App\Models\UserServiceApplication;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class CertificateController extends Controller
{
    public function certificate_variables_list()
    {
        try {
            if (!Auth::check()) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $variables = [
                'add_watermark',
                'form_title',
                'rules_ref',
                'government',
                'issuing_office',
                'verify_portal_url',
                'license_id',
                'issue_date',
                'principal_employer',
                'guardian_name',
                'address',
                'work_location',
                'registration_no',
                'registration_date',
                'valid_upto',
                'max_contract_labour',
                'fee_paid',
                'security_deposit',
                'designation',
                'signature_note',
                'user_name',
                'user_id',
                'qr_code',
                'field_1',
                'field_2',
            ];

            return response()->json([
                'status'  => 1,
                'message' => 'Variables list fetched.',
                'data'    => $variables,
            ]);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function user_certificate_view(Request $request)
    {

        try {

            $request->validate([
                'application_id' => 'required|integer|exists:user_service_applications,id',
            ]);

            $application = UserServiceApplication::where("id", $request->application_id)->with([
                'user',
                'service:id,form_template',
                'service.department.department_user',
            ])->first();

            $name = $application->user->authorized_person_name ?? '—';
            $verifyUrl = 'https://swaagat.tripura.gov.in/verify';

            $qrPayload = "Name: {$name}\nApplication Id: {$application->id}\n{$verifyUrl}";
            $qrSvg = QrCode::format('svg')->size(220)->margin(0)->generate($qrPayload);
            $qrDataUri = 'data:image/svg+xml;base64,' . base64_encode($qrSvg);

            $data = [
                'add_watermark'     => 'no',
                'form_title'        => 'FORM VI',
                'rules_ref'         => '[ Under rule 19(1) of the Tripura Contract Labour (Regulation and Abolition) Rules, 1978; ]',
                'government'        => 'Government of Tripura',
                'issuing_office'    => 'Office of the Licensing Officer',
                'verify_portal_url' => 'https://swaagat.tripura.gov.in',

                'license_id'          => $application->id ?? null,
                'issue_date'          => $application->application_date ? Carbon::parse($application->application_date)->format('d-m-Y') : null,
                'principal_employer'  => $application->user->authorized_person_name ?? null,
                'guardian_name'       => $application->user->management_details->owner_details_father_name ?? null,
                'address'             => $application->user->management_details->owner_details_residential_details ?? null,
                'work_location'       => null,
                'registration_no'     => $application->id ?? null,
                'registration_date'   => $application->application_date ? Carbon::parse($application->application_date)->format('d-m-Y') : null,
                'valid_upto'          => $application->NOC_expiry_date ? Carbon::parse($application->NOC_expiry_date)->format('d-m-Y') : null,
                'max_contract_labour' => $application->max_contract_labour ?? null,
                'fee_paid'            => $application->final_fee ?? null,
                'security_deposit'    => null,
                'designation'         => $application->service->department->department_user->designation ?? null,
                'signature_note'      => 'Not Required',
                'user_name'           => $user->authorized_person_name ?? null,
                'user_id'             => $application->user->id,
                'qr_code'             => $qrDataUri,
                'filed_1'             => null,
                'field_2'             => null,
            ];

            return response()->json([
                'status'  => 1,
                'message' => 'Certificate data fetched successfully.',
                'data'    => $data,
            ]);
        } catch (\Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function user_certificate_generate(Request $request)
    {
        $request->validate([
            'application_id' => 'required|integer|exists:user_service_applications,id',
            'add_watermark'  => 'nullable|in:yes,no',
        ]);

        try {
            $application = UserServiceApplication::where('id', $request->application_id)
                ->with([
                    'user',
                    'service:id,form_template',
                    'service.department.department_user',
                ])
                ->firstOrFail();

            $template = (string) data_get($application, 'service.form_template', '');
            if ($template === '') {
                return response()->json([
                    'status'  => 0,
                    'message' => 'No form template configured for this service.',
                ], 422);
            }

            $template = html_entity_decode($template, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $template = str_replace("\xC2\xA0", ' ', $template);
            $template = '<style>@page { margin: 18mm 18mm 18mm 18mm; }</style>' . $template;

            $user        = $application->user;
            $name        = $user->authorized_person_name ?? $user->name ?? '—';
            $verify_url  = 'https://swaagat.tripura.gov.in/verify';

            $issue_for_qr   = $request->issue_date   ?? '';
            $valid_for_qr   = $request->valid_upto   ?? '';
            $license_for_qr = $request->license_id   ?? '';

            $qr_payload = "Name: {$name}\n"
                . "License ID: {$license_for_qr}\n"
                . "Issue Date: {$issue_for_qr}\n"
                . "Valid Upto: {$valid_for_qr}\n"
                . "{$verify_url}";

            $qr_svg      = QrCode::format('svg')->size(220)->margin(0)->generate($qr_payload);
            $qr_data_uri = 'data:image/svg+xml;base64,' . base64_encode($qr_svg);

            $data = [
                'form_title'          => $request->form_title ?? null,
                'rules_ref'           => $request->rules_ref ?? null,
                'government'          => $request->government ?? null,
                'issuing_office'      => $request->issuing_office ?? null,
                'verify_portal_url'   => $request->verify_portal_url ?? null,

                'license_id'          => $request->license_id ?? null,
                'issue_date'          => $request->issue_date ?? null,
                'principal_employer'  => $request->principal_employer ?? null,
                'guardian_name'       => $request->guardian_name ?? null,
                'address'             => $request->address ?? null,
                'work_location'       => $request->work_location ?? null,
                'registration_no'     => $request->registration_no ?? null,
                'registration_date'   => $request->registration_date ?? null,
                'valid_upto'          => $request->valid_upto ?? null,
                'max_contract_labour' => $request->max_contract_labour ?? null,
                'fee_paid'            => $request->fee_paid ?? null,
                'security_deposit'    => $request->security_deposit ?? null,
                'designation'         => $request->designation ?? null,
                'signature_note'      => $request->signature_note ?? 'Not Required',
                'user_name'           => $user->name,
                'user_id'             => $user->id,
                'qr_code'             => $qr_data_uri,
                'filed_1'             => $request->filed_1 ?? null,
                'filed_2'             => $request->filed_2 ?? null,
            ];

            $filled = preg_replace_callback(
                '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/',
                function ($m) use ($data) {
                    $key = $m[1];
                    $val = $data[$key] ?? '';

                    if ($key === 'qr_code') {
                        if (!is_scalar($val) || $val === '') {
                            return '';
                        }

                        $src = e((string) $val);

                        return '<img src="' . $src . '" '
                            . 'alt="QR Code" '
                            . 'width="120" height="120" '
                            . 'style="display:inline-block; vertical-align:middle;" />';
                    }

                    return e(is_scalar($val) ? (string) $val : '');
                },
                $template
            );

            $logo_path = storage_path('app/public/images/logo/state_emblem_english.jpg');

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($filled)
                ->setPaper('a4', 'portrait')
                ->setOptions([
                    'isHtml5ParserEnabled' => true,
                    'isRemoteEnabled'      => true,
                    'defaultFont'          => 'DejaVu Sans',
                    'dpi'                  => 150,
                ]);

            // border start
            $dompdf = $pdf->getDomPDF();
            $canvas = $dompdf->getCanvas();

            $w  = $canvas->get_width();
            $h  = $canvas->get_height();
            $mm = 72 / 25.4;

            $outer_margin_mm = 10.0;
            $gap_mm          = 3.0;

            $outer = $outer_margin_mm * $mm;
            $inner = ($outer_margin_mm + $gap_mm) * $mm;

            $color_outer = [123 / 255, 30 / 255, 30 / 255];
            $color_inner = $color_outer;

            $border_obj = $canvas->open_object();

            $line_width_outer = 1.6;
            $canvas->line($outer,        $outer,        $w - $outer, $outer,        $color_outer, $line_width_outer);
            $canvas->line($outer,        $h - $outer,   $w - $outer, $h - $outer,   $color_outer, $line_width_outer);
            $canvas->line($outer,        $outer,        $outer,      $h - $outer,   $color_outer, $line_width_outer);
            $canvas->line($w - $outer,   $outer,        $w - $outer, $h - $outer,   $color_outer, $line_width_outer);

            $line_width_inner = 0.8;
            $canvas->line($inner,        $inner,        $w - $inner, $inner,        $color_inner, $line_width_inner);
            $canvas->line($inner,        $h - $inner,   $w - $inner, $h - $inner,   $color_inner, $line_width_inner);
            $canvas->line($inner,        $inner,        $inner,      $h - $inner,   $color_inner, $line_width_inner);
            $canvas->line($w - $inner,   $inner,        $w - $inner, $h - $inner,   $color_inner, $line_width_inner);

            $canvas->close_object();
            $canvas->add_object($border_obj, 'all');
            // border end

            // watermark start
            if ($request->add_watermark === 'yes' && is_file($logo_path)) {
                $img_w = 354;
                $img_h = 354;
                $x     = ($w - $img_w) / 2;
                $y     = ($h - $img_h) / 2;

                $wm_obj = $canvas->open_object();

                if (method_exists($canvas, 'save')) {
                    $canvas->save();
                }

                if (method_exists($canvas, 'set_opacity')) {
                    $canvas->set_opacity(0.07, 'Multiply');
                }

                $canvas->image($logo_path, $x, $y, $img_w, $img_h);

                if (method_exists($canvas, 'restore')) {
                    $canvas->restore();
                }

                $canvas->close_object();
                $canvas->add_object($wm_obj, 'all');
            }
            // watermark end

            $filename = uniqid('license_') . '.pdf';
            $path     = "uploads/{$user->id}/application/{$filename}";

            \Storage::disk('public')->put($path, $pdf->output());
            $application->update(['NOC_certificate' => $path]);
            $application->NOC_certificate = asset('storage/' . $application->NOC_certificate);

            return response()->json([
                'status'  => 1,
                'message' => 'Certificate generated.',
                'data'    => [
                    'application' => $application->withoutRelations(),
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

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


    public function preview_certificate($application_id)
    {

        try {


            $application = UserServiceApplication::with('service')->findOrFail($application_id);

            if (!$application->service || !$application->service->form_template) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Certificate template not found.'
                ]);
            }

            $user = Auth::user();

            $template = (string) data_get($application, 'service.form_template', '');
            $template = html_entity_decode($template, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $template = str_replace("\xC2\xA0", ' ', $template);

            $name = $application->user->authorized_person_name ?? $user->name ?? '—';
            $verifyUrl = 'https://swaagat.tripura.gov.in/verify';

            $qrPayload = "Name: {$name}\nApplication Id: {$application->id}\n{$verifyUrl}";
            $qrSvg = QrCode::format('svg')->size(220)->margin(0)->generate($qrPayload);
            $qrDataUri = 'data:image/svg+xml;base64,' . base64_encode($qrSvg);

            $data = [
                'form_title'        => 'FORM VI',
                'rules_ref'         => '[ Under rule 19(1) of the Tripura Contract Labour (Regulation and Abolition) Rules, 1978; ]',
                'government'        => 'Government of Tripura',
                'issuing_office'    => 'Office of the Licensing Officer',
                'verify_portal_url' => 'https://swaagat.tripura.gov.in',

                'license_id'          => $application->id ?? '—',
                'issue_date'          => $application->application_date ? Carbon::parse($application->application_date)->format('d-m-Y') : '—',
                'principal_employer'  => $application->user->authorized_person_name ?? '—',
                'guardian_name'       => $application->user->management_details->owner_details_father_name ?? '—',
                'address'             => $application->user->management_details->owner_details_residential_details ?? '—',
                'work_location'       => $application->work_location ?? 'Tripura',
                'registration_no'     => $application->id ?? '—',
                'registration_date'   => $application->application_date ? Carbon::parse($application->application_date)->format('d-m-Y') : '—',
                'valid_upto'          => $application->NOC_expiry_date ? Carbon::parse($application->NOC_expiry_date)->format('d-m-Y') : '—',
                'max_contract_labour' => (string) ($application->max_contract_labour ?? 0),
                'fee_paid'            => (string) ($application->final_fee ?? 0),
                'security_deposit'    => (string) ($application->security_deposit ?? ''),
                'designation'         => $application->service->department->department_user->designation ?? '',
                'signature_note'      => 'Not Required',
                'user_name'           => $user->authorized_person_name ?? '',
                'user_id'             => (string) $user->id,
                'qr_code'             => $qrDataUri,
            ];

            $filled = preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', function ($m) use ($data) {
                $key = $m[1];
                return e($data[$key] ?? '');
            }, $template);

            if (stripos($filled, '<html') === false) {
                $filled = '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $filled . '</body></html>';
            }

            $pdf = Pdf::loadHTML($filled)->setPaper('a4', 'portrait');

            $temp_file_name = 'preview_' . uniqid() . '.pdf';
            $temp_path = storage_path('app/public/temp/' . $temp_file_name);
            Storage::disk('public')->put('temp/' . $temp_file_name, $pdf->output());

            $previewUrl = asset('storage/temp/' . $temp_file_name);

            return response()->json([
                'status' => 1,
                'message' => 'Preview generated successfully.',
                'pdf_url' => $previewUrl,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while generating preview.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

}