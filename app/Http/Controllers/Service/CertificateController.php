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
use App\Models\ServiceQuestionnaire;
use App\Models\UserServiceApplication;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class CertificateController extends Controller
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
                'business_pan_no',
                'user_id',
                'qr_code',
                'field_1',
                'field_2',
                'application_data.n',
                'table_section'
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

    //edit application data preview
    public function user_certificate_view(Request $request)
    {
        try {
            $request->validate([
                'application_id' => 'required|integer|exists:user_service_applications,id',
            ]);

            $base = $this->prepare_certificate_base($request->application_id);

            $application = $base['application'];
            $user        = $base['user'];
            $template    = $base['template'];
            $qr_data_uri = $base['qr_data_uri'];

            preg_match_all('/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/', $template, $matches);
            $placeholders = array_values(array_unique($matches[1] ?? []));

            $base_data = $this->build_certificate_base_data($application, $qr_data_uri, null);

            $always_keep = ['add_watermark', 'field_1', 'field_2'];

            $data = [];

            foreach ($placeholders as $key) {
                if (strpos($key, 'application_data.') === 0) {
                    continue;
                }
                $data[$key] = $base_data[$key] ?? '';
            }

            foreach ($always_keep as $key) {
                $data[$key] = $base_data[$key] ?? '';
            }

            $structured_application_data = $this->build_structured_application_data($application, $placeholders);
            $data['application_data'] = $structured_application_data;

            return response()->json([
                'status'  => 1,
                'message' => 'Certificate data fetched successfully.',
                'data'    => $data,
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
                'message' => 'Something went wrong',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    public function user_certificate_generate(Request $request)
    {
        $request->validate([
            'is_preview' => 'required|in:yes,no',
            'application_id' => 'required|integer|exists:user_service_applications,id',
            'add_watermark'  => 'nullable|in:yes,no',
        ]);

        try {
            $base = $this->prepare_certificate_base($request->application_id, $request);

            $application = $base['application'];
            $user        = $base['user'];
            $template    = $base['template'];
            $qr_data_uri = $base['qr_data_uri'];


            $verify_url      = 'https://swaagat.tripura.gov.in/verify';
            $name_for_qr     = $request?->name ?? $user->name_of_enterprise ?? '—';
            $license_for_qr  = $request?->license_id ?? ($application->license_id ?? '');
            $issue_for_qr    = $request?->issue_date ?? ($application->application_date ?? '');
            $valid_for_qr    = $request?->valid_upto ?? ($application->NOC_expiry_date ?? '');

            $qr_payload = "Name: {$name_for_qr}\n"
                . "License ID: {$license_for_qr}\n"
                . "Issue Date: {$issue_for_qr}\n"
                . "Valid Upto: {$valid_for_qr}\n"
                . "{$verify_url}";

            $data = $this->build_certificate_base_data($application, $qr_data_uri, $request);

            $application_data_input = $request->input('application_data');

            if (is_array($application_data_input)) {
                foreach ($application_data_input as $question_id => $answer) {
                    if (is_numeric($question_id)) {
                        $key = 'application_data.' . (int) $question_id;
                        $data[$key] = is_scalar($answer) ? (string) $answer : '';
                    }
                }
            }

            // flat keys like application_data.42
            foreach ($request->all() as $key => $value) {
                if (strpos($key, 'application_data.') === 0) {
                    $data[$key] = is_scalar($value) ? (string) $value : '';
                }
            }

            $filled = preg_replace_callback(
                '/\{\{\s*([A-Za-z0-9_.\s]+)\s*\}\}/',
                function ($m) use ($data, $application) {
                    $key = trim($m[1]);
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

                    if (strpos($key, 'application_data.') === 0) {
                        $section_name = substr($key, strlen('application_data.'));
                        if (!is_numeric($section_name)) {
                            return $this->generate_section_table($application, $section_name);
                        }
                    }

                    if ($key === 'table_section') {
                        return $this->generate_all_sections($application);
                    }

                    return is_scalar($val) ? (string) $val : '';
                },
                $template
            );


            $logo_path = storage_path('app/public/images/logo/state_emblem_english.jpg');

            $pdf = Pdf::loadHTML($filled)
                ->setPaper('a4', 'portrait')
                ->setOptions([
                    // 'isHtml5ParserEnabled' => true,
                    'isRemoteEnabled'      => true,
                    // 'defaultFont'          => 'DejaVu Sans',
                    'dpi'                  => 150,
                ]);

            $this->decorate_pdf_with_border_and_watermark($pdf, $logo_path, $request->add_watermark ?? 'no');

            // $static_pdf_relative_path = 'uploads/by-law.pdf'; // example
            // $this->add_qr_to_static_pdf($static_pdf_relative_path, $qr_payload);
            // dd("success");
            if ($request->is_preview == 'yes') {

                return response($pdf->output(), 200)
                    ->header('Content-Type', 'application/pdf')
                    ->header('Content-Disposition', 'inline; filename="preview.pdf"');
            } else {

                $old_noc = $application->NOC_certificate;

                if (!empty($old_noc) && Storage::disk('public')->exists($old_noc)) {
                    Storage::disk('public')->delete($old_noc);
                }

                $filename = $application->applicationId . '.pdf';
                $path     = "uploads/{$user->id}/application/{$filename}";
                Storage::disk('public')->put($path, $pdf->output());
                
                $meta = $this->resolve_license_meta($application, $request);

                $update_data = [
                    'NOC_certificate'      => $path,
                    'status'               => 'noc_issued',
                    'license_id'           => $meta['license_id'],
                    'NOC_generationDate'   => $meta['issue_date'],
                    'NOC_application_date' => $meta['registration_date'],
                    'NOC_expiry_date'      => $meta['valid_upto'],
                    'final_fee'            => $meta['fee_paid'],
                ];

                $application->update($update_data);


                $application->NOC_certificate = asset('storage/' . $application->NOC_certificate);

                return response()->json([
                    'status'  => 1,
                    'message' => 'Certificate generated.',
                    'data'    => [
                        'application' => $application->withoutRelations(),
                    ],
                ]);
            }
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

    private function prepare_certificate_base(int $application_id, ?Request $request = null): array
    {
        $application = UserServiceApplication::where('id', $application_id)
            ->with([
                'user.management_details',
                'service',
                'latestWorkflow:id,action_taken_by',
                'latestWorkflow.actionTaker:id',
                'latestWorkflow.actionTaker.department_user:id,user_id,designation',
                'user.enterprise_details:id,user_id,business_pan_no'
            ])
            ->firstOrFail();

        $user = $application->user;

        $raw_template = (string) data_get($application, 'service.form_template', '');
        if ($raw_template === '') {
            throw new \Exception('No form template configured for this service.');
        }

        $template = stripcslashes($raw_template); // removes \" \n etc.
        $template = html_entity_decode($template, ENT_QUOTES | ENT_HTML5, 'UTF-8'); // decodes &nbsp;
        $template = str_replace("\xC2\xA0", ' ', $template);
        $template = str_replace('&quot;', '"', $template);
        $template = str_replace('&lt;', '<', $template);
        $template = str_replace('&gt;', '>', $template); 
        $template = preg_replace('/\{\{\s*\}\}/', '', $template); // remove {{}} if any
        $template = '<style>@page { margin: 18mm 18mm 18mm 18mm; } table { border-collapse: collapse; width: 100%; } td, th { border: 1px solid #000; padding: 8px; text-align: left; }</style>' . $template;

        $verify_url = 'https://swaagat.tripura.gov.in/verify';

        $name = $request?->name ?? $user->name_of_enterprise ?? '—';

        // Handle both generate and view payloads
        $license_for_qr = $request?->license_id ?? ($application->license_id ?? '');
        $issue_for_qr   = $request?->issue_date ?? ($application->application_date ?? '');
        $valid_for_qr   = $request?->valid_upto ?? ($application->NOC_expiry_date ?? '');

        $qr_payload = "Name: {$name}\n"
            . "License ID: {$license_for_qr}\n"
            . "Issue Date: {$issue_for_qr}\n"
            . "Valid Upto: {$valid_for_qr}\n"
            . "{$verify_url}";

        $qr_svg = QrCode::format('svg')->size(220)->margin(0)->generate($qr_payload);
        $qr_data_uri = 'data:image/svg+xml;base64,' . base64_encode($qr_svg);

        return [
            'application' => $application,
            'user'        => $user,
            'template'    => $template,
            'qr_data_uri' => $qr_data_uri,
        ];
    }


    private function calculate_noc_expiry_date($service)
    {
        $now = now();
        $noc_expiry_date = null;

        if (empty($service->noc_validity)) {

            $fixed_expiry_date = $service->fixed_expiry_date
                ? Carbon::parse($service->fixed_expiry_date)
                : null;

            if ($fixed_expiry_date) {

                if ($fixed_expiry_date->isPast()) {
                    $noc_expiry_date = $fixed_expiry_date->copy()->addYear();
                } else {
                    $noc_expiry_date = $fixed_expiry_date->copy();
                }
            }
        } else {
            $noc_expiry_date = $now->copy()->addDays($service->noc_validity);
        }

        return $noc_expiry_date;
    }

    private function resolve_license_meta(UserServiceApplication $application, ?Request $request = null): array
    {
        $service = $application->service;
        $noc_expiry_date = $this->calculate_noc_expiry_date($service);
        $now = now();

        $license_id = $request?->input('license_id')
            ?? $application->license_id
            ?? $this->generate_application_number($application->service_id, $application->id);

        $issue_date = $request?->input('issue_date')
            ?? (
                $application->NOC_generationDate
                ? Carbon::parse($application->NOC_generationDate)->format('d-m-Y')
                : $now->format('d-m-Y')
            );

        $registration_date = $request?->input('registration_date')
            ?? (
                $application->NOC_application_date
                ? Carbon::parse($application->NOC_application_date)->format('d-m-Y')
                : null
            );

        $valid_upto = $request?->input('valid_upto')
            ?? (
                $application->NOC_expiry_date
                ? Carbon::parse($application->NOC_expiry_date)->format('d-m-Y')
                : Carbon::parse($noc_expiry_date)->format('d-m-Y')
            );

        $fee_paid = $request?->input('fee_paid') ?? $application->final_fee;

        return [
            'license_id'        => $license_id,
            'issue_date'        => Carbon::parse($issue_date)->format('Y-m-d'),
            'registration_date' => $registration_date ? Carbon::parse($registration_date)->format('Y-m-d') : null,
            'valid_upto'        => Carbon::parse($valid_upto)->format('Y-m-d'),
            'fee_paid'          => $fee_paid,
        ];
    }

    private function build_structured_application_data(UserServiceApplication $application, array $placeholders): array
    {
        $application_data_raw = json_decode($application->application_data, true) ?? [];
        $application_data = [];

        if (is_array($application_data_raw)) {
            foreach ($application_data_raw as $key => $value) {
                // Direct numeric key → {"614":"uploads/..."}
                if (is_numeric($key)) {
                    if (is_scalar($value)) {
                        $application_data[(int) $key] = $value;
                    }
                    continue;
                }

                // Section name → {"TestSection":[{"616":"xyz","617":"23"}]}
                if (is_array($value)) {
                    foreach ($value as $row) {
                        if (is_array($row)) {
                            foreach ($row as $sub_key => $sub_val) {
                                if (is_numeric($sub_key) && is_scalar($sub_val)) {
                                    $application_data[(int) $sub_key] = $sub_val;
                                }
                            }
                        }
                    }
                }
            }
        }

        // Collect all ids from placeholders: {{ application_data.n }}
        $app_data_ids = [];
        foreach ($placeholders as $key) {
            if (strpos($key, 'application_data.') === 0) {
                $id = (int) substr($key, strlen('application_data.'));
                if ($id > 0) {
                    $app_data_ids[] = $id;
                }
            }
        }
        $app_data_ids = array_values(array_unique($app_data_ids));

        // Load question labels & types
        $questions_by_id = [];
        if (!empty($app_data_ids)) {
            $questions = ServiceQuestionnaire::whereIn('id', $app_data_ids)
                ->get(['id', 'question_label', 'question_type']);

            foreach ($questions as $q) {
                $questions_by_id[$q->id] = [
                    'label' => $q->question_label,
                    'type'  => $q->question_type,
                ];
            }
        }

        $structured = [];
        foreach ($app_data_ids as $question_id) {
            $answer_raw = $application_data[$question_id] ?? null;
            $answer     = is_scalar($answer_raw) ? (string) $answer_raw : '';

            $structured[$question_id] = [
                'question' => $questions_by_id[$question_id]['label'] ?? null,
                'answer'   => $answer,
                'type'     => $questions_by_id[$question_id]['type'] ?? null,
            ];
        }

        return $structured;
    }

    private function decorate_pdf_with_border_and_watermark($pdf, string $logo_path, string $add_watermark = 'no'): void
    {
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

        if ($add_watermark === 'yes' && is_file($logo_path)) {
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
    }

    public function download_application_pdf(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
        }

        try {
            $request->validate([
                'application_id'   => 'required|integer|exists:user_service_applications,id',
            ]);

            $application = UserServiceApplication::where('id', $request->application_id)->first();

            $path = $application->NOC_certificate;
            
            if (!$path || !Storage::disk('public')->exists($path)) {
                return response()->json(['status' => 0, 'message' => 'Certificate not generated for this application.'], 404);
            }
            return response()->json([
                'status' => 1,
                'message' => 'PDF file is available.',
                'download_url' => asset(Storage::url($path)),
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong while fetching pdf.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    private function generate_application_number($service_id, $application_id)
    {

        $service = ServiceMaster::find($service_id);

        if (!$service) {
            return null;
        }

        $format = $service->generated_id_format;

        $current_year = date('Y');

        $application_number = strtoupper($service->noc_name);
        if (!empty($format)) {

            $application_number = str_replace(['{YEAR}', '{year}'], $current_year, $format);
            $application_number = str_replace('{SEQ}', $application_id, $application_number);
        }

        return $application_number;
    }

    private function build_certificate_base_data(
        UserServiceApplication $application,
        string $qr_data_uri,
        ?Request $request = null
    ): array {
        $user = $application->user;
        $meta = $this->resolve_license_meta($application, $request);

        return [
            'add_watermark'        => $request?->input('add_watermark', 'yes') ?? 'yes',
            'form_title'           => $request->form_title ?? 'FORM VI',
            'rules_ref'            => $request->rules_ref ?? '[ Under rule 19(1) of the Tripura Contract Labour (Regulation and Abolition) Rules, 1978; ]',
            'government'           => $request->government ?? 'Government of Tripura',
            'issuing_office'       => $request->issuing_office ?? 'Office of the Licensing Officer',
            'verify_portal_url'    => $request->verify_portal_url ?? 'https://swaagat.tripura.gov.in',

            'license_id'           => $request->license_id ?? $meta['license_id'],
            'issue_date'           => $request->issue_date ?? $meta['issue_date'],

            'principal_employer'   => $request->principal_employer ?? ($user->name_of_enterprise ?? null),
            'guardian_name'        => $request->guardian_name ?? (optional($user->management_details)->owner_details_father_name ?? null),
            'address'              => $request->address ?? (optional($user->management_details)->owner_details_residential_details ?? null),
            'work_location'        => $request->work_location ?? ($user->registered_enterprise_city ?? null),

            'registration_no'      => $request->registration_no ?? ($application->id ?? null),
            'registration_date'    => $request->registration_date ?? $meta['registration_date'],
            'valid_upto'           => $request->valid_upto ?? $meta['valid_upto'],

            'max_contract_labour'  => $request->max_contract_labour ?? null,
            'fee_paid'             => $request->fee_paid ?? $meta['fee_paid'],
            'security_deposit'     => $request->security_deposit ?? null,

            'designation'          => $request->designation ?? ($application->service->department->department_user->designation ?? null),
            'signature_note'       => $request->signature_note ?? 'Not Required',
            'user_name'            => $request->name ?? ($user->authorized_person_name ?? null),
            'user_id'              => $user->id,
            'business_pan_no'      => $request->business_pan_no ?? optional($user->enterprise_details)->business_pan_no,

            'qr_code'              => $qr_data_uri,
            'field_1'              => $request->field_1 ?? null,
            'field_2'              => $request->field_2 ?? null,
        ];
    }

    private function generate_all_sections(UserServiceApplication $application): string
    {
        $application_data_raw = json_decode($application->application_data, true) ?? [];
        $html = '';
        
        $sections = ServiceQuestionnaire::where('service_id', $application->service_id)
            ->where('is_section', 'yes')
            ->where('is_required', 'yes')
            ->distinct()
            ->pluck('section_name')
            ->filter()
            ->toArray();
        
        foreach ($sections as $section_name) {
            if (isset($application_data_raw[$section_name]) && is_array($application_data_raw[$section_name])) {
                $html .= '<h4>' . e($section_name) . '</h4>';
                $html .= $this->generate_section_table($application, $section_name);
            }
        }
        
        return $html;
    }

    private function generate_section_table(UserServiceApplication $application, string $section_name): string
    {
        $application_data_raw = json_decode($application->application_data, true) ?? [];
        
        if (!isset($application_data_raw[$section_name]) || !is_array($application_data_raw[$section_name])) {
            return '';
        }

        $section_data = $application_data_raw[$section_name];
        if (empty($section_data)) {
            return '';
        }

        $question_ids = [];
        foreach ($section_data as $row) {
            if (is_array($row)) {
                $question_ids = array_merge($question_ids, array_keys($row));
            }
        }
        $question_ids = array_unique(array_filter($question_ids, 'is_numeric'));

        if (empty($question_ids)) {
            return '';
        }

        $questions = ServiceQuestionnaire::whereIn('id', $question_ids)
            ->where('question_type', '!=', 'file')
            ->where('is_required', 'yes')
            ->get(['id', 'question_label'])
            ->keyBy('id');

        $valid_question_ids = array_intersect($question_ids, $questions->keys()->toArray());

        if (empty($valid_question_ids)) {
            return '';
        }

        $html = '<table style="width: 100%; border-collapse: collapse; margin: 10px 0; table-layout: fixed;">';
        
        $html .= '<thead><tr>';
        foreach ($valid_question_ids as $qid) {
            $label = $questions[$qid]->question_label ?? "Question {$qid}";
            $html .= '<th style="border: 1px solid #000; padding: 4px; background-color: #f5f5f5; font-size: 12px; word-wrap: break-word;">' . e($label) . '</th>';
        }
        $html .= '</tr></thead>';
        
        $html .= '<tbody>';
        foreach ($section_data as $row) {
            if (is_array($row)) {
                $html .= '<tr>';
                foreach ($valid_question_ids as $qid) {
                    $value = $row[$qid] ?? '';
                    $html .= '<td style="border: 1px solid #000; padding: 4px; font-size: 11px; word-wrap: break-word; overflow-wrap: break-word;">' . e($value) . '</td>';
                }
                $html .= '</tr>';
            }
        }
        $html .= '</tbody></table>';

        return $html;
    }

}
