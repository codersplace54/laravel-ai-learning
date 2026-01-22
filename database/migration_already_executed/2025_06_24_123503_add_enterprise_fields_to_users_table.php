<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{


    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name_of_enterprise')->nullable()->after('id');
            $table->renameColumn('name', 'authorized_person_name')->after('name_of_enterprise');
            $table->renameColumn('email', 'email_id')->after('authorized_person_name');
            $table->string('pan')->nullable()->after('email_id');
            $table->string('mobile_no')->after('pan');
            $table->string('user_name')->collation('utf8mb4_bin')->unique()->after('mobile_no');
            $table->string('bin')->unique()->nullable()->after('user_name');
            $table->bigInteger('district_id')->nullable()->after('user_name');
            $table->bigInteger('subdivision_id')->nullable()->after('district_id');
            $table->bigInteger('ulb_id')->nullable()->after('subdivision_id');
            $table->bigInteger('ward_id')->nullable()->after('ulb_id');
            $table->text('registered_enterprise_address')->nullable()->after('bin');
            $table->string('registered_enterprise_city')->nullable()->after('registered_enterprise_address');
            $table->enum('user_type', ['individual', 'department', 'admin'])->default('individual')->after('registered_enterprise_city');
            $table->enum('status', ['active', 'blocked'])->default('active');
            $table->text('current_token')->nullable()->after('status');
        });
    }


    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('authorized_person_name', 'name');
            $table->renameColumn('email_id', 'email');
            $table->dropColumn([
                'name_of_enterprise',
                'pan',
                'mobile_no',
                'user_name',
                'bin',
                'registered_enterprise_address',
                'registered_enterprise_city',
                'user_type',
            ]);
        });
    }
};
