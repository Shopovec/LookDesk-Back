<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PlanController extends Controller
{
    use ApiResponse;

    /* =========================================================
     | GET ALL PLANS (PUBLIC)
     ========================================================= */
    #[OA\Get(
        path: "/api/plans",
        summary: "Get available plans",
        tags: ["Plans"],
        parameters: [
            new OA\Parameter(
                name: "period",
                in: "query",
                description: "Filter prices by period",
                schema: new OA\Schema(type: "string", enum: ["monthly", "yearly"])
            )
        ],
        responses: [
            new OA\Response(response: 200, description: "Plans list")
        ]
    )]
    public function index(Request $request)
    {
        $plans = Plan::where('is_active', true)
            ->with([
                'features',
                'prices' => fn ($q) =>
                    $request->period
                        ? $q->where('period', $request->period)
                        : $q
            ])
            ->orderBy('id')
            ->get();

        return $this->success($plans);
    }
}