<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class RoleController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /* ============================================================
     | GET ALL ROLES
     ============================================================ */
    #[OA\Get(
        path: "/api/roles",
        summary: "Get all roles",
        tags: ["Roles"],
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(response: 200, description: "Roles list")
        ]
    )]
    public function index()
    {
        $roles = Role::orderBy('id')->get();

        return $this->success($roles);
    }

    /* ============================================================
     | CREATE ROLE
     ============================================================ */
    #[OA\Post(
        path: "/api/roles",
        summary: "Create new role",
        tags: ["Roles"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "name", type: "string"),
                ],
                required: ["name"]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Role created"),
        ]
    )]
    public function store(Request $request)
    {
        $this->denyIfNoAdmin();

        $request->validate([
            'name' => 'required|string|unique:roles,name'
        ]);

        $role = Role::create([
            'name' => $request->name,
        ]);

        return $this->success($role, "Role created", 201);
    }

    /* ============================================================
     | SHOW ROLE
     ============================================================ */
    #[OA\Get(
        path: "/api/roles/{id}",
        summary: "Get role by ID",
        tags: ["Roles"],
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
            new OA\Response(response: 200, description: "Role details"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function show($id)
    {
        $this->denyIfNoAdmin();

        $role = Role::find($id);
        if (!$role) return $this->error("Not found", 404);

        return $this->success($role);
    }

    /* ============================================================
     | UPDATE ROLE
     ============================================================ */
    #[OA\Put(
        path: "/api/roles/{id}",
        summary: "Update role",
        tags: ["Roles"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "name", type: "string"),
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

        $role = Role::find($id);
        if (!$role) return $this->error("Not found", 404);

        if ($id <= 2) {
            return $this->error("System roles cannot be modified", 403);
        }

        $request->validate([
            'name' => 'required|string|unique:roles,name,' . $id,
        ]);

        $role->name = $request->name;
        $role->save();

        return $this->success($role, "Updated");
    }

    /* ============================================================
     | DELETE ROLE
     ============================================================ */
    #[OA\Delete(
        path: "/api/roles/{id}",
        summary: "Delete role",
        tags: ["Roles"],
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(response: 200, description: "Deleted"),
            new OA\Response(response: 404, description: "Not found"),
        ]
    )]
    public function destroy($id)
    {
        $this->denyIfNoAdmin();

        // SYSTEM ROLES PROTECTED
        if ($id <= 4) {
            return $this->error("This role cannot be deleted", 403);
        }

        $role = Role::find($id);
        if (!$role) return $this->error("Not found", 404);

        if ($role->users()->count() > 0) {
            return $this->error("Role has assigned users", 409);
        }

        $role->delete();

        return $this->success(null, "Deleted");
    }

    /* ============================================================
     | HELPERS
     ============================================================ */
    private function denyIfNoAdmin()
    {
        $user = auth()->user();

        if (!$user) abort(401);

        // доступ имеют только role_id 1 (owner) и 2 (admin)
        if (!in_array($user->role_id, [1, 2])) {
            abort(403, "Forbidden");
        }
    }
}
