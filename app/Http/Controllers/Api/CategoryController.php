<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CategoryController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /* -------------------------------------------------------------
     | GET ALL CATEGORIES
     | Supports:
     |  ?lang=en
     |  ?parent_id=null
     |  returns nested structure
     ------------------------------------------------------------- */
    #[OA\Get(
        path: "/api/categories",
        summary: "Get all categories (with translations)",
        tags: ["Categories"],
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(
                name: "lang",
                in: "query",
                schema: new OA\Schema(type: "string")
            ),
            new OA\Parameter(
                name: "parent_id",
                in: "query",
                schema: new OA\Schema(type: "integer", nullable: true)
            )
        ],
        responses: [
            new OA\Response(response: 200, description: "Categories list")
        ]
    )]
    public function index(Request $request)
    {
        $lang = $request->get('lang', 'en');

        $query = Category::query()->with(['translations']);

        if ($request->has('parent_id')) {
            $query->where('parent_id', $request->parent_id);
        }

        $items = $query->orderBy('sort_order')->get();

        $items->transform(function ($cat) use ($lang) {
            $cat->translated = $cat->getTranslation($lang);
            return $cat;
        });

        return $this->success($items);
    }

    /* -------------------------------------------------------------
     | CREATE CATEGORY
     ------------------------------------------------------------- */
    #[OA\Post(
        path: "/api/categories",
        summary: "Create new category",
        tags: ["Categories"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "parent_id", type: "integer", nullable: true),
                    new OA\Property(property: "sort_order", type: "integer"),
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
        $this->checkOwnerAdmin();

        $request->validate([
            'parent_id' => 'nullable|integer|exists:categories,id',
            'sort_order' => 'nullable|integer',
            'translations' => 'required|array',
            'translations.*.lang' => 'required|string',
            'translations.*.title' => 'required|string',
        ]);

        $category = Category::create([
            'parent_id' => $request->parent_id,
            'sort_order' => $request->sort_order ?? 0,
        ]);

        foreach ($request->translations as $t) {
            CategoryTranslation::create([
                'category_id' => $category->id,
                'lang' => $t['lang'],
                'title' => $t['title'],
                'description' => $t['description'] ?? null,
            ]);
        }

        return $this->success($category->load('translations'), "Created", 201);
    }

    /* -------------------------------------------------------------
     | SHOW CATEGORY
     ------------------------------------------------------------- */
    #[OA\Get(
        path: "/api/categories/{id}",
        summary: "Get category by ID",
        tags: ["Categories"],
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
            new OA\Response(response: 200, description: "Category found"),
            new OA\Response(response: 404, description: "Not found")
        ]
    )]
    public function show($id, Request $request)
    {
        $lang = $request->get('lang', 'en');

        $category = Category::with('translations')->find($id);
        if (!$category) return $this->error("Not found", 404);

        $category->translated = $category->getTranslation($lang);

        return $this->success($category);
    }

    /* -------------------------------------------------------------
     | UPDATE CATEGORY
     ------------------------------------------------------------- */
    #[OA\Put(
        path: "/api/categories/{id}",
        summary: "Update category",
        tags: ["Categories"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "parent_id", type: "integer", nullable: true),
                    new OA\Property(property: "sort_order", type: "integer"),
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
        $this->checkOwnerAdmin();

        $category = Category::find($id);
        if (!$category) return $this->error("Not found", 404);

        $category->update([
            'parent_id' => $request->parent_id ?? $category->parent_id,
            'sort_order' => $request->sort_order ?? $category->sort_order,
        ]);

        if ($request->translations) {
            foreach ($request->translations as $t) {
                CategoryTranslation::updateOrCreate(
                    [
                        'category_id' => $category->id,
                        'lang' => $t['lang']
                    ],
                    [
                        'title' => $t['title'],
                        'description' => $t['description'] ?? null
                    ]
                );
            }
        }

        return $this->success($category->load('translations'), "Updated");
    }

    /* -------------------------------------------------------------
     | DELETE CATEGORY
     ------------------------------------------------------------- */
    #[OA\Delete(
        path: "/api/categories/{id}",
        summary: "Delete category",
        tags: ["Categories"],
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(response: 200, description: "Deleted")
        ]
    )]
    public function destroy($id)
    {
        $this->checkOwnerAdmin();

        $category = Category::find($id);
        if (!$category) return $this->error("Not found", 404);

        if (Category::where('parent_id', $id)->exists()) {
            return $this->error("Category has children", 409);
        }

        $category->translations()->delete();
        $category->delete();

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
