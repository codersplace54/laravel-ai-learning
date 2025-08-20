<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
       public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name_of_enterprise')->after('id');
            $table->renameColumn('name', 'authorized_person_name')->after('name_of_enterprise');
            $table->renameColumn('email', 'email_id')->after('authorized_person_name');
            $table->string('pan')->nullable()->after('email_id');
            $table->string('mobile_no')->after('pan');
            $table->string('user_name')->unique()->after('mobile_no');
            $table->string('bin')->unique()->nullable()->after('user_name');
            $table->text('registered_enterprise_address')->after('bin');
            $table->string('registered_enterprise_city')->after('registered_enterprise_address');
            $table->enum('user_type', ['Individual'])->default('Individual')->after('registered_enterprise_city');
            $table->enum('status', ['active', 'blocked'])->default('active');
            $table->text('current_token')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
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
