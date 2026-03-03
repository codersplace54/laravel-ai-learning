<?php

namespace App\Http\Controllers;

use App\Models\InformationWizard;
use Illuminate\Http\Request;

class InformationWizardController extends Controller
{
    public function get_all_information_wizards()
    {
        try {
            $data = InformationWizard::orderBy('id', 'desc')->get();

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
}
