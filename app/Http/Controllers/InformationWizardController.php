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
                $query->where('field_wizard_department', $request->department);
            }

            if ($request->filled('search')) {
                $query->where(function ($q) use ($request) {
                    $q->where('field_wizard_noc_name', 'like', '%' . $request->search . '%')
                      ->orWhere('title', 'like', '%' . $request->search . '%');
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

    public function get_information_wizard_filters()
    {
        try {
            $categories  = InformationWizard::whereNotNull('field_wizard_category')
                ->where('field_wizard_category', '!=', '')
                ->distinct()
                ->pluck('field_wizard_category');

            $departments = InformationWizard::whereNotNull('field_wizard_department')
                ->where('field_wizard_department', '!=', '')
                ->distinct()
                ->pluck('field_wizard_department');

            return response()->json([
                'status'      => 1,
                'message'     => 'Filters fetched successfully.',
                'categories'  => $categories,
                'departments' => $departments,
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
