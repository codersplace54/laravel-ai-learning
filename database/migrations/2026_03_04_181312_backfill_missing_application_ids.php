<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\UserServiceApplication;
use App\Models\ServiceMaster;

return new class extends Migration
{

    public function up(): void
    {
        DB::transaction(function () {

            UserServiceApplication::where(function ($query) {
                $query->whereNull('applicationId')
                    ->orWhere('applicationId', '');
            })
                ->where('status', '!=', 'draft')
                ->chunkById(500, function ($applications) {

                    foreach ($applications as $application) {

                        $service = ServiceMaster::find($application->service_id);

                        if (!$service) {
                            continue;
                        }

                        $short_name = strtoupper($service->noc_short_name);
                        $padded_id = str_pad($application->id, 4, '0', STR_PAD_LEFT);

                        $application_number = $short_name . $padded_id;

                        DB::table('user_service_applications')
                            ->where('id', $application->id)
                            ->update([
                                'applicationId' => $application_number
                            ]);
                    }
                });
        });
    }


    public function down(): void
    {
        //
    }
};
