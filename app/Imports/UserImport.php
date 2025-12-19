<?php

namespace App\Imports;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserImport
{
    public int $imported_count = 0;
    public int $skipped_count  = 0;
    public array $skipped_rows = [];

    protected string $default_password_hash;

    public function __construct()
    {
        $this->default_password_hash = Hash::make('Swaagat@123');
    }

    public function import(array $users_array): void
    {
        set_time_limit(0);
        ini_set('memory_limit', '512M');
        DB::disableQueryLog();

        $seen_mobiles = [];
        $chunk_rows   = [];
        $chunk_size   = 1000;

        foreach ($users_array as $index => $user_row) {
            if (!is_array($user_row)) {

                $this->skipped_count++;

                $this->skipped_rows[] = [
                    'row_index' => $index,
                    'uid'       => null,
                    'mobile_no' => null,
                    'reason'    => 'invalid_object',
                ];
                continue;
            }

            $mapped_row = $this->map_user_row_to_db($user_row);

            if ($mapped_row === null) {

                $this->skipped_count++;

                $this->skipped_rows[] = [
                    'row_index' => $index,
                    'uid'       => $this->get_field_first_value($user_row, 'uid'),
                    'mobile_no' => $this->get_field_first_value($user_row, 'field_mobile'),
                    'reason'    => 'missing_required_fields',
                ];
                continue;
            }

            $pan = $mapped_row['pan'] ?? null;
            if ($pan === null || trim((string) $pan) === '') {
                $this->skipped_count++;

                $this->skipped_rows[] = [
                    'row_index' => $index,
                    'uid'       => $this->get_field_first_value($user_row, 'uid'),
                    'mobile_no' => $mapped_row['mobile_no'] ?? $this->get_field_first_value($user_row, 'field_mobile'),
                    'reason'    => 'pan_missing',
                ];
                continue;
            }
            $mobile_no = $mapped_row['mobile_no'];

            if (isset($seen_mobiles[$mobile_no])) {

                $this->skipped_count++;

                $this->skipped_rows[] = [
                    'row_index' => $index,
                    'uid'       => $this->get_field_first_value($user_row, 'uid'),
                    'mobile_no' => $mobile_no,
                    'reason'    => 'duplicate_mobile_in_json',
                ];
                continue;
            }

            $seen_mobiles[$mobile_no] = true;
            $chunk_rows[] = $mapped_row;

            if (count($chunk_rows) >= $chunk_size) {

                $inserted = DB::table('users')->insertOrIgnore($chunk_rows);

                $this->imported_count += $inserted;
                $this->skipped_count  += (count($chunk_rows) - $inserted);
                $chunk_rows = [];
            }
        }

        if (!empty($chunk_rows)) {

            $inserted = DB::table('users')->insertOrIgnore($chunk_rows);

            $this->imported_count += $inserted;
            $this->skipped_count  += (count($chunk_rows) - $inserted);
        }
    }

    protected function map_user_row_to_db(array $user_row): ?array
    {
        $old_id                  = $this->get_field_first_value($user_row, 'uid');
        $name_of_enterprise      = $this->get_field_first_value($user_row, 'field_name_of_industrial');
        $authorized_person_name  = $this->get_field_first_value($user_row, 'field_user_full_name');
        $email_id                = $this->get_field_first_value($user_row, 'mail');
        $mobile_no               = $this->get_field_first_value($user_row, 'field_mobile');
        $user_name               = $this->get_field_first_value($user_row, 'name');

        if (
            empty($name_of_enterprise) ||
            empty($authorized_person_name) ||
            empty($email_id) ||
            empty($mobile_no) ||
            empty($user_name)
        ) {
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
            'password'                      => $this->default_password_hash,
            'created_at'                    => $created_at ?: now(),
            'updated_at'                    => $updated_at ?: now(),
        ];
    }

    protected function get_field_first_value(array $user_row, string $field_key): ?string
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

    protected function parse_date_value(?string $date_string): ?Carbon
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
