<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\PlanFeature;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class PlanController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    private function adminOnly()
    {
    }

    /* =====================================================
     | LIST PLANS
     ===================================================== */
    #[OA\Get(
     path: "/api/admin/plans",
     tags: ["Admin Plans"],
    security: [["sanctum" => []]],
    responses: [
        new OA\Response(
            response: 200,
            description: "Paginated plans list",
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "success", type: "boolean"),
                    new OA\Property(
                        property: "data",
                        type: "object",
                        properties: [
                            new OA\Property(property: "current_page", type: "integer"),
                            new OA\Property(property: "data", type: "array", items: new OA\Items(type: "object")),
                            new OA\Property(property: "last_page", type: "integer"),
                            new OA\Property(property: "total", type: "integer")
                        ]
                    )
                ]
            )
        )
    ]
 )]
    public function index()
    {
        $this->adminOnly();

        return $this->success(
            Plan::with(['prices', 'features'])->paginate(20)
        );
    }

    /* =====================================================
     | SHOW PLAN
     ===================================================== */
 #[OA\Get(
    path: "/api/admin/plans/{id}",
    summary: "Get plan by ID",
    tags: ["Admin Plans"],
    security: [["sanctum" => []]],
    parameters: [
        new OA\Parameter(
            name: "id",
            in: "path",
            required: true,
            description: "Plan ID",
            schema: new OA\Schema(type: "integer")
        )
    ],
    responses: [
        new OA\Response(response: 200, description: "Plan data"),
        new OA\Response(response: 404, description: "Not found")
    ]
)]
    public function show($id)
    {
        $this->adminOnly();

        return $this->success(
            Plan::with(['prices', 'features'])->findOrFail($id)
        );
    }

    /* =====================================================
     | CREATE PLAN + PRICES + FEATURES
     ===================================================== */
    #[OA\Post(
     path: "/api/admin/plans",
     summary: "Create plan with prices and features",
     tags: ["Admin Plans"],
     security: [["sanctum" => []]],
     requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            type: "object",
            example: [
                "code" => "starter",
                "name" => "Starter",
                "description" => "Perfect for small teams",
                "status" => "available",
                "is_active" => true,
                "prices" => [
                    [
                        "period" => "monthly",
                        "price" => 30,
                        "per_user" => false,
                        "trial_days" => 30
                    ],
                    [
                        "period" => "yearly",
                        "price" => 300,
                        "per_user" => false,
                        "trial_days" => 30
                    ]
                ],
                "features" => [
                    ["title" => "30-days free trial", "sort" => 1],
                    ["title" => "Up to 5 users", "sort" => 2],
                    ["title" => "Live Chat", "sort" => 3]
                ]
            ]
        )
    ),
     responses: [
        new OA\Response(response: 201, description: "Created"),
        new OA\Response(response: 422, description: "Validation error")
    ]
)]
    public function store(Request $request)
    {
        $this->adminOnly();

        $data = $request->validate([
            'code' => 'required|string|unique:plans,code',
            'name' => 'required|string',
            'description' => 'nullable|string',
            'status' => 'required|in:available,request',
            'is_active' => 'boolean',

            'prices' => 'required|array|min:1',
            'prices.*.period' => 'required|in:monthly,yearly',
            'prices.*.price' => 'required|numeric|min:0',
            'prices.*.currency' => 'string',
            'prices.*.per_user' => 'boolean',
            'prices.*.min_users' => 'integer|min:1',
            'prices.*.max_users' => 'nullable|integer',
            'prices.*.trial_days' => 'integer|min:0',

            'features' => 'array',
            'features.*.title' => 'required|string',
            'features.*.sort' => 'integer',
        ]);

        DB::transaction(function () use ($data, &$plan) {
            $plan = Plan::create($data);

            foreach ($data['prices'] as $price) {
                $plan->prices()->create($price);
            }

            if (!empty($data['features'])) {
                foreach ($data['features'] as $feature) {
                    $plan->features()->create($feature);
                }
            }
        });

        return $this->success(
            $plan->load(['prices', 'features']),
            'Created',
            201
        );
    }

    /* =====================================================
     | UPDATE PLAN + SYNC PRICES + FEATURES
     ===================================================== */
   #[OA\Put(
    path: "/api/admin/plans/{id}",
    summary: "Update plan with prices and features",
    tags: ["Admin Plans"],
    security: [["sanctum" => []]],
    parameters: [
        new OA\Parameter(
            name: "id",
            in: "path",
            required: true,
            schema: new OA\Schema(type: "integer")
        )
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            type: "object",
            example: [
                "name" => "Starter",
                "description" => "Perfect for small teams",
                "status" => "available",
                "is_active" => true,
                "prices" => [
                    [
                        "id" => 1,
                        "period" => "monthly",
                        "price" => 35,
                        "per_user" => false,
                        "trial_days" => 14
                    ]
                ],
                "features" => [
                    [
                        "id" => 5,
                        "title" => "Live Chat",
                        "sort" => 1
                    ]
                ]
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: "Updated"),
        new OA\Response(response: 404, description: "Not found")
    ]
)]
    public function update(Request $request, $id)
    {
        $this->adminOnly();

        $plan = Plan::findOrFail($id);

        $data = $request->validate([
            'name' => 'string',
            'description' => 'nullable|string',
            'status' => 'in:available,request',
            'is_active' => 'boolean',

            'prices' => 'array',
            'prices.*.id' => 'nullable|exists:plan_prices,id',
            'prices.*.period' => 'required|in:monthly,yearly',
            'prices.*.price' => 'required|numeric|min:0',
            'prices.*.currency' => 'string',
            'prices.*.per_user' => 'boolean',
            'prices.*.min_users' => 'integer|min:1',
            'prices.*.max_users' => 'nullable|integer',
            'prices.*.trial_days' => 'integer|min:0',

            'features' => 'array',
            'features.*.id' => 'nullable|exists:plan_features,id',
            'features.*.title' => 'required|string',
            'features.*.sort' => 'integer',
        ]);

        DB::transaction(function () use ($plan, $data) {

            $plan->update($data);

            /* ---- PRICES ---- */
            $priceIds = [];
            foreach ($data['prices'] ?? [] as $price) {
                if (!empty($price['id'])) {
                    PlanPrice::where('id', $price['id'])->update($price);
                    $priceIds[] = $price['id'];
                } else {
                    $priceIds[] = $plan->prices()->create($price)->id;
                }
            }
            $plan->prices()->whereNotIn('id', $priceIds)->delete();

            /* ---- FEATURES ---- */
            $featureIds = [];
            foreach ($data['features'] ?? [] as $feature) {
                if (!empty($feature['id'])) {
                    PlanFeature::where('id', $feature['id'])->update($feature);
                    $featureIds[] = $feature['id'];
                } else {
                    $featureIds[] = $plan->features()->create($feature)->id;
                }
            }
            $plan->features()->whereNotIn('id', $featureIds)->delete();
        });

        return $this->success(
            $plan->load(['prices', 'features']),
            'Updated'
        );
    }

    /* =====================================================
     | DELETE PLAN (CASCADE)
     ===================================================== */
 #[OA\Delete(
    path: "/api/admin/plans/{id}",
    summary: "Delete plan",
    tags: ["Admin Plans"],
    security: [["sanctum" => []]],
    parameters: [
        new OA\Parameter(
            name: "id",
            in: "path",
            required: true,
            schema: new OA\Schema(type: "integer")
        )
    ],
    responses: [
        new OA\Response(response: 200, description: "Deleted"),
        new OA\Response(response: 404, description: "Not found")
    ]
)]
    public function destroy($id)
    {
        $this->adminOnly();

        Plan::findOrFail($id)->delete();

        return $this->success(null, 'Deleted');
    }
}
