<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OwnerChangeRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class OwnerChangeRequestController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /* ============================================================
     | SUBMIT REQUEST
     ============================================================ */

    #[OA\Post(
        path: "/api/owner-change",
        summary: "Submit owner change request",
        tags: ["Owner Change"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["new_owner_email"],
                properties: [
                    new OA\Property(property: "new_owner_email", type: "string"),
                    new OA\Property(property: "comment", type: "string")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Request submitted")
        ]
    )]
    public function store(Request $request)
    {
        $request->validate([
            'new_owner_email' => 'required|email',
            'comment' => 'nullable|string'
        ]);

        $item = OwnerChangeRequest::create([
            'requested_by' => auth()->id(),
            'new_owner_email' => $request->new_owner_email,
            'comment' => $request->comment
        ]);

        return response()->json([
            'success' => true,
            'data' => $item
        ]);
    }

    /* ============================================================
     | LIST REQUESTS
     ============================================================ */

    #[OA\Get(
        path: "/api/owner-change",
        summary: "Get owner change requests",
        tags: ["Owner Change"],
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(response: 200, description: "List")
        ]
    )]
    public function index()
    {
        $items = OwnerChangeRequest::with('requester','approver')
            ->latest()
            ->get();

        return response()->json($items);
    }

    /* ============================================================
     | APPROVE REQUEST
     ============================================================ */

    #[OA\Post(
        path: "/api/owner-change/{id}/approve",
        summary: "Approve owner change",
        tags: ["Owner Change"],
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
            new OA\Response(response: 200, description: "Approved")
        ]
    )]
    public function approve($id)
    {
        DB::transaction(function () use ($id) {

            $request = OwnerChangeRequest::findOrFail($id);

            if ($request->status !== 'pending') {
                abort(400, 'Already processed');
            }

            $newOwner = User::where('email', $request->new_owner_email)->firstOrFail();

            // обновляем роль
            $newOwner->update([
                'role_id' => 1
            ]);

            $request->update([
                'status' => 'approved',
                'approved_by' => auth()->id(),
                'approved_at' => now()
            ]);
        });

        return response()->json(['success' => true]);
    }

    /* ============================================================
     | REJECT REQUEST
     ============================================================ */

    #[OA\Post(
        path: "/api/owner-change/{id}/reject",
        summary: "Reject owner change",
        tags: ["Owner Change"],
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
            new OA\Response(response: 200, description: "Rejected")
        ]
    )]
    public function reject($id)
    {
        $request = OwnerChangeRequest::findOrFail($id);

        $request->update([
            'status' => 'rejected',
            'approved_by' => auth()->id(),
            'approved_at' => now()
        ]);

        return response()->json(['success' => true]);
    }
}