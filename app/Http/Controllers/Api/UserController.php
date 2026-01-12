<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;

class UserController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        // только владелец или админ может управлять пользователями
        $this->middleware('auth:sanctum');
    }

    /* ============================================================
     | GET USERS LIST
     ============================================================ */
    #[OA\Get(
        path: "/api/users",
        summary: "Get users list",
        tags: ["Users"],
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "search", in: "query", schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "role_id", in: "query", schema: new OA\Schema(type: "integer")),
            new OA\Parameter(name: "page", in: "query", schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "List of users")
        ]
    )]
    public function index(Request $request)
    {
        $this->denyIfNoAdmin();

        $query = User::query()->with('role');

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
            });
        }

        if ($request->role_id) {
            $query->where('role_id', $request->role_id);
        }

        return $this->success($query->paginate(20));
    }

    /* ============================================================
     | GET USER BY ID
     ============================================================ */
    #[OA\Get(
        path: "/api/users/{id}",
        summary: "Get user by ID",
        tags: ["Users"],
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
            new OA\Response(response: 200, description: "User detail"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function show($id)
    {
        $this->denyIfNoAdmin();

        $user = User::with('role')->find($id);
        if (!$user) return $this->error("Not found", 404);

        return $this->success($user);
    }

    /* ============================================================
     | CREATE USER (ADMIN ONLY)
     ============================================================ */
    #[OA\Post(
        path: "/api/users",
        summary: "Create user",
        tags: ["Users"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: "object",
                properties: [
                    new OA\Property(property: "name", type: "string"),
                    new OA\Property(property: "email", type: "string"),
                    new OA\Property(property: "password", type: "string"),
                    new OA\Property(property: "role_id", type: "integer"),
                ],
                required: ["name", "email", "password", "role_id"]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "User created"),
        ]
    )]
    public function store(Request $request)
    {
        $this->denyIfNoAdmin();

        $request->validate([
            'name'     => 'required|string',
            'email'    => 'required|email|unique:users',
            'password' => 'required|string|min:6',
            'role_id'  => 'required|integer|exists:roles,id'
        ]);

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'role_id'  => $request->role_id,
            'password' => Hash::make($request->password),
        ]);

        return $this->success($user->load('role'), "Created", 201);
    }

    /* ============================================================
     | UPDATE USER
     ============================================================ */
    #[OA\Put(
        path: "/api/users/{id}",
        summary: "Update user",
        tags: ["Users"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                type: "object",
                properties: [
                    new OA\Property(property: "name", type: "string", nullable: true),
                    new OA\Property(property: "email", type: "string", nullable: true),
                    new OA\Property(property: "password", type: "string", nullable: true),
                    new OA\Property(property: "role_id", type: "integer", nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Updated"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function update(Request $request, $id)
    {
        $this->denyIfNoAdmin();

        $user = User::find($id);
        if (!$user) return $this->error("Not found", 404);

        if ($request->name) {
            $user->name = $request->name;
        }

        if ($request->email) {
            $request->validate(['email' => 'email|unique:users,email,' . $id]);
            $user->email = $request->email;
        }

        if ($request->password) {
            $user->password = Hash::make($request->password);
        }

        if ($request->role_id) {
            $request->validate(['role_id' => 'integer|exists:roles,id']);
            $user->role_id = $request->role_id;
        }

        $user->save();

        return $this->success($user->load('role'), "Updated");
    }

    /* ============================================================
     | DELETE USER
     ============================================================ */
    #[OA\Delete(
        path: "/api/users/{id}",
        summary: "Delete user",
        tags: ["Users"],
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(response: 200, description: "Deleted"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function destroy($id)
    {
        $this->denyIfNoAdmin();

        $user = User::find($id);
        if (!$user) return $this->error("Not found", 404);

        $user->delete();

        return $this->success(null, "Deleted");
    }

    /* ============================================================
     | CHANGE USER ROLE
     ============================================================ */
    #[OA\Post(
        path: "/api/users/{id}/role",
        summary: "Change user role",
        tags: ["Users"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "role_id", type: "integer"),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Role changed"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function changeRole(Request $request, $id)
    {
        $this->denyIfNoAdmin();

        $request->validate([
            'role_id' => 'required|integer|exists:roles,id'
        ]);

        $user = User::find($id);
        if (!$user) return $this->error("Not found", 404);

        $user->role_id = $request->role_id;
        $user->save();

        return $this->success($user->load('role'), "Role updated");
    }

    /* ============================================================
     | HELPERS — ACCESS CHECK
     ============================================================ */
    private function denyIfNoAdmin()
    {
        $user = auth()->user();

        if (!$user) {
            abort(401, "Unauthorized");
        }

        // 1 — Owner
        // 2 — Admin
        if (!in_array($user->role_id, [1, 2])) {
            abort(403, "Forbidden");
        }
    }
}
