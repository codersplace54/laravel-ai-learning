<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        DB::statement("
            ALTER TABLE user_service_applications 
            MODIFY status ENUM(
                'draft',
                'saved',
                'submitted',
                'under_review',
                'approved',
                'rejected',
                'send_back',
                're_submitted',
                'extra_payment',
                'expired',
                'noc_issued'
            ) NOT NULL
        ");
    }

    public function down()
    {
        DB::statement("
            ALTER TABLE user_service_applications 
            MODIFY status ENUM(
                'saved',
                'submitted',
                'under_review',
                'approved',
                'rejected',
                'send_back',
                're_submitted',
                'extra_payment',
                'expired',
                'noc_issued'
            ) NOT NULL
        ");
    }
};
