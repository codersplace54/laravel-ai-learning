<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `user_incentive_applications`
        MODIFY COLUMN `workflow_status` ENUM(
            'draft',
            'submitted',
            'approved_by_da',
            'rejected_by_da',
            'sent_back_by_da',
            'rejected_by_gm',
            'sent_back_by_gm',
            'approved_by_gm',
            'under_review_slc',
            'approved_by_slc',
            'rejected_by_slc',
            'sent_back_by_slc'
        ) NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `user_incentive_applications`
        MODIFY COLUMN `workflow_status` ENUM(
            'draft',
            'submitted',
            'approved_by_da',
            'rejected_by_da',
            'sent_back_by_da',
            'approved_by_gm',
            'rejected_by_gm',
            'sent_back_by_gm',
            'approved_by_slc',
            'rejected_by_slc',
            'sent_back_by_slc'
        ) NULL DEFAULT 'draft'");
    }
};
