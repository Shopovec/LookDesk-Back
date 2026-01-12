<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    use ApiResponse;

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
            new OA\Response(response: 200, description: "Success"),
            new OA\Response(response: 401, description: "Invalid credentials")
        ]
    )]
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->error('Invalid credentials', 401);
        }

        $token = $user->createToken('api')->plainTextToken;

        return $this->success([
            'token' => $token,
            'user'  => $user->load('role')
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
            new OA\Response(response: 201, description: "User created")
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

        return $this->success($user, 'User created', 201);
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
    public function me()
    {
        return $this->success(auth()->user()->load('role'));
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
