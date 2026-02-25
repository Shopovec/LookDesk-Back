<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FunctionZ;
use App\Models\User;
use App\Models\FunctionTranslation;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Models\Event;
use OpenApi\Attributes as OA;

class FunctionController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /* -------------------------------------------------------------
     | GET ALL functions
     | Supports:
     |  ?lang=en
     |  returns nested structure
     ------------------------------------------------------------- */
    #[OA\Get(
     path: "/api/functions",
     summary: "Get all functions (with translations)",
     tags: ["functions"],
     security: [["sanctum" => []]],
     parameters: [
        new OA\Parameter(
            name: "lang",
            in: "query",
            schema: new OA\Schema(type: "string")
        )
    ],
    responses: [
        new OA\Response(response: 200, description: "functions list")
    ]
)]
    public function index(Request $request)
    {
        $lang = $request->get('lang', 'en');

        $query = FunctionZ::query()->with(['translations']);

        $me = auth()->user();

        if ($me->hasRole('user') || $me->hasRole('client')) {

        // если клиент — берем owner-а, иначе себя
            $ownerId = $me->id;

        // показать функции, привязанные к ownerId (через pivot user_function)
            $query->whereHas('users', function ($q) use ($ownerId) {
                $q->where('users.id', $ownerId);
            });
        }

        if ($me->hasRole('owner') ) {

        // если клиент — берем owner-а, иначе себя
            $ownerId = $me->id;

        // показать функции, привязанные к ownerId (через pivot user_function)
            $query->whereHas('users', function ($q) use ($ownerId) {
                $q->where('users.id', $ownerId);
            });
        }

        $items = $query->get();

        $items->transform(function ($cat) use ($lang) {
            $cat->translated = $cat->getTranslation($lang);
            return $cat;
        });

        return $this->success($items);
    }

    /* -------------------------------------------------------------
     | CREATE Function
     ------------------------------------------------------------- */
    #[OA\Post(
     path: "/api/functions",
     summary: "Create new Function",
     tags: ["functions"],
     security: [["sanctum" => []]],
     requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "translations", type: "array", items:
                    new OA\Items(
                        properties: [
                            new OA\Property(property: "lang", type: "string"),
                            new OA\Property(property: "title", type: "string"),
                            new OA\Property(property: "description", type: "string", nullable: true),
                        ],
                        required: ["lang", "title"]
                    )
                )
            ],
            required: ["translations"]
        )
    ),
     responses: [
        new OA\Response(response: 201, description: "Created")
    ]
)]
    public function store(Request $request)
    {
        $request->validate([
            'translations' => 'required|array',
            'translations.*.lang' => 'required|string',
            'translations.*.title' => 'required|string',
        ]);

        $function = FunctionZ::create([]);

        foreach ($request->translations as $t) {
            FunctionTranslation::create([
                'function_id' => $function->id,
                'lang' => $t['lang'],
                'title' => $t['title'],
                'description' => $t['description'] ?? null,
            ]);
        }

        $current_user = auth()->user();

        if ($current_user->hasRole('owner')) {
            $team_users = User::where('client_creator_id', $current_user->id)->get();
            foreach ($team_users as $user) {
                $user->functions()->syncWithoutDetaching([$function->id]);
            }

        } else {
            $team_users = User::where('client_creator_id', $current_user->client_creator_id)->get();
            foreach ($team_users as $user) {
                $user->functions()->syncWithoutDetaching([$function->id]);
            }
        }

        Event::create([
            'user_id' => auth()->user()->id,
            'action'  => 'created',
            'model' => 'function',
            'model_id' => $function->id,
        ]);

        return $this->success($function->load('translations'), "Created", 201);
    }

    /* -------------------------------------------------------------
     | SHOW Function
     ------------------------------------------------------------- */
    #[OA\Get(
     path: "/api/functions/{id}",
     summary: "Get Function by ID",
     tags: ["functions"],
     security: [["sanctum" => []]],
     parameters: [
        new OA\Parameter(
            name: "id",
            in: "path",
            schema: new OA\Schema(type: "integer")
        ),
        new OA\Parameter(
            name: "lang",
            in: "query",
            schema: new OA\Schema(type: "string")
        )
    ],
    responses: [
        new OA\Response(response: 200, description: "Function found"),
        new OA\Response(response: 404, description: "Not found")
    ]
)]
    public function show($id, Request $request)
    {
        $lang = $request->get('lang', 'en');

        $function = FunctionZ::with('translations')->find($id);
        if (!$function) return $this->error("Not found", 404);

        $function->translated = $function->getTranslation($lang);

        return $this->success($function);
    }

    /* -------------------------------------------------------------
     | UPDATE Function
     ------------------------------------------------------------- */
    #[OA\Put(
     path: "/api/functions/{id}",
     summary: "Update Function",
     tags: ["functions"],
     security: [["sanctum" => []]],
    parameters: [
        new OA\Parameter(
            name: "id",
            in: "path",
            schema: new OA\Schema(type: "integer")
        )
    ],
     requestBody: new OA\RequestBody(
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "translations", type: "array", items:
                    new OA\Items(
                        properties: [
                            new OA\Property(property: "lang", type: "string"),
                            new OA\Property(property: "title", type: "string"),
                            new OA\Property(property: "description", type: "string", nullable: true)
                        ]
                    )
                )
            ]
        )
    ),
     responses: [
        new OA\Response(response: 200, description: "Updated")
    ]
)]
    public function update($id, Request $request)
    {
        $function = FunctionZ::find($id);
        if (!$function) return $this->error("Not found", 404);

        if ($request->translations) {
            foreach ($request->translations as $t) {
                FunctionTranslation::updateOrCreate(
                    [
                        'function_id' => $function->id,
                        'lang' => $t['lang']
                    ],
                    [
                        'title' => $t['title'],
                        'description' => $t['description'] ?? null
                    ]
                );
            }
        }

        return $this->success($function->load('translations'), "Updated");
    }

    /* -------------------------------------------------------------
     | DELETE Function
     ------------------------------------------------------------- */
    #[OA\Delete(
     path: "/api/functions/{id}",
     summary: "Delete Function",
         parameters: [
        new OA\Parameter(
            name: "id",
            in: "path",
            schema: new OA\Schema(type: "integer")
        )
    ],
     tags: ["functions"],
     security: [["sanctum" => []]],
     responses: [
        new OA\Response(response: 200, description: "Deleted")
    ]
)]
    public function destroy($id)
    {

        $function = FunctionZ::find($id);
        if (!$function) return $this->error("Not found", 404);

        $current_user = auth()->user();

        $current_user->functions()->detach([$id]);

        if ($current_user->hasRole('owner')) {
            $team_users = User::where('client_creator_id', $current_user->id)->get();
            foreach ($team_users as $user) {
                $user->functions()->detach([$id]);
            }

        } else {
            $team_users = User::where('client_creator_id', $current_user->client_creator_id)->get();
            foreach ($team_users as $user) {
                $user->functions()->detach([$id]);
            }
        }

        if ($function->is_system) return $this->success(null, "Deleted");

        Event::create([
            'user_id' => auth()->user()->id,
            'action'  => 'deleted',
            'model' => 'function',
            'model_id' => $function->id,
            'deleted_title' => $function->getTranslation()['title']
        ]);

        $function->translations()->delete();
        $function->delete();

        return $this->success(null, "Deleted");
    }

    /* -------------------------------------------------------------
     | OWNER / ADMIN VALIDATION
     ------------------------------------------------------------- */
     private function checkOwnerAdmin()
     {
        $user = auth()->user();
        if (!$user || !in_array($user->role_id, [1, 2])) {
            abort(403, "Forbidden");
        }
    }
}
