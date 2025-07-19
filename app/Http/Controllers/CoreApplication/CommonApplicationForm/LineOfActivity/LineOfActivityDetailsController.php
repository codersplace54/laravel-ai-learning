<?php

namespace App\Http\Controllers\CoreApplication\CommonApplicationForm\LineOfActivity;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\LineOfActivity;
use App\Models\RawMaterialToBeUsed;
use App\Models\ListOfProductsOrByProduct;


class LineOfActivityDetailsController extends Controller
{

    public function line_of_activity_store(Request $request)
    {

        try {


            $user = Auth::user();
            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $request->validate([
                'thrust_sector' => 'required|in:Agri & Horticultural Produce,Bamboo,Gas,Hospital/Nursing Home,Hotel,Rubber,Tea,Tourism Promoting Activites(Water-Sports, Ropeways, Adventure and Leisure Sports)',

                'raw_materials' => 'required|array',
                'raw_materials.*.raw_material_name' => 'required|string',
                'raw_materials.*.raw_material_quantity_per_month' => 'required|string',
                'raw_materials.*.raw_material_unit' => 'required|in:Liters Numbers Per Month,Kilo Liters Number Per Month,Meter Numbers Per Month,Square Meter Numbers Per Month,Cubic Meter Numbers Per Month,Foot Numbers Per Month,Square Foot Numbers Per Month,Tonnes Numbers Per Month,Metric Tonnes Numbers Per Month,Million Unit (MU)',

                'products' => 'required|array',
                'products.*.product_name' => 'required|string',
                'products.*.product_production_capacity_per_month' => 'required|string',
                'products.*.product_average_production_per_month' => 'required|string',
                'products.*.unit' => 'required|in:Liters Numbers Per Month,Kilo Liters Number Per Month,Meter Numbers Per Month,Square Meter Numbers Per Month,Cubic Meter Numbers Per Month,Foot Numbers Per Month,Square Foot Numbers Per Month,Tonnes Numbers Per Month,Metric Tonnes Numbers Per Month,Million Unit (MU)',
            ]);

            DB::beginTransaction();

            $line_of_activity = LineOfActivity::where('user_id', $user->id)->first();

            if ($line_of_activity) {
                $line_of_activity->update([
                    'thrust_sector' => $request->thrust_sector,
                ]);
            } else {
                $line_of_activity = LineOfActivity::create([
                    'user_id' => $user->id,
                    'thrust_sector' => $request->thrust_sector,
                ]);
            }

            $raw_material_to_be_used = [];
            foreach ($request->raw_materials as $material) {
                $raw_material  =  RawMaterialToBeUsed::create([
                    'user_id' => $user->id,
                    'raw_material_name' => $material['raw_material_name'],
                    'raw_material_quantity_per_month' => $material['raw_material_quantity_per_month'],
                    'raw_material_unit' => $material['raw_material_unit'],
                ]);
                $raw_material_to_be_used[] = $raw_material->toArray();
            }


            $list_of_products_or_by_products = [];
            foreach ($request->products as $product) {
                $list_of_products  = ListOfProductsOrByProduct::create([
                    'user_id' => $user->id,
                    'product_name' => $product['product_name'],
                    'product_production_capacity_per_month' => $product['product_production_capacity_per_month'],
                    'product_average_production_per_month' => $product['product_average_production_per_month'],
                    'unit' => $product['unit'],
                ]);
                $list_of_products_or_by_products[] = $list_of_products->toArray();
            }

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Details saved successfully.',
                'line_of_activity' => $line_of_activity,
                'raw_material_to_be_used' => $raw_material_to_be_used,
                'list_of_products_or_by_products' => $list_of_products_or_by_products,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {


            DB::rollBack();

            Log::error('Error saving LineOfActivity details: ' . $e->getMessage());

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
            ], 500);
        }
    }


    public function line_of_activity_delete()
    {

        try {


            $user = Auth::user();
            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            DB::beginTransaction();

            LineOfActivity::where('user_id', $user->id)->delete();
            RawMaterialToBeUsed::where('user_id', $user->id)->delete();
            ListOfProductsOrByProduct::where('user_id', $user->id)->delete();

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'LineOfActivity and related records deleted successfully.',
            ], 200);
        } catch (\Exception $e) {


            DB::rollBack();

            Log::error('Error deleting LineOfActivity: ' . $e->getMessage());

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
            ], 500);
        }
    }


    public function line_of_activity_view()
    {

        try {


            $user = Auth::user();
            if (!$user) {
                return response()->json(['status' => 0, 'message' => 'Unauthenticated user.'], 401);
            }

            $line_of_activity = LineOfActivity::where('user_id', $user->id)->first();

            if (!$line_of_activity) {
                return response()->json(['status' => 0, 'message' => 'No LineOfActivity found.'], 404);
            }

            $raw_material_to_be_used = RawMaterialToBeUsed::where('user_id', $user->id)->get();
            $list_of_products = ListOfProductsOrByProduct::where('user_id', $user->id)->get();

            return response()->json([
                'status' => 1,
                'message' => 'LineOfActivity details fetched successfully.',
                'line_of_activity' => $line_of_activity,
                'raw_material_to_be_used' => $raw_material_to_be_used,
                'list_of_products' => $list_of_products,
            ], 200);
        } catch (\Exception $e) {


            Log::error('Error fetching LineOfActivity: ' . $e->getMessage());

            return response()->json([
                'status' => 0,
                'message' => 'Something went wrong.',
            ], 500);
        }
    }
}
