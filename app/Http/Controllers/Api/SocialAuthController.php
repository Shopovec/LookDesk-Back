<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SocialAuthService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class SocialAuthController extends Controller
{
    use ApiResponse;

    #[OA\Post(
        path: "/api/auth/social/google",
        summary: "Login via Google",
        tags: ["Auth"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "access_token", type: "string"),
                ],
                required: ["access_token"]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Logged in")
        ]
    )]
    public function google(Request $request, SocialAuthService $service)
    {
        $request->validate(['access_token' => 'required|string']);

        $data = $service->google($request->access_token);
        $user = $service->resolveUser($data);

        $token = $user->createToken('api')->plainTextToken;

        return $this->success([
            'token' => $token,
            'user' => $user->load([
                    'role',
                    'subscription.plan',
                    'subscription.plan.features',
                    'subscription.plan.prices',
                    'subscription.planPrice'
                ])
        ]);
    }

    #[OA\Post(
        path: "/api/auth/social/microsoft",
        summary: "Login via Microsoft",
        tags: ["Auth"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "access_token", type: "string"),
                ],
                required: ["access_token"]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Logged in")
        ]
    )]
    public function microsoft(Request $request, SocialAuthService $service)
    {
        $request->validate(['access_token' => 'required|string']);

        $data = $service->microsoft($request->access_token);
        $user = $service->resolveUser($data);

        $token = $user->createToken('api')->plainTextToken;

        return $this->success([
            'token' => $token,
            'user' => $user->load([
                    'role',
                    'subscription.plan',
                    'subscription.plan.features',
                    'subscription.plan.prices',
                    'subscription.planPrice'
                ])
        ]);
    }
}