<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseRequest;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use OpenApi\Attributes as OA;

class EnterpriseRequestController extends Controller
{
    use ApiResponse;

    /* =========================================================
     | SEND ENTERPRISE REQUEST (PUBLIC)
     ========================================================= */
    #[OA\Post(
        path: "/api/enterprise-requests",
        summary: "Send enterprise request",
        tags: ["Enterprise"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                example: [
                    "name" => "John Doe",
                    "email" => "john@company.com",
                    "employees_range" => "101-200",
                    "requirements" => "Need SSO and custom integrations"
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Request sent"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'employees_range' => 'required|string|max:50',
            'requirements' => 'nullable|string',
        ]);

        $req = EnterpriseRequest::create($data);

        // notify admins
        $adminEmails = User::whereIn('role_id', [1, 2])->pluck('email')->toArray();

        if (!empty($adminEmails)) {
            Mail::raw(
                "New enterprise request\n\n".
                "Name: {$req->name}\n".
                "Email: {$req->email}\n".
                "Employees: {$req->employees_range}\n\n".
                "Requirements:\n{$req->requirements}",
                fn ($m) => $m->to($adminEmails)->subject('New Enterprise Request')
            );
        }

        return $this->success(null, 'Request sent');
    }

    /* =========================================================
     | LIST ENTERPRISE REQUESTS (ADMIN, PAGINATION)
     ========================================================= */
    #[OA\Get(
        path: "/api/admin/enterprise-requests",
        summary: "Get enterprise requests list",
        tags: ["Admin Enterprise"],
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(
                name: "page",
                in: "query",
                schema: new OA\Schema(type: "integer")
            ),
            new OA\Parameter(
                name: "per_page",
                in: "query",
                schema: new OA\Schema(type: "integer", default: 20)
            )
        ],
        responses: [
            new OA\Response(response: 200, description: "Paginated list"),
            new OA\Response(response: 403, description: "Forbidden")
        ]
    )]
    public function index(Request $request)
    {
        $user = auth()->user();

        if (!$user || !in_array($user->role_id, [1, 2])) {
            abort(403, 'Forbidden');
        }

        $perPage = min((int)$request->get('per_page', 20), 100);

        return $this->success(
            EnterpriseRequest::latest()->paginate($perPage)
        );
    }
}
