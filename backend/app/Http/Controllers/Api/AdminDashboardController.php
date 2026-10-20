<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminDashboardService;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function __invoke(AdminDashboardService $dashboard): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Admin dashboard retrieved successfully.',
            'data' => $dashboard->get(),
        ]);
    }
}
