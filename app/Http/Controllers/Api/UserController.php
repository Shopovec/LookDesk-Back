<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Models\Event;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Crypt;
use App\Models\ChatSession;
use App\Models\ChatMessage;
use App\Models\AiDocumentStat;
use App\Models\Subscription;
use App\Models\Document;
use App\Models\DocumentTranslation;
use PhpOffice\PhpWord\IOFactory;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    use ApiResponse;
    public function __construct()
    {

    }

    private string $redirectUrl = 'https://admin.lookdesk.ai/';

    /* ============================================================
     | SEND INVITE (AUTH)
     ============================================================ */

   #[OA\Post(
     path: "/api/team-invitations",
     summary: "Invite user to team by email (sends accept link)",
     tags: ["Team Invitations"],
     security: [["sanctum" => []]],
     requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["name", "email", "role", "functions"],
            properties: [
                new OA\Property(property: "name", type: "string", example: "name"),
                new OA\Property(property: "email", type: "string", example: "user@example.com"),
                new OA\Property(property: "role", type: "string", example: "user"),
                new OA\Property(
                    property: "functions",
                    type: "array",
                    items: new OA\Items(
                        type: "object",
                        properties: [
                            new OA\Property(property: "id", type: "integer", example: 1),
                        ]
                    )
                ),
            ]
        )
    ),
     responses: [
        new OA\Response(response: 200, description: "Invitation sent"),
        new OA\Response(response: 422, description: "Validation error"),
    ]
)]
   public function teamInvite(Request $request)
   {
    $authUser = auth()->user();
    $user = auth()->user();
    if (!$user || $user->hasRole('user') || $user->hasRole('editor') || $user->hasRole('accountant')) {
        abort(403, "Forbidden");
    }

    $request->validate([
        'name' => 'required|string',
        'email' => [
            'required',
            'email',
            Rule::unique('users', 'email')->withoutTrashed(),
        ],
        'role' => 'required|string|exists:roles,name',
        'functions' => 'nullable|array',
        'functions.*.id' => 'nullable|integer',
    ]);


    if ($user->hasRole('admin') &&  in_array(strtolower($request->role),['owner', 'admin']) ) {
        abort(403, "Forbidden");
    }

    

    $role = Role::where('name', strtolower($request->role))->firstOrFail();

    $inviteToken = Str::random(64);

    $user = User::create([
        'name' => $request->name,
        'email' => $request->email,
        'password' => '',
        'role_id' => $role->id,
        'client_creator_id' => $authUser->id,
        'is_verified' => false,
        'verification_code' => $inviteToken,
    ]);

    $functions = collect($request->functions ?? [])->pluck('id')->toArray();
    $user->functions()->sync($functions);

    $acceptUrl = url('/api/team-invitations/accept') . '?' . http_build_query([
        'token' => $inviteToken
    ]);

    Mail::raw(
        "You have been invited.\n\nOpen the link to activate your account:\n{$acceptUrl}",
        function ($message) use ($user) {
            $message->to($user->email)
            ->subject('Invitation to join');
        }
    );

    Event::create([
        'user_id' => $authUser->id,
        'action'  => 'invited',
        'model'   => 'user',
        'model_id'=> $user->id,
    ]);

    return $this->success([
        'invite_link' => $acceptUrl,
        'user_id' => $user->id,
    ], 'Invitation sent', 200);
}

#[OA\Get(
path: "/api/team-invitations/accept",
summary: "Accept team invitation (public link). Activates invited user and redirects to admin.lookdesk.ai",
tags: ["Team Invitations"],
parameters: [
    new OA\Parameter(name: "token", in: "query", required: true, schema: new OA\Schema(type: "string"))
],
responses: [
    new OA\Response(response: 302, description: "Redirect to https://admin.lookdesk.ai/"),
    new OA\Response(response: 400, description: "Invalid link"),
]
)]
public function accept(Request $request)
{
    try {
        $token = (string) $request->query('token', '');
        if (!$token) {
            return redirect()->away($this->redirectUrl . '?invite=invalid');
        }


        $user = User::where('verification_code', $token)->first();

        if (!$user) {
            return redirect()->away($this->redirectUrl . '?invite=invalid');
        }
        $plainPassword = Str::random(10);
        $user->is_verified = true;
        $user->verification_code = null;
        $user->password = Hash::make($plainPassword);
        $user->save();

        Mail::raw("
         You have been invited.

         Login: {$user->email}
         Password: {$plainPassword}

         Please login and change your password.
         ", function ($message) use ($user) {
            $message->to($user->email)
            ->subject('Your Account Credentials');
        });


        Event::create([
            'user_id' => $user->id,
            'action'  => 'accepted_invite',
            'model'   => 'user',
            'model_id'=> $user->id,
        ]);

        return redirect()->away($this->redirectUrl . '?invite=accepted');
    } catch (\Throwable $e) {
        return redirect()->away($this->redirectUrl . '?invite=invalid');
    }
}

