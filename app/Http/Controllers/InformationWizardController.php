<?php

namespace App\Http\Controllers;

use App\Models\InformationWizard;
use Illuminate\Http\Request;

class InformationWizardController extends Controller
{
    public function get_all_information_wizards(Request $request)
    {
        try {
            $query = InformationWizard::query();

            if ($request->filled('category')) {
                $query->where('field_wizard_category', $request->category);
            }

            if ($request->filled('department')) {
                $query->where('dept_id', $request->department);
            }

            if ($request->filled('search')) {
                $query->where(function ($q) use ($request) {
                    $q->where('field_wizard_noc_name', 'like', '%' . $request->search . '%')
                      ->orWhere('title', 'like', '%' . $request->search . '%');
                });
            }

            if ($request->filled('service')) {
                $query->where('title', 'like', '%' . $request->service . '%');
            }

            if ($request->filled('investor')) {
                $query->where('field_wizard_fee_text', 'like', '%Investor%')
                      ->where('field_wizard_fee_text', 'like', '%' . $request->investor . '%');
            }

            if ($request->filled('business_location')) {
                $query->where('field_wizard_fee_text', 'like', '%Business Location%')
                      ->where('field_wizard_fee_text', 'like', '%' . $request->business_location . '%');
            }

            if ($request->filled('risk_category')) {
                $query->where('field_wizard_fee_text', 'like', '%Risk%')
                      ->where('field_wizard_fee_text', 'like', '%' . $request->risk_category . '%');
            }

            if ($request->filled('num_employees')) {
                $query->where(function ($q) use ($request) {
                    $q->where('field_wizard_fee_text', 'like', '%' . $request->num_employees . '%')
                      ->orWhere('field_wizard_required_documents', 'like', '%' . $request->num_employees . '%')
                      ->orWhere('field_wizard_process', 'like', '%' . $request->num_employees . '%');
                });
            }

            if ($request->filled('num_hp')) {
                $query->where(function ($q) use ($request) {
                    $q->where('field_wizard_fee_text', 'like', '%' . $request->num_hp . '%')
                      ->orWhere('field_wizard_required_documents', 'like', '%' . $request->num_hp . '%')
                      ->orWhere('field_wizard_process', 'like', '%' . $request->num_hp . '%');
                });
            }

            $data = $query->orderBy('id', 'desc')->get();

            if ($data->isEmpty()) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'No information wizards found.',
                    'data'    => []
                ], 200);
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Information wizards fetched successfully.',
                'data'    => $data
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function get_information_wizard_filters(Request $request)
    {
        try {
            $baseQuery = InformationWizard::query();

            $categories = (clone $baseQuery)
                ->whereNotNull('field_wizard_category')
                ->where('field_wizard_category', '!=', '')
                ->distinct()
                ->pluck('field_wizard_category');

            $departments = InformationWizard::whereNotNull('dept_id')
                ->whereNotNull('field_wizard_department')
                ->where('field_wizard_department', '!=', '')
                ->distinct()
                ->get(['dept_id', 'field_wizard_department'])
                ->map(fn($d) => [
                    'id'   => $d->dept_id,
                    'name' => $d->field_wizard_department,
                ])
                ->unique('id')
                ->values();

            $services = (clone $baseQuery)
                ->where('dept_id',$request->department_id)
                ->whereNotNull('title')
                ->where('title', '!=', '')
                ->distinct()
                ->pluck('title')
                ->map(fn($t) => strip_tags($t))
                ->filter()
                ->values();

            return response()->json([
                'status'                => 1,
                'message'               => 'Filters fetched successfully.',
                'is_factory_department' => $request->filled('department_id') && $request->department_id == 9,
                'categories'            => $categories,
                'departments'           => $departments,
                'services'              => $services,
                'business_location' => [
                    'Industrial Estate',
                    'Urban',
                    'Rural',
                ],
                'investor'         => [
                    'Domestic Investor',
                    'Foreign Investor',
                ],
                'risk_category'    => [
                    'High',
                    'Medium',
                    'Low',
                ],
                'num_employees'    => [
                    'Upto 10',
                    '11 - 50',
                    '51 - 100',
                    '101 - 500',
                    'Above 500',
                ],
                'num_hp'           => [
                    'Upto 10 HP',
                    '11 - 50 HP',
                    '51 - 100 HP',
                    '101 - 500 HP',
                    'Above 500 HP',
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
