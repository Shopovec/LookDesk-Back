<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentTranslation;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DocumentTranslationController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /* ===========================================================
     | LIST ALL TRANSLATIONS OF DOCUMENT
     =========================================================== */
    #[OA\Get(
        path: "/api/documents/{id}/translations",
        summary: "Get all translations for a document",
        tags: ["Document Translations"],
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
            new OA\Response(response: 200, description: "List of translations"),
            new OA\Response(response: 404, description: "Document not found"),
        ]
    )]
    public function index($id)
    {
        $document = Document::with('translations')->find($id);
        if (!$document) return $this->error("Document not found", 404);

        return $this->success($document->translations);
    }

    /* ===========================================================
     | CREATE TRANSLATION (ADD LANGUAGE)
     =========================================================== */
    #[OA\Post(
        path: "/api/documents/{id}/translations",
        summary: "Create translation for a document",
        tags: ["Document Translations"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "lang", type: "string"),
                    new OA\Property(property: "title", type: "string"),
                    new OA\Property(property: "description", type: "string", nullable: true)
                ],
                required: ["lang", "title"]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Translation created"),
            new OA\Response(response: 404, description: "Document not found")
        ]
    )]
    public function store($id, Request $request)
    {
        $this->checkOwnerAdmin();

        $request->validate([
            'lang' => 'required|string|max:5',
            'title' => 'required|string',
            'description' => 'nullable|string',
        ]);

        $doc = Document::find($id);
        if (!$doc) return $this->error("Document not found", 404);

        // Prevent duplicates
        if (DocumentTranslation::where('document_id', $id)->where('lang', $request->lang)->exists()) {
            return $this->error("Translation for this language already exists", 409);
        }

        $translation = DocumentTranslation::create([
            'document_id' => $id,
            'lang' => $request->lang,
            'title' => $request->title,
            'description' => $request->description,
        ]);

        return $this->success($translation, "Translation created", 201);
    }

    /* ===========================================================
     | SHOW SINGLE TRANSLATION
     =========================================================== */
    #[OA\Get(
        path: "/api/documents/{id}/translations/{lang}",
        summary: "Get translation by language",
        tags: ["Document Translations"],
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", schema: new OA\Schema(type: "integer")),
            new OA\Parameter(name: "lang", in: "path", schema: new OA\Schema(type: "string"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Translation found"),
            new OA\Response(response: 404, description: "Not found")
        ]
    )]
    public function show($id, $lang)
    {
        $translation = DocumentTranslation::where('document_id', $id)
            ->where('lang', $lang)
            ->first();

        if (!$translation) {
            return $this->error("Translation not found", 404);
        }

        return $this->success($translation);
    }

    /* ===========================================================
     | UPDATE TRANSLATION
     =========================================================== */
    #[OA\Put(
        path: "/api/documents/{id}/translations/{lang}",
        summary: "Update translation of document",
        tags: ["Document Translations"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "title", type: "string"),
                    new OA\Property(property: "description", type: "string", nullable: true)
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Updated"),
            new OA\Response(response: 404, description: "Not found")
        ]
    )]
    public function update($id, $lang, Request $request)
    {
        $this->checkOwnerAdmin();

        $translation = DocumentTranslation::where('document_id', $id)
            ->where('lang', $lang)
            ->first();

        if (!$translation) {
            return $this->error("Translation not found", 404);
        }

        $translation->title = $request->title ?? $translation->title;
        $translation->description = $request->description ?? $translation->description;
        $translation->save();

        return $this->success($translation, "Updated");
    }

    /* ===========================================================
     | DELETE TRANSLATION
     =========================================================== */
    #[OA\Delete(
        path: "/api/documents/{id}/translations/{lang}",
        summary: "Delete translation of document",
        tags: ["Document Translations"],
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(response: 200, description: "Deleted"),
            new OA\Response(response: 404, description: "Not found")
        ]
    )]
    public function destroy($id, $lang)
    {
        $this->checkOwnerAdmin();

        $translation = DocumentTranslation::where('document_id', $id)
            ->where('lang', $lang)
            ->first();

        if (!$translation) {
            return $this->error("Translation not found", 404);
        }

        $translation->delete();

        return $this->success(null, "Deleted");
    }

    /* ===========================================================
     | HELPER: OWNER / ADMIN
     =========================================================== */
    private function checkOwnerAdmin()
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role_id, [1, 2])) {
            abort(403, "Forbidden");
        }
    }
}
