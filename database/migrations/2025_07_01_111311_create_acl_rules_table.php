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
        Schema::create('acl_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->string('role_code')->nullable();
            $table->string('district')->nullable();
            $table->string('sub_division')->nullable();
            $table->string('ulb')->nullable();
            $table->string('gp_vc_mc')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acl_rules');
    }
};
