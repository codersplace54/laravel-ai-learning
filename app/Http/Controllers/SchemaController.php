<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class SchemaController extends Controller
{
    public function get_table_columns(Request $request)
    {

        $request->validate([
            'table' => 'required|string'
        ]);

        $table = $request->input('table');


        if (!Schema::hasTable($table)) {
            return response()->json([
                'error' => "Table '{$table}' does not exist."
            ], 404);
        }

        $columns = Schema::getColumnListing($table);

        return response()->json([
            'columns' => $columns
        ]);
    }
}
