<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;



class AuthController extends Controller
{
    use ApiResponse;
    public function __construct()
    {
    }

    private function sendVerification(User $user)
    {
        $code = random_int(100000, 999999);

        $user->update([
            'verification_code' => $code,
            'is_verified' => false,
        ]);

        Mail::send('emails.verify-code', [
            'user' => $user,
            'code' => $code,
        ], function ($m) use ($user) {
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

        $user = User::where('email', $request->email)
        ->whereNull('deleted_at')
        ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->error('Invalid credentials', 401);
        }

        if (!$user->is_verified) {
            return $this->error('Account not verified', 403);
        }

        if ((int)$user->role_id === 2) {
            $this->sendVerification($user);

            return $this->success(null, 'Verification code sent to email', 201);
        }

        $token = $user->createToken('api')->plainTextToken;

        return $this->success([
            'token' => $token,
            'user' => $user->load([
                'role',
                'subscription.plan',
                'subscription.plan.features',
                'subscription.plan.prices',
                'subscription.planPrice',
                'payments'
            ])
        ]);
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
     $validator = Validator::make($request->all(), [
        'name' => ['required', 'string', 'min:2', 'max:255'],
        'email' => ['required', 'string', 'email:rfc,dns', 'max:255', 'email' => [
            'required',
            'string',
            'email:rfc,dns',
            'max:255',
            Rule::unique('users', 'email')->withoutTrashed(),
        ],],
        'password' => ['required', 'string', 'confirmed', Password::min(6)],
    ], [
        'name.required' => 'I am required.',
        'email.required' => 'The email address is required.',
        'email.email' => 'Enter a valid email address.',
        'email.unique' => 'There is also a user at this email address.',
        'password.required' => 'The password is binding.',
        'password.confirmed' => 'Post confirmation not confirmed.',
    ]);

     if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    $data = $validator->validated();

    $user = User::create([
        'name' => trim($data['name']),
        'email' => mb_strtolower(trim($data['email'])),
        'password' => Hash::make($data['password']),
        'role_id' => 4
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
                'subscription.planPrice',
                'payments'
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
            'subscription.planPrice',
            'payments'
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
