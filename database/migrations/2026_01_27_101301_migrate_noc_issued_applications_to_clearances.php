<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{

    public function up(): void
    {
        DB::beginTransaction();

        try {

            $applications = DB::table('user_service_applications')
                ->where('status', 'noc_issued')
                ->get();

            foreach ($applications as $application) {
                $department_id = DB::table('service_masters')
                    ->where('id', $application->service_id)
                    ->value('department_id');

                $exists = DB::table('clearances')
                    ->where('application_id', $application->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('clearances')->insert([
                    'user_id'            => $application->user_id,
                    'application_id'     => $application->id,
                    'service_id'         => $application->service_id,
                    'department_id'      => $department_id,
                    'licence_number'     => $application->license_id ?? 'UNKNOWN',
                    'licence_date'       => $application->NOC_generationDate,
                    'licence_valid_till' => $application->NOC_expiry_date,
                    'status'             => 'active',
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);
            }

            DB::commit();
        } catch (\Exception $e) {

            DB::rollBack();
            throw $e;
        }
    }


    public function down(): void
    {
        DB::table('clearances')
            ->whereIn('application_id', function ($query) {
                $query->select('id')
                    ->from('user_service_applications')
                    ->where('status', 'noc_issued');
            })
            ->delete();
    }
};