private function sendVerification(User $user)
{
    $code = random_int(100000, 999999);

    Mail::send('emails.verify-code', [
        'user' => $user,
        'code' => $code,
    ], function ($m) use ($user) {
        $m->to($user->email)->subject('Verify your account');
    });
}

private function aiEconomics($owner_id): array
{
    $daysAgo = now()->subDays(30);

    $team_users_ids = User::where('client_creator_id', $owner_id)->pluck('id')->toArray();

    $team_users_ids[] = $owner_id;

    // Revenue query
    $revenueQuery = Subscription::query()
    ->whereIn('subscriptions.status', ['active', 'canceled', 'trialing'])
    ->where('subscriptions.created_at', '>=', $daysAgo)
    ->join('plan_prices', 'subscriptions.plan_price_id', '=', 'plan_prices.id');

    $revenueQuery->whereIn('subscriptions.user_id', $team_users_ids);
    $totalRevenue = (float) $revenueQuery->sum('plan_prices.price');

    // AI answers query
    $answersQuery = ChatMessage::query()
    ->where('role', 'assistant')
    ->where('created_at', '>=', $daysAgo);
    $answersQuery->whereHas('session', fn ($q) => $q->whereIn('user_id', $team_users_ids));
    $aiAnswers = (int) $answersQuery->count();

    $aiCost = round($aiAnswers * (float) config('ai.cost_per_answer', 0.002), 2);

    $margin = $totalRevenue > 0
    ? round((($totalRevenue - $aiCost) / $totalRevenue) * 100, 1)
    : 0;

    return [
        'total_revenue' => round($totalRevenue, 2),
        'total_ai_cost' => $aiCost,
        'net_margin'    => $margin,
    ];
}

private function trashedUsersLast30Days($owner_id): int
{

    $from = now()->subDays(30);

    return User::onlyTrashed()->where('created_at', '>=', $from)->where('client_creator_id', $owner_id)->count();
}

private function activeUsersLast30Days($owner_id): int
{
    $team_users_ids = User::where('client_creator_id', $owner_id)->pluck('id')->toArray();

    $from = now()->subDays(30);

    return ChatSession::where('created_at', '>=', $from)
    ->whereIn('user_id', $team_users_ids)
    ->distinct('user_id')
    ->count('user_id');
}

