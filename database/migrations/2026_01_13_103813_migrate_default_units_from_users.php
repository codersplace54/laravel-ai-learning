<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{

    public function up(): void
    {
        $users = DB::table('users')
            ->where('user_type', 'individual')
            ->get();

        foreach ($users as $user) {
            DB::table('user_units')->insert([
                'user_id'       => $user->id,
                'unit_name'     => $user->name_of_enterprise,
                'district_id'   => $user->district_id,
                'subdivision_id' => $user->subdivision_id,
                'ulb_id'        => $user->ulb_id,
                'ward_id'       => $user->ward_id,
                'status'        => 'active',
                'created_at'    => Carbon::now(),
                'updated_at'    => Carbon::now(),
            ]);
        }
    }


    public function down(): void
    {
        $userIds = DB::table('users')
            ->where('user_type', 'individual')
            ->pluck('id');

        DB::table('user_units')
            ->whereIn('user_id', $userIds)
            ->delete();
    }
};
