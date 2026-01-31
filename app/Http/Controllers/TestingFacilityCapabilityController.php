<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\TestingFacilityCapability;
use App\Models\TestingFacility;
use App\Models\FssaiLabEquipment;

class TestingFacilityCapabilityController extends Controller
{
    public function get_testing_facility_capabilities()
    {


        try {


            $capabilities = TestingFacilityCapability::orderBy('created_at', 'desc')->get();

            if ($capabilities->isEmpty()) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'No testing facility capabilities found.',
                    'data'    => [],
                ], 404);
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Testing facility capabilities fetched successfully.',
                'data'    => $capabilities->map(function ($item) {
                    return [
                        'id'               => $item->id,
                        'product_material' => $item->product_material,
                        'test_parameter'   => $item->test_parameter,
                        'test_method'      => $item->test_method,
                        'group_name'       => $item->group_name,
                        'sub_group_name'   => $item->sub_group_name,
                        'created_at'       => $item->created_at,
                    ];
                }),
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function get_testing_facilities()
    {


        try {


            $facilities = TestingFacility::orderBy('id', 'desc')->get();

            if ($facilities->isEmpty()) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'No testing facilities found.',
                    'data'    => [],
                ], 404);
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Testing facilities fetched successfully.',
                'data'    => $facilities->map(function ($item) {
                    return [
                        'id'                       => $item->id,
                        'institution_name'         => $item->institution_name,
                        'organization_type'        => $item->organization_type,
                        'lab_name'                 => $item->lab_name,
                        'district'                 => $item->district,
                        'address'                  => $item->address,
                        'ownership'                => $item->ownership,
                        'sector'                   => $item->sector,
                        'facilities_available'     => $item->facilities_available,
                        'facilities_not_available' => $item->facilities_not_available,
                        'key_equipment'            => $item->key_equipment,
                        'manpower'                 => $item->manpower,
                        'accreditation'            => $item->accreditation,
                        'msme_access'              => $item->msme_access,
                        'charges'                  => $item->charges,
                        'turnaround_time'          => $item->turnaround_time,
                        'contact_person'           => $item->contact_person,
                        'phone'                    => $item->phone,
                        'email'                    => $item->email,
                    ];
                }),
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function get_fssai_lab_equipment()
    {


        try {


            $equipment = FssaiLabEquipment::orderBy('created_at', 'desc')->get();

            if ($equipment->isEmpty()) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'No lab equipment found.',
                    'data'    => [],
                ], 404);
            }

            return response()->json([
                'status'  => 1,
                'message' => 'Lab equipment fetched successfully.',
                'data'    => $equipment->map(function ($item) {
                    return [
                        'id'             => $item->id,
                        'uid'            => $item->uid,
                        'equipment_name' => $item->equipment_name,
                        'serial_no'      => $item->serial_no,
                        'model'          => $item->model,
                        'make'           => $item->make,
                        'year_of_make'   => $item->year_of_make,
                        'range_accuracy' => $item->range_accuracy,
                        'created_at'     => $item->created_at,
                        'updated_at'     => $item->updated_at,
                    ];
                }),
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