#[OA\Get(
path: "/api/users",
summary: "Get users list",
tags: ["Users"],
security: [["sanctum" => []]],
parameters: [
    new OA\Parameter(name: "search", in: "query", schema: new OA\Schema(type: "string")),
    new OA\Parameter(name: "username", in: "query", schema: new OA\Schema(type: "string")),
    new OA\Parameter(name: "email", in: "query", schema: new OA\Schema(type: "string")),
    new OA\Parameter(
        name: "status",
        in: "query",
        description: "User status filter. Example: status=active,pending",
        style: "form",
        explode: false,
        schema: new OA\Schema(
            type: "array",
            items: new OA\Items(
                type: "string",
                enum: ["active", "inactive", "pending", "deleted"]
            )
        )
    ),
    new OA\Parameter(name: "role_id", in: "query", schema: new OA\Schema(type: "integer")),
    new OA\Parameter(name: "role", in: "query", schema: new OA\Schema(type: "string")),
    new OA\Parameter(name: "function_id", in: "query", schema: new OA\Schema(type: "integer")),
    new OA\Parameter(name: "page", in: "query", schema: new OA\Schema(type: "integer")),
],
responses: [
    new OA\Response(response: 200, description: "List of users")
]
)]
public function index(Request $request)
{
    $query = User::query()->withCount([
        'documents as documents_count',
        'sessions as sessions_chat_count',
        'documentsTeam as documents_team_count',
        'sessionsTeam as sessions_team_chat_count',
        'clientUsers as client_users_count',
    ])->with(
        'role',
        'functions',
        'subscription.plan',
        'subscription.plan.features',
        'subscription.plan.prices',
        'subscription.planPrice',
        'payments'
    );

    $me = auth()->user();

    if ($me->hasRole('user') || $me->hasRole('client') || $me->hasRole('owner') || $me->hasRole('superadmin')) {
        $query->where(function ($q) use ($me) {
            $q->where('client_creator_id', $me->id)
            ->orWhere('id', $me->id);
        });
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

    if ($request->filled('status')) {
        $statuses = $request->input('status', []);

    // Поддержка и массива, и строки "active,pending"
        if (!is_array($statuses)) {
            $statuses = explode(',', $statuses);
        }

        $statuses = collect($statuses)
        ->map(fn($status) => strtolower(trim($status)))
        ->filter()
        ->unique()
        ->values()
        ->all();

        if (!empty($statuses)) {
            $query->where(function ($q) use ($statuses) {
                foreach ($statuses as $status) {
                    switch ($status) {
                        case 'active':
                        $q->orWhere(function ($sub) {
                            $sub->where('is_verified', true)
                            ->whereNull('deleted_at');
                        });
                        break;

                        case 'pending':
                        $q->orWhere(function ($sub) {
                            $sub->where('is_verified', false)
                            ->whereNull('deleted_at');
                        });
                        break;

                        case 'inactive':
                        case 'deleted':
                        $q->orWhereNotNull('deleted_at');
                        break;
                    }
                }
            });
        }
    }

    if ($request->role) {
        $role = Role::where('name', strtolower($request->role))->first();

        if ($role) {
            $query->where('role_id', $role->id);
        }
    }

    if ($request->role_id) {
        $query->where('role_id', $request->role_id);
    }

    if ($request->function_id) {
        $query->whereHas('functions', fn($x) =>
            $x->where('functions.id', $request->function_id)
        );
    }

    $query->orderByRaw("
        CASE
        WHEN role_id = 1 THEN 0
        WHEN deleted_at IS NULL AND is_verified = 1 THEN 1
        ELSE 2
        END ASC
        ")->orderBy('id', 'desc');

    $users = $query->paginate(20);

    $users->getCollection()->transform(function ($user) {
        $user->documents_count = (int) $user->documents_count;
        $user->sessions_chat_count = (int) $user->sessions_chat_count;
        $user->documents_team_count = (int) $user->documents_team_count;
        $user->sessions_team_chat_count = (int) $user->sessions_team_chat_count;
        $user->client_users_count = (int) $user->client_users_count;

        $user->is_online = $user->last_seen_at
        ? strtotime($user->last_seen_at) > now()->subMinutes(5)->timestamp
        : false;

        if ($user->deleted_at) {
            $user->status = 'inactive';
        } elseif (!$user->is_verified) {
            $user->status = 'pending';
        } else {
            $user->status = 'active';
        }

        if ($user->hasRole('owner')) {
            $user->documents_team_count += $user->documents_count;
            $user->sessions_team_chat_count += $user->sessions_chat_count;
            $user->economist_30_last = $this->aiEconomics($user->id);
            $user->active_users_last_30_days = $this->activeUsersLast30Days($user->id);
            $user->deleted_users_last_30_days = $this->trashedUsersLast30Days($user->id);
        }

        return $user;
    });

    return $this->success($users);
}
    /* ============================================================
     | GET USERS LIST
     ============================================================ */
    #[OA\Get(
     path: "/api/team-users/{id}",
     summary: "Get team users list",
     tags: ["Users"],
     security: [["sanctum" => []]],
     parameters: [
        new OA\Parameter(name: "id", in: "path", schema: new OA\Schema(type: "integer")),
    ],
    responses: [
        new OA\Response(response: 200, description: "List of users")
    ]
)]
    public function teamUsers($id, Request $request)
    {
        $me = User::find($id);

        $query = User::query()->withCount([
            'documents as documents_count',
            'sessions as sessions_chat_count',
            'documentsTeam as documents_team_count',
            'sessionsTeam as sessions_team_chat_count',
            'clientUsers as client_users_count',
        ])->with('role', 'functions',
        'subscription.plan',
        'subscription.plan.features',
        'subscription.plan.prices',
        'subscription.planPrice',
        'payments');


        if ($me->hasRole('owner') || $me->hasRole('superadmin')) {
           $query->where(function ($q) use ($me) {
            $q->where('client_creator_id', $me->id)
              ->orWhere('id', $me->id); // ✅ добавить себя
          });
       } else {
           $query->where(function ($q) use ($me) {
            $q->where('client_creator_id', $me->client_creator_id)
              ->orWhere('id', $me->client_creator_id); // ✅ добавить себя
          });
       }


       return $this->success($query->paginate(20)->getCollection()->transform(function ($user) {
        $user->documents_count = (int) $user->documents_count;
        $user->sessions_chat_count = (int) $user->sessions_chat_count;
        $user->is_online = $user->last_seen_at
        ? strtotime($user->last_seen_at) > now()->subMinutes(5)->timestamp
        : false;

        if ($user->deleted_at) {
            $user->status = 'inactive';
        } elseif (!$user->is_verified) {
            $user->status = 'pending';
        } else {
            $user->status = 'active';
        }
        if ($user->hasRole('owner')) {
            $user->documents_team_count = (int) $user->documents_team_count + $user->documents_count;
            $user->sessions_team_chat_count = (int) $user->sessions_team_chat_count + $user->sessions_chat_count;
            $user->client_users_count = (int) $user->client_users_count;
            $user->economist_30_last = $this->aiEconomics($user->id);
            $user->active_users_last_30_days = $this->activeUsersLast30Days($user->id);
            $user->deleted_users_last_30_days =  $this->trashedUsersLast30Days($user->id);
        }
        return $user;
    }));
   }

    /* ============================================================
     | GET USERS LIST
     ============================================================ */
    #[OA\Get(
     path: "/api/users-superadmin",
     summary: "Get users list",
     tags: ["Users"],
     security: [["sanctum" => []]],
     parameters: [
        new OA\Parameter(name: "search", in: "query", schema: new OA\Schema(type: "string")),
        new OA\Parameter(name: "username", in: "query", schema: new OA\Schema(type: "string")),
        new OA\Parameter(name: "email", in: "query", schema: new OA\Schema(type: "string")),
        new OA\Parameter(
            name: "status",
            in: "query",
            description: "User status filter",
            schema: new OA\Schema(
                type: "string",
                enum: ["active","inactive","pending","deleted"]
            )
        ),
        new OA\Parameter(name: "role_id", in: "query", schema: new OA\Schema(type: "integer")),
        new OA\Parameter(name: "role", in: "query", schema: new OA\Schema(type: "string")),
        new OA\Parameter(name: "function_id", in: "query", schema: new OA\Schema(type: "integer")),
        new OA\Parameter(name: "page", in: "query", schema: new OA\Schema(type: "integer")),
    ],
    responses: [
        new OA\Response(response: 200, description: "List of users")
    ]
)]
    public function index2(Request $request)
    {
        $user = auth()->user();
        // Разрешаем только: super admin, admin, owner

        $query = User::query()->withCount([
            'documents as documents_count',
            'sessions as sessions_chat_count',
            'documentsTeam as documents_team_count',
            'sessionsTeam as sessions_team_chat_count',
            'clientUsers as client_users_count',
        ])->with('role', 'functions',
        'subscription.plan',
        'subscription.plan.features',
        'subscription.plan.prices',
        'subscription.planPrice',
        'payments');

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

        if ($request->status) {

            switch ($request->status) {

                case 'active':
                $query->where('is_verified', true)
                ->whereNull('deleted_at');
                break;

                case 'pending':
                $query->where('is_verified', false)
                ->whereNull('deleted_at');
                break;

                case 'inactive':
                $query->onlyTrashed();
                break;
            }

        }

        if ($request->role) {
            $roleId = Role::where('name', strtolower($request->role))->first()->id;
            $query->where('role_id', $roleId);
        }

        if ($request->role_id) {
            $query->where('role_id', $request->role_id);
        }

        if ($request->function_id) {
            $query->whereHas('functions', fn($x) =>
                $x->where('functions.id', $request->function_id)
            );
        }


        return $this->success($query->paginate(20)->getCollection()->transform(function ($user) {
            $user->documents_count = (int) $user->documents_count;
            $user->sessions_chat_count = (int) $user->sessions_chat_count;
            $user->is_online = $user->last_seen_at
            ? strtotime($user->last_seen_at) > now()->subMinutes(5)->timestamp
            : false;

            if ($user->deleted_at) {
                $user->status = 'inactive';
            } elseif (!$user->is_verified) {
                $user->status = 'pending';
            } else {
                $user->status = 'active';
            }
            if ($user->hasRole('owner')) {
                $user->documents_team_count = (int) $user->documents_team_count + $user->documents_count;
                $user->sessions_team_chat_count = (int) $user->sessions_team_chat_count + $user->sessions_chat_count;
                $user->client_users_count = (int) $user->client_users_count;
                $user->economist_30_last = $this->aiEconomics($user->id);
                $user->active_users_last_30_days = $this->activeUsersLast30Days($user->id);
                $user->deleted_users_last_30_days =  $this->trashedUsersLast30Days($user->id);
            }
            return $user;
        }));
    }


     /* ============================================================
     | GET USERS LIST
     ============================================================ */
    #[OA\Get(
     path: "/api/users/deleted",
     summary: "Get users list",
     tags: ["Users"],
     security: [["sanctum" => []]],
     parameters: [
        new OA\Parameter(name: "page", in: "query", schema: new OA\Schema(type: "integer")),
    ],
    responses: [
        new OA\Response(response: 200, description: "List of users")
    ]
)]
    public function deleted(Request $request)
    {
        $query = User::onlyTrashed();


        return $this->success($query->paginate(20));
    }

    /* ======================================================
| FILE DOWNLOAD LIST XLSX BY IDS
====================================================== */
#[OA\Get(
path: "/api/users/download/xsl",
summary: "Download users list as XLSX by ids",
tags: ["Users"],
security: [["sanctum" => []]],
parameters: [
    new OA\Parameter(
        name: "ids",
        in: "query",
        description: "User IDs",
        required: true,
        style: "form",
        explode: true,
        schema: new OA\Schema(
            type: "array",
            items: new OA\Items(type: "integer")
        )
    )
],
responses: [
    new OA\Response(response: 200, description: "Users XLSX downloaded"),
    new OA\Response(response: 422, description: "Validation error")
]
)]
public function downloadXsl(Request $request)
{
    $request->validate([
        'ids' => ['nullable', 'array'],
        'ids.*' => ['integer', 'exists:users,id'],
    ]);

    $currentUser = auth()->user();

    if (!$currentUser || $currentUser->hasRole('user') || $currentUser->hasRole('editor') || $currentUser->hasRole('accountant')) {
        abort(403, "Forbidden");
    }

    $ids = array_map('intval', $request->get('ids', []));

    if ($ids) {
         $users = User::withCount([
        'documents as documents_count',
        'sessions as sessions_chat_count',
        'documentsTeam as documents_team_count',
        'sessionsTeam as sessions_team_chat_count',
        'clientUsers as client_users_count',
    ])
    ->with(
        'role',
        'functions',
        'subscription.plan',
        'subscription.plan.features',
        'subscription.plan.prices',
        'subscription.planPrice',
        'payments'
    )
    ->whereIn('id', $ids)
    ->get()
    ->sortBy(function ($user) use ($ids) {
        return array_search($user->id, $ids);
    })
    ->values();
    } else {
         $users = User::withCount([
        'documents as documents_count',
        'sessions as sessions_chat_count',
        'documentsTeam as documents_team_count',
        'sessionsTeam as sessions_team_chat_count',
        'clientUsers as client_users_count',
    ])
    ->with(
        'role',
        'functions',
        'subscription.plan',
        'subscription.plan.features',
        'subscription.plan.prices',
        'subscription.planPrice',
        'payments'
    )
    ->get()
    ->values();
    }

    $users->transform(function ($user) {
        $user->is_online = $user->last_seen_at
        ? strtotime($user->last_seen_at) > now()->subMinutes(5)->timestamp
        : false;

        if ($user->deleted_at) {
            $user->status = 'inactive';
        } elseif (!$user->is_verified) {
            $user->status = 'pending';
        } else {
            $user->status = 'active';
        }

        if ($user->hasRole('owner')) {
            $user->documents_team_count = (int)$user->documents_team_count;
            $user->sessions_team_chat_count = (int)$user->sessions_team_chat_count;
            $user->client_users_count = (int)$user->client_users_count;

            $user->economist_30_last = $this->aiEconomics($user->id);
            $user->active_users_last_30_days = $this->activeUsersLast30Days($user->id);
            $user->deleted_users_last_30_days = $this->trashedUsersLast30Days($user->id);
        }

        return $user;
    });

    $fileName = 'users_report_' . now()->format('Ymd_His') . '.xlsx';

    return Excel::download(new \App\Exports\UsersExport($users), $fileName);
}

