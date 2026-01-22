<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE user_service_applications
            MODIFY COLUMN status ENUM(
                'saved',
                'submitted',
                'under_review',
                'approved',
                'rejected',
                'send_back',
                're_submitted',
                'extra_payment',
                'expired'
            ) DEFAULT 'saved'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE user_service_applications
            MODIFY COLUMN status ENUM(
                'saved',
                'submitted',
                'under_review',
                'approved',
                'rejected',
                'send_back',
                're_submitted',
                'extra_payment'
            ) DEFAULT 'saved'
        ");
    }
};
