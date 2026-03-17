<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // keep approved_by_gm temporarily for migration
        DB::statement("ALTER TABLE `user_incentive_applications`
        MODIFY COLUMN `workflow_status` ENUM(
            'draft',
            'submitted',
            're_submitted',
            'approved_by_da',
            'rejected_by_da',
            'sent_back_by_da',
            'rejected_by_gm',
            'sent_back_by_gm',
            'approved_by_gm',
            'noc_issued',
            'claim_approved_by_gm',
            'claim_approved_by_slc',
            'under_review_slc',
            'rejected_by_slc',
            'sent_back_by_slc'
        ) NULL DEFAULT 'draft'");

        DB::table('user_incentive_applications')
            ->where('workflow_status', 'approved_by_gm')
            ->update(['workflow_status' => 'noc_issued']);

        DB::table('incentive_workflow_histories')
            ->where('from_status', 'approved_by_gm')
            ->update(['from_status' => 'noc_issued']);
            
        DB::table('incentive_workflow_histories')
            ->where('to_status', 'approved_by_gm')
            ->update(['to_status' => 'noc_issued']);
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
            'rejected_by_gm',
            'sent_back_by_gm',
            'approved_by_gm',
            'under_review_slc',
            'rejected_by_slc',
            'sent_back_by_slc'
        ) NULL DEFAULT 'draft'");
    }
};
