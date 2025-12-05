<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ImportController extends Controller
{
    public function import_users_form()
    {
        return view('admin.import.users');
    }

    public function import_users(Request $request)
    {
        try {
            // DB::table('users')->truncate();
            set_time_limit(0);
            ini_set('memory_limit', '512M');
            DB::disableQueryLog();

            $request->validate([
                'json_file' => 'nullable|file|mimes:json,txt',
                'json_text' => 'nullable|string',
            ]);

            if (!$request->hasFile('json_file') && empty($request->json_text)) {
                return back()
                    ->withInput()
                    ->with('error', 'Please upload a JSON file or paste JSON text.');
            }

            if ($request->hasFile('json_file')) {
                $json_string = file_get_contents($request->file('json_file')->getRealPath());
            } else {
                $json_string = $request->json_text;
            }

            $decoded_data = json_decode($json_string, true);

            if ($decoded_data === null && json_last_error() !== JSON_ERROR_NONE) {
                return back()
                    ->withInput()
                    ->with('error', 'Invalid JSON. Please check the format.');
            }

            if (isset($decoded_data[0]) && is_array($decoded_data[0])) {
                $users_array = $decoded_data;
            } else {
                $users_array = [$decoded_data];
            }

            $default_password_hash = Hash::make('Swaagat@123');

            $imported_count   = 0;
            $skipped_count    = 0;
            $seen_mobiles     = [];
            $skipped_rows     = [];

            $chunk_size       = 1000;
            $chunk_rows       = [];

            foreach ($users_array as $index => $user_row) {
                if (!is_array($user_row)) {
                    $skipped_count++;
                    $skipped_rows[] = [
                        'row_index'  => $index,
                        'uid'        => null,
                        'mobile_no'  => null,
                        'reason'     => 'invalid_object',
                    ];
                    continue;
                }

                $mapped_row = $this->map_user_row_to_db($user_row, $default_password_hash);

                if ($mapped_row === null) {
                    $skipped_count++;
                    $skipped_rows[] = [
                        'row_index'  => $index,
                        'uid'        => $this->get_field_first_value($user_row, 'uid'),
                        'mobile_no'  => $this->get_field_first_value($user_row, 'field_mobile'),
                        'reason'     => 'missing_required_fields',
                    ];
                    continue;
                }

                $mobile_no = $mapped_row['mobile_no'];

                if (isset($seen_mobiles[$mobile_no])) {
                    $skipped_count++;
                    $skipped_rows[] = [
                        'row_index'  => $index,
                        'uid'        => $this->get_field_first_value($user_row, 'uid'),
                        'mobile_no'  => $mobile_no,
                        'reason'     => 'duplicate_mobile_in_json',
                    ];
                    continue;
                }

                $seen_mobiles[$mobile_no] = true;
                $chunk_rows[] = $mapped_row;

                if (count($chunk_rows) >= $chunk_size) {
                    $inserted = DB::table('users')->insertOrIgnore($chunk_rows);

                    $imported_count += $inserted;
                    $skipped_count  += (count($chunk_rows) - $inserted);

                    $chunk_rows = [];
                }
            }

            if (!empty($chunk_rows)) {
                $inserted = DB::table('users')->insertOrIgnore($chunk_rows);

                $imported_count += $inserted;
                $skipped_count  += (count($chunk_rows) - $inserted);
            }

            $message = "Import completed. Imported: {$imported_count}, Skipped: {$skipped_count}.";

            return back()->with([
                'success'       => $message,
                'skipped_rows'  => $skipped_rows,
            ]);
        } catch (\Exception $e) {
            return back()
                ->withInput()
                ->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    private function map_user_row_to_db(array $user_row, string $default_password_hash): ?array
    {
        $old_id         = $this->get_field_first_value($user_row, 'uid');
        $name_of_enterprise     = $this->get_field_first_value($user_row, 'field_name_of_industrial');
        $authorized_person_name = $this->get_field_first_value($user_row, 'field_user_full_name');
        $email_id               = $this->get_field_first_value($user_row, 'mail');
        $mobile_no              = $this->get_field_first_value($user_row, 'field_mobile');
        $user_name              = $this->get_field_first_value($user_row, 'name');

        if (empty($name_of_enterprise) ||
            empty($authorized_person_name) ||
            empty($email_id) ||
            empty($mobile_no) ||
            empty($user_name)) {
            return null;
        }

        $pan                           = $this->get_field_first_value($user_row, 'field_pan');
        $bin                           = $this->get_field_first_value($user_row, 'field_unique_identification');
        $registered_enterprise_address = $this->get_field_first_value($user_row, 'field_company_address_usr');
        $registered_enterprise_city    = $this->get_field_first_value($user_row, 'field_company_city');
        $business_activity             = $this->get_field_first_value($user_row, 'field_company_activity');
        $role_key                      = $this->get_field_first_value($user_row, 'roles');

        $user_type = 'individual';
        if ($role_key === 'department') {
            $user_type = 'department';
        } elseif ($role_key === 'admin') {
            $user_type = 'admin';
        }

        $status_value       = $this->get_field_first_value($user_row, 'status');
        $status             = 'active';
        $is_mobile_verified = 1;

        if ($status_value === false || $status_value === 0 || $status_value === '0') {
            $status             = 'blocked';
            $is_mobile_verified = 0;
        }

        $created_raw = $this->get_field_first_value($user_row, 'created');
        $changed_raw = $this->get_field_first_value($user_row, 'changed');

        $created_at = $this->parse_date_value($created_raw);
        $updated_at = $this->parse_date_value($changed_raw);

        return [
            'old_id'                        => $old_id,
            'name_of_enterprise'            => $name_of_enterprise,
            'authorized_person_name'        => $authorized_person_name,
            'email_id'                      => $email_id,
            'pan'                           => $pan,
            'mobile_no'                     => $mobile_no,
            'is_mobile_verified'            => $is_mobile_verified,
            'user_name'                     => $user_name,
            'district_id'                   => null,
            'subdivision_id'                => null,
            'ulb_id'                        => null,
            'ward_id'                       => null,
            'bin'                           => $bin,
            'registered_enterprise_address' => $registered_enterprise_address,
            'registered_enterprise_city'    => $registered_enterprise_city,
            'business_activity'             => $business_activity,
            'user_type'                     => $user_type,
            'status'                        => $status,
            'password'                      => $default_password_hash,
            'created_at'                    => $created_at ?: now(),
            'updated_at'                    => $updated_at ?: now(),
        ];
    }

    private function get_field_first_value(array $user_row, string $field_key): ?string
    {
        if (!isset($user_row[$field_key]) || !is_array($user_row[$field_key]) || count($user_row[$field_key]) === 0) {
            return null;
        }

        $first_item = $user_row[$field_key][0];

        if (!is_array($first_item)) {
            return null;
        }

        if (array_key_exists('value', $first_item)) {
            return $first_item['value'];
        }

        if (array_key_exists('target_id', $first_item)) {
            return $first_item['target_id'];
        }

        return null;
    }

    private function parse_date_value(?string $date_string): ?Carbon
    {
        if (empty($date_string)) {
            return null;
        }

        try {
            return Carbon::parse($date_string)->setTimezone('Asia/Kolkata');
        } catch (\Exception $e) {
            return null;
        }
    }
}
