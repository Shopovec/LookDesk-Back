<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\SupportContactMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use OpenApi\Attributes as OA;

class SupportController extends Controller
{
    /* ============================================================
     | CONTACT SUPPORT
     ============================================================ */
    #[OA\Post(
     path: "/api/support/contact",
     summary: "Contact support (send email to all users with role_id 2 and 7)",
     tags: ["Support"],
     security: [["sanctum" => []]],
     requestBody: new OA\RequestBody(
        required: true,
        content: [
            new OA\MediaType(
                mediaType: "application/json",
                schema: new OA\Schema(
                    type: "object",
                    required: ["name", "email", "subject", "message"],
                    properties: [
                        new OA\Property(property: "name", type: "string", example: "John Doe"),
                        new OA\Property(property: "email", type: "string", example: "john@mail.com"),
                        new OA\Property(property: "subject", type: "string", example: "Need help"),
                        new OA\Property(property: "message", type: "string", example: "Hello, I have an issue..."),
                    ]
                )
            ),
            new OA\MediaType(
                mediaType: "multipart/form-data",
                schema: new OA\Schema(
                    type: "object",
                    required: ["name", "email", "subject", "message"],
                    properties: [
                        new OA\Property(property: "name", type: "string"),
                        new OA\Property(property: "email", type: "string"),
                        new OA\Property(property: "subject", type: "string"),
                        new OA\Property(property: "message", type: "string"),
                    ]
                )
            ),
        ]
    ),
     responses: [
        new OA\Response(response: 200, description: "Sent"),
        new OA\Response(response: 422, description: "Validation error"),
    ]
)]
    public function contact(Request $request)
    {
        // на случай если фронт пришлёт "subjext"
        if (!$request->has('subject') && $request->has('subjext')) {
            $request->merge(['subject' => $request->input('subjext')]);
        }

        $data = $request->validate([
            'name'    => ['required', 'string', 'max:120'],
            'email'   => ['required', 'email', 'max:190'],
            'subject' => ['required', 'string', 'max:190'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        // получатели: все с role_id 2 и 7 (soft-deleted не берем)
        $recipients = User::query()
        ->whereIn('role_id', [2, 7])
        ->when(method_exists(User::class, 'bootSoftDeletes') || in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses_recursive(User::class)),
            fn($q) => $q->whereNull('deleted_at')
        )
        ->pluck('email')
        ->filter()
        ->unique()
        ->values()
        ->all();

        if (empty($recipients)) {
            return response()->json([
                'success' => false,
                'message' => 'No support recipients found',
            ], 422);
        }

        // чтобы не светить список емейлов всем получателям — отправляем через BCC
        $payload = [
            ...$data,
            'user_id' => optional(auth()->user())->id,
            'ip'      => $request->ip(),
        ];

        Mail::to(config('mail.from.address'))
        ->bcc($recipients)
        ->send(new SupportContactMail($payload));

       /* Mail::to(config('mail.from.address'))
        ->bcc(['mishace282@gmail.SupportContactMail'])
        ->send(new SupportContactMail($payload));    */

        return response()->json([
            'success' => true,
            'message' => 'Sent',
            'data'    => [],
        ], 200);
    }
}