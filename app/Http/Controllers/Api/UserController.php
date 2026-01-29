<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Models\Event;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;
use Illuminate\Support\Facades\Mail;

class UserController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        // только владелец или админ может управлять пользователями
        $this->middleware('auth:sanctum');
    }

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
        new OA\Parameter(name: "username", in: "query", schema: new OA\Schema(type: "string")),
        new OA\Parameter(name: "email", in: "query", schema: new OA\Schema(type: "string")),
        new OA\Parameter(name: "role_id", in: "query", schema: new OA\Schema(type: "integer")),
        new OA\Parameter(name: "function_id", in: "query", schema: new OA\Schema(type: "integer")),
        new OA\Parameter(name: "page", in: "query", schema: new OA\Schema(type: "integer")),
    ],
    responses: [
        new OA\Response(response: 200, description: "List of users")
    ]
)]
    public function index(Request $request)
    {

        $query = User::query()->with('role', 'functions');

        if (auth()->user()->hasRole('user') || auth()->user()->hasRole('client')) {
            $query->where('client_creator_id', auth()->user()->id);
        }

        if ($request->username) {
            $query->where('name', 'like', "%{$request->username}%");
        }

        if ($request->email) {
            $query->where('email', 'like', "%{$request->email}%");
        }

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                ->orWhere('email', 'like', "%{$request->search}%");
            });
        }

        if ($request->role_id) {
            $query->where('role_id', $request->role_id);
        }

        if ($request->function_id) {
            $q->whereHas('functions', fn($x) =>
                $x->where('functions.id', $request->function_id)
            );
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
        $user = User::with('role', 'functions')->find($id);
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
        content: new OA\MediaType(
            mediaType: "multipart/form-data",
            schema: new OA\Schema(
                type: "object",
                properties: [
                   new OA\Property(property: "name", type: "string"),
                   new OA\Property(property: "email", type: "string"),
                   new OA\Property(property: "password", type: "string"),
                   new OA\Property(property: "role_id", type: "integer"),
                   new OA\Property(
                    property: "functions[0][id]",
                    type: "integer",
                    example: "1"
                ),


                   new OA\Property(
                    property: "functions[1][id]",
                    type: "integer",
                    example: "1"
                ),

               ]
           )
        )
    ),
     responses: [
        new OA\Response(response: 201, description: "Verification code sent to user email"),
    ]
)]
    public function store(Request $request)
    {

        if (auth()->user()->hasRole('user') || auth()->user()->hasRole('client')) {
            $request->validate([
                'name'     => 'required|string',
                'email'    => 'required|email|unique:users',
                'password' => 'required|string|min:6',
                'role_id'  => 'required|integer|exists:roles,id', 
                'functions'          => 'required|array',
                'functions.*.id'   => 'required|integer',
            ]);
        } else {
            $request->validate([
                'name'     => 'required|string',
                'email'    => 'required|email|unique:users',
                'password' => 'required|string|min:6',
                'role_id'  => 'required|integer|exists:roles,id', 
                'functions'          => 'nullable|array',
                'functions.*.id'   => 'nullable|integer',
            ]);
        }
        

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'role_id'  => $request->role_id,
            'password' => Hash::make($request->password),
        ]);

        $functions  = collect($request->functions)->pluck('id')->toArray();

        $user->functions()->sync($functions ?? []);
        $this->sendVerification($user);
        Event::create([
            'user_id' => auth()->user()->id,
            'action'  => 'added',
            'model' => 'user',
            'model_id' => $user->id
        ]);

        return $this->success(null, 'Verification code sent to user email', 201);
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
        required: true,
        content: new OA\MediaType(
            mediaType: "multipart/form-data",
            schema: new OA\Schema(
                type: "object",
                properties: [
                   new OA\Property(property: "name", type: "string"),
                   new OA\Property(property: "email", type: "string"),
                   new OA\Property(property: "password", type: "string"),
                   new OA\Property(property: "role_id", type: "integer"),
                   new OA\Property(
                    property: "functions[0][id]",
                    type: "integer",
                    example: "1"
                ),


                   new OA\Property(
                    property: "functions[1][id]",
                    type: "integer",
                    example: "1"
                ),

               ]
           )
        )
    ),
     responses: [
        new OA\Response(response: 200, description: "Updated"),
        new OA\Response(response: 404, description: "Not found"),
    ]
)]
    public function update(Request $request, $id)
    {

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

        $functions  = collect($request->functions)->pluck('id')->toArray();

        $user->functions()->sync($functions ?? $user->functions->pluck('id')->toArray());

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
        $user = User::find($id);
        if (!$user) return $this->error("Not found", 404);

        Event::create([
            'user_id' => auth()->user()->id,
            'action'  => 'removed',
            'model' => 'user',
            'model_id' => $user->id,
            'deleted_title' => $user->name
        ]);

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
        $request->validate([
            'role_id' => 'required|integer|exists:roles,id'
        ]);

        $user = User::find($id);
        if (!$user) return $this->error("Not found", 404);

        $user->role_id = $request->role_id;
        $user->save();

        return $this->success($user->load('role'), "Role updated");
    }
}
