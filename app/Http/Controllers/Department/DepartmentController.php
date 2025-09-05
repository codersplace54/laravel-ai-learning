<?php

namespace App\Http\Controllers\Department;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;
use App\Models\Department;

class DepartmentController extends Controller
{
    public function all_departments(Request $request)
    {
        try {


            $per_page = $request->input('per_page', 10);

            $departments = Department::paginate($per_page);

            return response()->json([
                'status' => 1,
                'data' => $departments->items(),
                'meta' => [
                    'current_page' => $departments->currentPage(),
                    'per_page' => $departments->perPage(),
                    'total' => $departments->total(),
                    'last_page' => $departments->lastPage(),
                ],
            ]);
        } catch (Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Failed to retrieve departments.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store_department(Request $request)
    {
        try {


            DB::beginTransaction();

            $request->validate(
                [
                    'name' => 'required|string|unique:departments,name|min:2|max:255',
                    'details' => 'nullable|string',
                ],
                [
                    'name.required' => 'The department name is required.',
                    'name.unique' => 'This department name is already taken. Please use another name.',
                    'name.max' => 'The department name cannot exceed 255 characters.',
                    'name.min' => 'The department name must be at least 2 characters.',
                ]
            );

            $department = Department::create([
                'name' => $request->name,
                'details' => $request->details,
            ]);

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => "New department has been created successfully.",
                'data' => $department,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Failed to create department.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show_department(Request $request)
    {
        try {


            $request->validate(
                [
                    'id' => 'required|exists:departments,id',
                ],
                [
                    'id.required' => 'Id is required.',
                    'id.exists' => 'No department is assigned to this ID.',
                ]
            );

            $department = Department::findOrFail($request->id);

            return response()->json([
                'status' => 1,
                'data' => $department,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Failed to retrieve department.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update_department(Request $request)
    {
        try {


            DB::beginTransaction();

            $request->validate(
                [
                    'id' => 'required|exists:departments,id',
                    'name' => 'required|string|unique:departments,name,' . $request->input('id') . '|min:2|max:255',
                    'details' => 'nullable|string',
                ],
                [
                    'id.required' => 'Id is required.',
                    'id.exists' => 'No department is assigned to this ID.',
                    'name.required' => 'The department name is required.',
                    'name.unique' => 'This department name is already taken. Please use another name.',
                    'name.max' => 'The department name cannot exceed 255 characters.',
                    'name.min' => 'The department name must be at least 2 characters.',
                ]
            );

            $department = Department::findOrFail($request->id);

            $department->update([
                'name' => $request->name,
                'details' => $request->details,
            ]);

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => "The department has been updated successfully.",
                'data' => $department,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Failed to update department.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy_department(Request $request)
    {
        try {


            DB::beginTransaction();

            $request->validate(
                [
                    'id' => 'required|exists:departments,id',
                ],
                [
                    'id.required' => 'Id is required.',
                    'id.exists' => 'No department is assigned to this ID.',
                ]
            );

            $department = Department::findOrFail($request->id);

            if ($department->services()->exists()) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Department cannot be deleted because it is assigned to services.',
                ], 400);
            }

            $department->delete();

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Department deleted successfully.',
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Failed to delete department.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
