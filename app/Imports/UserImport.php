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

            $mapped_row = $this->map_user_row_to_db($user_row, $index);

            if ($mapped_row === null) {
                // map_user_row_to_db already pushed exact skip reason(s)
                continue;
            }

            $mobile_no = $mapped_row['mobile_no'] ?? null;

            if (!$mobile_no) {
                $this->skipped_count++;
                $this->skipped_rows[] = [
                    'row_index' => $index,
                    'uid'       => $this->get_field_first_value($user_row, 'uid'),
                    'mobile_no' => null,
                    'reason'    => 'mobile_missing_after_mapping',
                ];
                continue;
            }

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
                $this->flush_chunk($chunk_rows, $index);
                $chunk_rows = [];
            }
        }

        if (!empty($chunk_rows)) {
            $this->flush_chunk($chunk_rows, null);
        }
    }

    protected function flush_chunk(array $chunk_rows, ?int $index): void
    {
        if (empty($chunk_rows)) {
            return;
        }

        // Check for existing records before inserting
        $mobile_nos = array_column($chunk_rows, 'mobile_no');
        $email_ids = array_column($chunk_rows, 'email_id');
        $old_ids = array_column($chunk_rows, 'old_id');

        $existing_records = DB::table('users')
            ->where(function($query) use ($mobile_nos, $email_ids, $old_ids) {
                $query->whereIn('mobile_no', $mobile_nos)
                      ->orWhereIn('email_id', $email_ids)
                      ->orWhereIn('old_id', $old_ids);
            })
            ->select('mobile_no', 'email_id', 'old_id')
            ->get();

        $existing_mobiles = $existing_records->pluck('mobile_no')->toArray();
        $existing_emails = $existing_records->pluck('email_id')->toArray();
        $existing_old_ids = $existing_records->pluck('old_id')->toArray();

        $new_rows = [];
        foreach ($chunk_rows as $row) {
            $duplicate_reason = null;
            
            if (in_array($row['mobile_no'], $existing_mobiles)) {
                $duplicate_reason = 'duplicate_mobile';
            } elseif (in_array($row['email_id'], $existing_emails)) {
                $duplicate_reason = 'duplicate_email';
            } elseif (in_array($row['old_id'], $existing_old_ids)) {
                $duplicate_reason = 'duplicate_old_id';
            }

            if ($duplicate_reason) {
                $this->skipped_count++;
                $this->skipped_rows[] = [
                    'row_index' => null,
                    'uid' => $row['old_id'],
                    'mobile_no' => $row['mobile_no'],
                    'email_id' => $row['email_id'],
                    'reason' => $duplicate_reason,
                ];
            } else {
                $new_rows[] = $row;
            }
        }

        if (!empty($new_rows)) {
            $inserted = DB::table('users')->insert($new_rows);
            $this->imported_count += count($new_rows);
        }
    }

    protected function map_user_row_to_db(array $user_row, int $row_index): ?array
    {
        $role_key = strtolower((string) $this->get_field_first_value($user_row, 'roles'));
        $role_key = trim($role_key);

        if ($role_key !== 'industrial') {
            $this->skipped_rows[] = [
                'row'        => $row_index,
                'old_id'     => $this->get_field_first_value($user_row, 'uid'),
                'role'       => $role_key ?: null,
                'reason_key' => 'role_not_individual',
                'reason'     => 'Skipped because role is not industrial',
            ];
            return null;
        }

        $old_id                 = $this->get_field_first_value($user_row, 'uid');
        $name_of_enterprise     = $this->get_field_first_value($user_row, 'field_name_of_industrial');
        $authorized_person_name = $this->get_field_first_value($user_row, 'field_user_full_name');
        $email_id               = $this->get_field_first_value($user_row, 'mail');
        $mobile_no              = $this->get_field_first_value($user_row, 'field_mobile');
        $user_name              = $this->get_field_first_value($user_row, 'name');
        $user_type              = "individual";

        $missing_fields = [];

        if (empty($email_id)) {
            $this->skipped_count++;
            $this->skipped_rows[] = [
                'row_index' => $row_index,
                'uid'       => $old_id,
                'mobile_no' => $mobile_no,
                'reason'    => 'email_missing',
            ];
            return null;
        }
        if (empty($mobile_no)) {
            $this->skipped_count++;
            $this->skipped_rows[] = [
                'row_index' => $row_index,
                'uid'       => $old_id,
                'mobile_no' => $mobile_no,
                'reason'    => 'mobile_missing',
            ];
            return null;
        }

        if (empty($user_name)) {
            $this->skipped_count++;
            $this->skipped_rows[] = [
                'row_index' => $row_index,
                'uid'       => $old_id,
                'mobile_no' => $mobile_no,
                'reason'    => 'username_missing',
            ];
            return null;
        }

        if (!empty($missing_fields)) {
            $this->skipped_count++;
            $this->skipped_rows[] = [
                'row_index'      => $row_index,
                'uid'            => $old_id,
                'mobile_no'      => $mobile_no,
                'reason'         => 'missing_required_fields',
                'missing_fields' => $missing_fields,
            ];
            return null;
        }

        $pan = $this->get_field_first_value($user_row, 'field_pan');

        if (($pan === null || trim((string) $pan) === '') && $user_type !== "individual") {

            $this->skipped_count++;
            $this->skipped_rows[] = [
                'row_index' => $row_index,
                'uid'       => $old_id,
                'mobile_no' => $mobile_no,
                'reason'    => 'pan_missing',
            ];
            return null;
        }

        $bin                           = $this->get_field_first_value($user_row, 'field_unique_identification');
        $registered_enterprise_address = $this->get_field_first_value($user_row, 'field_company_address_usr');
        $registered_enterprise_city    = $this->get_field_first_value($user_row, 'field_company_city');
        $business_activity             = $this->get_field_first_value($user_row, 'field_company_activity');

        $status_value       = $this->get_field_first_value($user_row, 'status');
        $status             = 'active';
        $is_mobile_verified = 0;

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
            'user_type'                     => "individual",
            'district_id'                   => null,
            'subdivision_id'                => null,
            'ulb_id'                        => null,
            'ward_id'                       => null,
            'bin'                           => $bin,
            'registered_enterprise_address' => $registered_enterprise_address,
            'registered_enterprise_city'    => $registered_enterprise_city,
            'business_activity'             => $business_activity,
            'user_type'                     => "individual",
            'status'                        => $status,
            'password'                      => $this->default_password_hash,
            'password_reset_required'       => 1,
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

    public function skipped_grouped(): array
    {
        $grouped = [];

        foreach ($this->skipped_rows as $row) {
            $reason = $row['reason'] ?? 'unknown';
            $grouped[$reason][] = $row;
        }

        return $grouped;
    }
}