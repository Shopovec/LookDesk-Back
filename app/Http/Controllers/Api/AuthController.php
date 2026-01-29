<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;
use Illuminate\Support\Facades\Mail;


class AuthController extends Controller
{
    use ApiResponse;

    private function sendVerification(User $user)
    {
        $code = random_int(100000, 999999);

        $user->update([
            'verification_code' => $code,
            'is_verified' => false,
        ]);

        Mail::raw("Your verification code: {$code}", function ($m) use ($user) {
            $m->to($user->email)->subject('Verify your account');
        });
    }

    /* ---------------------------------------------------------
     | LOGIN
     --------------------------------------------------------- */
    #[OA\Post(
     path: "/api/auth/login",
     summary: "Login user",
     tags: ["Auth"],
     requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "email", type: "string"),
                new OA\Property(property: "password", type: "string")
            ],
            required: ["email", "password"]
        )
    ),
     responses: [
        new OA\Response(response: 201, description: "Verification code sent to email"),
        new OA\Response(response: 200, description: "Success"),
        new OA\Response(response: 401, description: "Invalid credentials")
    ]
)]
    public function login(Request $request)
    {

        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->error('Invalid credentials', 401);
        }

        if (!$user->is_verified) {
            return $this->error('Account not verified', 403);
        }

    // admin shortcut
        if ($user->role_id == 2) {
            $this->sendVerification($user);

            return $this->success(null, 'Verification code sent to email', 201);
        } else {
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

    /* ---------------------------------------------------------
     | REGISTER
     --------------------------------------------------------- */
    #[OA\Post(
     path: "/api/auth/register",
     summary: "Register user",
     tags: ["Auth"],
     requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "name", type: "string"),
                new OA\Property(property: "email", type: "string"),
                new OA\Property(property: "password", type: "string"),
            ],
            required: ["name", "email", "password"]
        )
    ),
     responses: [
        new OA\Response(response: 201, description: "Verification code sent to email")
    ]
)]
    public function register(Request $request)
    {
        $request->validate([
            'name'     => 'required|string',
            'email'    => 'required|email|unique:users',
            'password' => 'required|string|min:6'
        ]);

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'role_id'  => 4 // default role: user
        ]);

        $this->sendVerification($user);

        return $this->success(null, 'Verification code sent to email', 201);
    }

    /* ---------------------------------------------------------
     | GET CURRENT USER
     --------------------------------------------------------- */
    #[OA\Get(
     path: "/api/auth/me",
     summary: "Get current user",
     tags: ["Auth"],
     security: [["sanctum" => []]],
     responses: [
        new OA\Response(response: 200, description: "Current user")
    ]
)]

    #[OA\Post(
    path: "/api/auth/verify",
    summary: "Verify user email",
    tags: ["Auth"],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "email", type: "string"),
                new OA\Property(property: "code", type: "string"),
            ],
            required: ["email", "code"]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: "Verified & logged in")
    ]
)]

    public function verify(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string'
        ]);

        $user = User::where('email', $request->email)
        ->where('verification_code', $request->code)
        ->first();

        if (!$user) {
            return $this->error('Invalid verification code', 422);
        }

        $user->update([
            'is_verified' => true,
            'verification_code' => null,
        ]);

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
    public function me()
    {
        return $this->success(auth()->user()->load([
            'role',
            'subscription.plan',
            'subscription.plan.features',
            'subscription.plan.prices',
            'subscription.planPrice'
        ]));
    }

    /* ---------------------------------------------------------
     | LOGOUT
     --------------------------------------------------------- */
    #[OA\Post(
     path: "/api/auth/logout",
     summary: "Logout",
     tags: ["Auth"],
     security: [["sanctum" => []]],
     responses: [
        new OA\Response(response: 200, description: "Logged out")
    ]
)]
    public function logout()
    {
        auth()->user()->tokens()->delete();

        return $this->success(null, "Logged out");
    }
}
