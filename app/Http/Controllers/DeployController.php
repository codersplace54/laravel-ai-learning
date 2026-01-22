<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class DeployController extends Controller
{
    public function deploy(Request $request)
    {
        if ($request->header('X-DEPLOY-TOKEN') !== config('deploy.token')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        Artisan::call('deploy:backend');

        return response()->json([
            'message' => 'Deployment triggered successfully',
            'output' => Artisan::output(),
        ]);
    }
}
