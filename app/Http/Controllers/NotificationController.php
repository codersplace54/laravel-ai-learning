<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Notification;


class NotificationController extends Controller
{
    public function notification_store(Request $request)
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
                'message'       => 'required|string',
                'display_order' => 'nullable|integer',
                'attachment'    => 'nullable|file|mimes:pdf,jpg,jpeg,png',
                'upload_date'   => 'nullable|date',
                'valid_till'    => 'nullable|date',
                'status'        => 'nullable|string',
                'featured'      => 'nullable|in:yes,no',
                'link'          => 'nullable|string',
            ]);

            DB::beginTransaction();

            $attachment_path = null;

            if ($request->hasFile('attachment')) {
                $attachment_path = $request->file('attachment')
                    ->store('uploads/notifications', 'public');
            }

            $notification = Notification::create([
                'display_order' => $request->display_order,
                'message'       => $request->message,
                'attachment'    => $attachment_path,
                'upload_date'   => $request->upload_date,
                'valid_till'    => $request->valid_till,
                'status'        => $request->status ?? 'active',
                'featured'      => $request->featured ?? "no",
                'link'          => $request->link,
            ]);

            $notification->attachment = $attachment_path
                ? asset('storage/' . $attachment_path)
                : null;

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Notification created successfully.',
                'data'    => $notification
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'error'   => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function notification_update(Request $request)
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
                'id'            => 'required|integer|exists:notifications,id',
                'message'       => 'required|string',
                'display_order' => 'nullable|integer',
                'attachment'    => 'nullable|file|mimes:pdf,jpg,jpeg,png',
                'upload_date'   => 'nullable|date',
                'valid_till'    => 'nullable|date',
                'status'        => 'nullable|string',
                'featured'      => 'nullable|in:yes,no',
                'link'          => 'nullable|string',
            ]);

            DB::beginTransaction();

            $notification = Notification::where('id', $request->id)->first();

            $attachment_path = $notification->attachment;

            if ($request->hasFile('attachment')) {
                $attachment_path = $request->file('attachment')
                    ->store('uploads/notifications', 'public');
            }

            $notification->update([
                'display_order' => $request->display_order,
                'message'       => $request->message,
                'attachment'    => $attachment_path,
                'upload_date'   => $request->upload_date,
                'valid_till'    => $request->valid_till,
                'status'        => $request->status ?? $notification->status,
                'featured'      => $request->featured ?? $notification->featured,
                'link'          => $request->link,
            ]);

            $notification->attachment = $attachment_path
                ? asset('storage/' . $attachment_path)
                : null;

            DB::commit();

            return response()->json([
                'status'  => 1,
                'message' => 'Notification updated successfully.',
                'data'    => $notification
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'error'   => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function notification_delete(Request $request)
    {


        try {

            if (!Auth::check()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Unauthenticated user.'
                ], 401);
            }

            $request->validate([
                'id' => 'required|integer|exists:notifications,id',
            ]);

            DB::beginTransaction();

            $notification = Notification::where('id', $request->id)->first();

            if (!$notification) {

                DB::rollBack();

                return response()->json([
                    'status' => 0,
                    'message' => 'Notification not found.'
                ], 404);
            }

            $notification->delete();

            DB::commit();

            return response()->json([
                'status'     => 1,
                'message'    => 'Notification deleted successfully.',
                'deleted_id' => $request->id
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status'  => 0,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function notification_list(Request $request)
    {
        try {

            if (!Auth::check()) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Unauthenticated user.'
                ], 401);
            }

            $notifications = Notification::orderBy('display_order', 'asc')
                ->get();

            $notifications->transform(function ($item) {
                if ($item->attachment) {
                    $item->attachment = asset('storage/' . $item->attachment);
                } else {
                    $item->attachment = null;
                }
                return $item;
            });

            return response()->json([
                'status'  => 1,
                'message' => 'Notifications fetched successfully.',
                'data'    => $notifications
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }


    public function notification_view(Request $request)
    {

        try {

            if (!Auth::check()) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Unauthenticated user.'
                ], 401);
            }

            $request->validate([
                'id' => 'required|integer|exists:notifications,id',
            ]);

            $notification = Notification::where('id', $request->id)->first();

            if (!$notification) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Notification not found.'
                ], 404);
            }

            $notification->attachment = $notification->attachment
                ? asset('storage/' . $notification->attachment)
                : null;

            return response()->json([
                'status'  => 1,
                'message' => 'Notification fetched successfully.',
                'data'    => $notification
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed.',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {

            return response()->json([
                'status'  => 0,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
