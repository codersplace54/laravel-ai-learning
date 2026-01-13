<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{

    public function up(): void
    {
        DB::table('departments')
            ->whereIn('name', [
                'Factories & Boilers Organisation',
                'Directorate of Labour',
                'Legal Metrology',
                'Tripura State Pollution Control Board',
            ])
            ->update([
                'is_inspection_dept' => 'yes',
            ]);
    }


    public function down(): void
    {
        DB::table('departments')
            ->whereIn('name', [
                'Factories & Boilers Organisation',
                'Directorate of Labour',
                'Legal Metrology',
                'Tripura State Pollution Control Board',
            ])
            ->update([
                'is_inspection_dept' => 'no',
            ]);
    }
};
