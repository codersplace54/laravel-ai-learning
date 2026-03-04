<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('information_wizards', function (Blueprint $table) {
            $table->id();
            $table->longText('title')->nullable();
            $table->longText('field_fee_structure_files')->nullable();
            $table->integer('delta')->nullable();
            $table->longText('field_noc_master_link')->nullable();
            $table->longText('field_wizard_category')->nullable();
            $table->longText('field_wizard_department')->nullable();
            $table->longText('field_wizard_fee_text')->nullable();
            $table->longText('field_wizard_noc_name')->nullable();
            $table->longText('field_wizard_notification')->nullable();
            $table->integer('delta_1')->nullable();
            $table->longText('field_wizard_process')->nullable();
            $table->longText('field_wizard_required_documents')->nullable();
            $table->longText('field_wizard_timeline')->nullable();
            $table->timestamps();
        });

        $path = database_path('sql/information_wizards.sql');

        if (!file_exists($path)) {
            throw new \RuntimeException("SQL file not found at: {$path}");
        }

        DB::unprepared(file_get_contents($path));
    }

    public function down(): void
    {
        Schema::dropIfExists('information_wizards');
    }
};