/* ======================================================
| FILE DOWNLOAD LIST PDF BY IDS
====================================================== */
#[OA\Get(
path: "/api/users/download/pdf",
summary: "Download users list as PDF by ids",
tags: ["Users"],
security: [["sanctum" => []]],
parameters: [
    new OA\Parameter(
        name: "ids",
        in: "query",
        description: "User IDs",
        required: true,
        style: "form",
        explode: true,
        schema: new OA\Schema(
            type: "array",
            items: new OA\Items(type: "integer")
        )
    ),
    new OA\Parameter(
        name: "isView",
        in: "query",
        schema: new OA\Schema(type: "boolean")
    )
],
responses: [
    new OA\Response(response: 200, description: "Users PDF downloaded"),
    new OA\Response(response: 422, description: "Validation error")
]
)]
public function downloadListPDF(Request $request)
{
    $request->validate([
        'ids' => ['nullable', 'array'],
        'ids.*' => ['integer', 'exists:users,id'],
        'isView' => ['nullable'],
    ]);

    $currentUser = auth()->user();

    if (!$currentUser || $currentUser->hasRole('user') || $currentUser->hasRole('editor') || $currentUser->hasRole('accountant')) {
        abort(403, "Forbidden");
    }

    $ids = array_map('intval', $request->get('ids', []));

    if ($ids) {
         $users = User::withCount([
        'documents as documents_count',
        'sessions as sessions_chat_count',
        'documentsTeam as documents_team_count',
        'sessionsTeam as sessions_team_chat_count',
        'clientUsers as client_users_count',
    ])
    ->with(
        'role',
        'functions',
        'subscription.plan',
        'subscription.plan.features',
        'subscription.plan.prices',
        'subscription.planPrice',
        'payments'
    )
    ->whereIn('id', $ids)
    ->get()
    ->sortBy(function ($user) use ($ids) {
        return array_search($user->id, $ids);
    })
    ->values();
    } else {
         $users = User::withCount([
        'documents as documents_count',
        'sessions as sessions_chat_count',
        'documentsTeam as documents_team_count',
        'sessionsTeam as sessions_team_chat_count',
        'clientUsers as client_users_count',
    ])
    ->with(
        'role',
        'functions',
        'subscription.plan',
        'subscription.plan.features',
        'subscription.plan.prices',
        'subscription.planPrice',
        'payments'
    )
    ->get()
    ->values();
    }
   

    $users->transform(function ($user) {
        $user->is_online = $user->last_seen_at
        ? strtotime($user->last_seen_at) > now()->subMinutes(5)->timestamp
        : false;

        if ($user->deleted_at) {
            $user->status = 'inactive';
        } elseif (!$user->is_verified) {
            $user->status = 'pending';
        } else {
            $user->status = 'active';
        }

        if ($user->hasRole('owner')) {
            $user->documents_team_count = (int)$user->documents_team_count;
            $user->sessions_team_chat_count = (int)$user->sessions_team_chat_count;
            $user->client_users_count = (int)$user->client_users_count;

            $user->economist_30_last = $this->aiEconomics($user->id);
            $user->active_users_last_30_days = $this->activeUsersLast30Days($user->id);
            $user->deleted_users_last_30_days = $this->trashedUsersLast30Days($user->id);
        }

        return $user;
    });

    $fileName = 'users_report_' . now()->format('Ymd_His') . '.pdf';

    $pdf = Pdf::loadView('pdf.users-report-list', [
        'users' => $users,
        'authUser' => auth()->user(),
    ])->setPaper('a4');

    if ($request->boolean('isView')) {
        return response($pdf->output(), 200)
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'inline; filename="' . $fileName . '"');
    }

    return $pdf->download($fileName);
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
        $user = User::with('role', 'functions', 'functions.translations',
            'subscription.plan',
            'subscription.plan.features',
            'subscription.plan.prices',
            'subscription.planPrice',
            'payments')->find($id);
        if (!$user) return $this->error("Not found", 404);

        return $this->success($user);
    }

    /* ============================================================
     | GET USER BY ID
     ============================================================ */
    #[OA\Get(
     path: "/api/users/check-team/{email}",
     summary: "Get check team user by EMAIL",
     tags: ["Users"],
     security: [["sanctum" => []]],
     parameters: [
        new OA\Parameter(
            name: "email",
            in: "path",
            required: true,
            schema: new OA\Schema(type: "string")
        )
    ],
    responses: [
        new OA\Response(response: 200, description: "User detail"),
        new OA\Response(response: 404, description: "Not found"),
    ]
)]
    public function checkTeam($email)
    {
        $user = User::where('email', $email)->first();
        if (!$user) return $this->error("Not found", 404);
        if ($user->client_creator_id) {
         return $this->success(['message' => 'Yeap. User in team']); 
     }


     $c = User::where('client_creator_id',$user->client_creator_id)->count();

     if ($c) {
         return $this->success(['message' => 'Yeap. User is owner a team']); 
     }
     return $this->error(['message' => 'Not. User not in team']); 
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
            mediaType: "application/json",
            schema: new OA\Schema(
                type: "object",
                properties: [
                    new OA\Property(property: "name", type: "string"),
                    new OA\Property(property: "email", type: "string"),
                    new OA\Property(property: "role_id", type: "integer"),
                    new OA\Property(property: "role", type: "string"),
                    new OA\Property(
                        property: "functions",
                        type: "array",
                        items: new OA\Items(
                            type: "object",
                            properties: [
                                new OA\Property(property: "id", type: "integer", example: 1),
                            ]
                        )
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
 if (!$user || $user->hasRole('user') || $user->hasRole('editor') || $user->hasRole('accountant')) {
    abort(403, "Forbidden");
}
if (auth()->user()->hasRole('user') || auth()->user()->hasRole('client')) {
    $request->validate([
        'name' => 'required|string',
        'email' => [
            'required',
            'email',
            Rule::unique('users', 'email')->withoutTrashed(),
        ],
        'role_id' => 'nullable|integer|exists:roles,id',
        'role' => 'nullable|string|exists:roles,name',
        'functions' => 'nullable|array',
        'functions.*.id' => 'nullable|integer',
    ]);
} else {
    $request->validate([
        'name' => 'required|string',
        'email' => [
            'required',
            'email',
            Rule::unique('users', 'email')->withoutTrashed(),
        ],
        'role_id' => 'nullable|integer|exists:roles,id',
        'role' => 'nullable|string|exists:roles,name',
        'functions' => 'nullable|array',
        'functions.*.id' => 'nullable|integer',
    ]);
}

$role_id = $request->role_id;

if ($request->role) {
    $role = \App\Models\Role::where('name', strtolower($request->role))->first();
    $role_id = $role?->id;
}

$plainPassword = Str::random(10);

if ($user->hasRole('admin') &&  in_array(strtolower($request->role),['owner', 'admin']) ) {
    abort(403, "Forbidden");
}



$user = User::create([
    'name' => $request->name,
    'email' => $request->email,
    'role_id' => $role_id,
    'password' => Hash::make($plainPassword),
]);


$user->update([
    'is_verified' => true,
    'verification_code' => null,
]);


if ($request->functions)  {
  $functions  = collect($request->functions)->pluck('id')->toArray();

  $user->functions()->sync($functions);

}

Mail::raw("
    Your account has been created.

    Login: {$request->email}
    Password: {$plainPassword}

    Please login and change your password.
    ", function ($message) use ($request) {
        $message->to($request->email)
        ->subject('Your Account Credentials');
    });

Event::create([
    'user_id' => auth()->user()->id,
    'action' => 'added',
    'model' => 'user',
    'model_id' => $user->id
]);

return $this->success(null, 'Verification code sent to user email', 201);
}

   #[OA\Put(
path: "/api/users/{id}",
summary: "Update user",
tags: ["Users"],
security: [["sanctum" => []]],
parameters: [
    new OA\Parameter(
        name: "id",
        in: "path",
        required: true,
        schema: new OA\Schema(type: "integer")
    ),
],
requestBody: new OA\RequestBody(
    required: true,
    content: new OA\JsonContent(
        type: "object",
        properties: [
            new OA\Property(property: "name", type: "string"),
            new OA\Property(property: "email", type: "string"),
            new OA\Property(property: "password", type: "string"),
            new OA\Property(property: "role", type: "string", example: "owner"),
            new OA\Property(
                property: "functions",
                type: "array",
                items: new OA\Items(
                    type: "object",
                    properties: [
                        new OA\Property(property: "id", type: "integer", example: 1),
                    ]
                )
            ),
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

     $request->validate([
        'name' => 'nullable|string',
        'email' => 'nullable|email|unique:users,email',
        'role' => 'nullable|string|exists:roles,name',
        'functions'          => 'nullable|array',
        'functions.*.id'   => 'nullable|integer',
        'password' => 'nullable|string',
    ]);

     $user = User::find($id);
     if (!$user) return $this->error("Not found", 404);

     if ($user->hasRole('admin') &&  in_array(strtolower($request->role),['owner', 'admin']) ) {
        abort(403, "Forbidden");
    }

    

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

    if ($request->role) {
        $role = \App\Models\Role::where('name', strtolower($request->role))->first();
        $user->role_id = $role->id;
    }

    $user->save();

    if ($request->functions)  {
      $functions  = collect($request->functions)->pluck('id')->toArray();

      $user->functions()->sync($functions);

  }



  return $this->success($user->load('role',
    'functions',
    'subscription.plan',
    'subscription.plan.features',
    'subscription.plan.prices',
    'subscription.planPrice',
    'payments'), "Updated");
}

    /* ============================================================
     | DELETE USER
     ============================================================ */
    #[OA\Delete(
     path: "/api/users/{id}",
     summary: "Delete user",
     tags: ["Users"],
     security: [["sanctum" => []]],
     parameters: [
        new OA\Parameter(
            name: "id",
            in: "path",
            required: true,
            schema: new OA\Schema(type: "integer")
        ),
    ],
    responses: [
        new OA\Response(response: 200, description: "Deleted"),
        new OA\Response(response: 404, description: "Not found"),
    ]
)]
    public function destroy($id)
    {
        if (!$user || $user->hasRole('user') || $user->hasRole('editor') || $user->hasRole('accountant')) {
            abort(403, "Forbidden");
        }
        $user = User::find($id);

        if ($user->hasRole('admin') &&  ($user->hasRole('owner') || $user->hasRole('admin'))) {
            abort(403, "Forbidden");
        }

        
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

     /* ============================================================
     | ASSIGN FUNCTIONS (ADD ONLY)
     ============================================================ */
    #[OA\Put(
     path: "/api/users/{id}/assignFunctions",
     summary: "Assign (attach) functions to user (does not remove others)",
     tags: ["Users"],
     security: [["sanctum" => []]],
     parameters: [
        new OA\Parameter(
            name: "id",
            in: "path",
            required: true,
            schema: new OA\Schema(type: "integer")
        ),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            type: "object",
            required: ["function_ids"],
            properties: [
                new OA\Property(
                    property: "function_ids",
                    type: "array",
                    items: new OA\Items(type: "integer"),
                    example: [1,2,3]
                ),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: "Updated"),
        new OA\Response(response: 404, description: "Not found"),
        new OA\Response(response: 422, description: "Validation error"),
    ]
)]
    public function assignFunctions(Request $request, $id)
    {
        $user = User::find($id);
        if (!$user) return $this->error("Not found", 404);

        $data = $request->validate([
            'function_ids' => ['required', 'array', 'min:1'],
            'function_ids.*' => ['integer', 'distinct', 'exists:functions,id'], // <-- подставь реальное имя таблицы функций
        ]);

        $ids = $data['function_ids'];

        // добавляем, не удаляя остальные
        $user->functions()->syncWithoutDetaching($ids);

        return $this->success($user->load(['role', 'functions', 'functions.translations']), "Updated");
    }

    /* ============================================================
     | REMOVE FUNCTIONS (DETACH ONLY)
     ============================================================ */
    #[OA\Delete(
     path: "/api/users/{id}/removeFunctions",
     summary: "Remove (detach) functions from user (does not affect others)",
     tags: ["Users"],
     security: [["sanctum" => []]],
     parameters: [
        new OA\Parameter(
            name: "id",
            in: "path",
            required: true,
            schema: new OA\Schema(type: "integer")
        ),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            type: "object",
            required: ["function_ids"],
            properties: [
                new OA\Property(
                    property: "function_ids",
                    type: "array",
                    items: new OA\Items(type: "integer"),
                    example: [2,5]
                ),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: "Updated"),
        new OA\Response(response: 404, description: "Not found"),
        new OA\Response(response: 422, description: "Validation error"),
    ]
)]
    public function removeFunctions(Request $request, $id)
    {
        $user = User::find($id);
        if (!$user) return $this->error("Not found", 404);

        $data = $request->validate([
            'function_ids' => ['required', 'array', 'min:1'],
            'function_ids.*' => ['integer', 'distinct', 'exists:functions,id'], // <-- подставь реальное имя таблицы функций
        ]);

        $ids = $data['function_ids'];

        // удаляем только указанные, остальные не трогаем
        $user->functions()->detach($ids);

        return $this->success($user->load(['role', 'functions', 'functions.translations']), "Updated");
    }
}
