<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class HealthController extends Controller
{
    /**
     * Report whether the API is up and can reach its database.
     */
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            Log::warning('health.database_down', ['error' => $exception->getMessage()]);

            return response()->json(['status' => 'error', 'database' => 'down'], 503);
        }

        return response()->json(['status' => 'ok', 'database' => 'up']);
    }
}
