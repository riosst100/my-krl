<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SyncLogResource;
use App\Services\AdminStatsService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(AdminStatsService $stats): JsonResponse
    {
        $data = $stats->dashboard();

        return response()->json([
            'data' => [
                ...$data,
                'last_sync' => $data['last_sync'] ? new SyncLogResource($data['last_sync']) : null,
                'last_station_sync' => $data['last_station_sync'] ? new SyncLogResource($data['last_station_sync']) : null,
            ],
        ]);
    }
}
