<?php

namespace App\Http\Controllers\Role;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Exception;

class RoleController extends Controller
{
    public function all_roles(Request $request)
    {
        try {


            $per_page = $request->input('per_page', 10);

            $roles = Role::paginate($per_page);

            return response()->json([
                'status' => 1,
                'data' => $roles->items(),
                'meta' => [
                    'current_page' => $roles->currentPage(),
                    'per_page' => $roles->perPage(),
                    'total' => $roles->total(),
                    'last_page' => $roles->lastPage(),
                ],
            ]);
        } catch (Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Failed to retrieve roles.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store_role(Request $request)
    {
        try {


            DB::beginTransaction();

            $request->validate(
                [
                    'code' => 'required|string|unique:roles,code|min:1|max:255',
                    'role' => 'required|string|unique:roles,role|min:2|max:255',
                ],
                [
                    'code.unique' => 'This code is already taken. Please use another code.',
                    'code.max' => 'The code cannot exceed 255 characters.',
                    'code.min' => 'The code must be at least 1 characters.',
                    'role.unique' => 'This role is already taken. Please use another role.',
                    'role.max' => 'The role cannot exceed 255 characters.',
                    'role.min' => 'The role must be at least 2 characters.',
                ]
            );

            $role = Role::create([
                'code' => $request->code,
                'role' => $request->role,
            ]);

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => "New role has been created successfully.",
                'data' => $role,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => "Validation failed",
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Failed to create role.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show_role(Request $request)
    {
        try {


            $request->validate(
                [
                    'id' => 'required|exists:roles,id',
                ],
                [
                    'id.exists' => 'No role is assigned to this ID.'
                ]
            );

            $role = Role::findOrFail($request->id);

            return response()->json(
                [
                    'status' => 1,
                    'data' => $role,
                ]
            );
        } catch (\Illuminate\Validation\ValidationException $e) {


            return response()->json([
                'status' => 0,
                'message' => "Validation failed",
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {


            return response()->json([
                'status' => 0,
                'message' => 'Failed to retrieve role.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update_role(Request $request)
    {
        try {


            DB::beginTransaction();

            $request->validate(
                [
                    'id' => 'required|exists:roles,id',
                    'code' => 'required|string|unique:roles,code,' . $request->input('id') . '|max:255',
                    'role' => 'required|string|unique:roles,role,' . $request->input('id') . '|max:255',
                ],
                [
                    'id.exists' => 'No role is assigned to this ID.',
                    'code.unique' => 'This code is already taken. Please use another code.',
                    'code.max' => 'The code cannot exceed 255 characters.',
                    'code.min' => 'The code must be at least 1 characters.',
                    'role.unique' => 'This role is already taken. Please use another role.',
                    'role.max' => 'The role cannot exceed 255 characters.',
                    'role.min' => 'The role must be at least 2 characters.',
                ]
            );

            $role = Role::findOrFail($request->id);

            $role->update(
                [
                    'code' => $request->code,
                    'role' => $request->role,
                ]
            );

            DB::commit();

            return response()->json(
                [
                    'status' => 1,
                    'message' => "The role has been updated successfully.",
                    'data' => $role,
                ]
            );
        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => "Validation failed",
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Failed to update role.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy_role(Request $request)
    {
        try {


            DB::beginTransaction();

            $request->validate(
                [
                    'id' => 'required|exists:roles,id',
                ],
                [
                    'id.exists' => 'No role is assigned to this ID.',
                ]
            );

            $role = Role::findOrFail($request->id);

            $role->delete();

            DB::commit();

            return response()->json([
                'status' => 1,
                'message' => 'Role deleted successfully.',
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => "Validation failed",
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {


            DB::rollBack();

            return response()->json([
                'status' => 0,
                'message' => 'Failed to delete role.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
