<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentTranslation;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class DocumentController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /* ======================================================
     | GET DOCUMENT LIST
     | ?lang=en
     | ?category_id=3
     | ?search=abc
     ====================================================== */
    #[OA\Get(
     path: "/api/documents",
     summary: "Get document list",
     tags: ["Documents"],
     security: [["sanctum" => []]],
     parameters: [
        new OA\Parameter(name: "lang", in: "query", schema: new OA\Schema(type: "string")),
        new OA\Parameter(name: "category_id", in: "query", schema: new OA\Schema(type: "integer")),
        new OA\Parameter(name: "search", in: "query", schema: new OA\Schema(type: "string"))
    ],
    responses: [
        new OA\Response(response: 200, description: "Document list")
    ]
)]
    public function index(Request $request)
    {
        $lang = $request->get('lang', 'en');

        $q = Document::query()->with(['translations', 'category']);

        if ($request->category_id) {
            $q->where('category_id', $request->category_id);
        }

        if ($request->search) {
            $q->whereHas('translations', function ($qq) use ($request) {
                $qq->where('title', 'like', "%{$request->search}%");
            });
        }

        $items = $q->orderBy('id', 'desc')->get();

        $items->transform(function ($doc) use ($lang) {
            $doc->translated = $doc->getTranslation($lang);
            return $doc;
        });

        return $this->success($items);
    }

#[OA\Post(
    path: "/api/documents",
    summary: "Create document with translations (each with its own file)",
    tags: ["Documents"],
    security: [["sanctum" => []]],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: "multipart/form-data",
            schema: new OA\Schema(
                type: "object",
                properties: [

                    new OA\Property(
                        property: "category_id",
                        type: "integer",
                        example: 1
                    ),

                    new OA\Property(
                        property: "is_public",
                        type: "boolean",
                        example: true
                    ),

                    new OA\Property(
                        property: "file",
                        type: "string",
                        format: "binary",
                        description: "Main document file (PDF/DOCX/Image)"
                    ),

                    /* ==========================================================
                     * ARRAY: translations[0][lang], translations[0][file]
                     * ========================================================== */

                    new OA\Property(
                        property: "translations[0][lang]",
                        type: "string",
                        example: "en"
                    ),

                    new OA\Property(
                        property: "translations[0][title]",
                        type: "string",
                        example: "Contract EN"
                    ),

                    new OA\Property(
                        property: "translations[0][file]",
                        type: "string",
                        format: "binary",
                        description: "File for EN translation"
                    ),


                    new OA\Property(
                        property: "translations[1][lang]",
                        type: "string",
                        example: "ru"
                    ),

                    new OA\Property(
                        property: "translations[1][title]",
                        type: "string",
                        example: "Контракт RU"
                    ),

                    new OA\Property(
                        property: "translations[1][file]",
                        type: "string",
                        format: "binary",
                        description: "File for RU translation"
                    ),

                ]
            )
        )
    ),
    responses: [
        new OA\Response(response: 201, description: "Created")
    ]
)]
private function convertPdfToPng(string $pdfPath): ?string
{
    $output = $pdfPath . ".png";

    $cmd = sprintf(
        'convert -density 300 %s[0] -quality 100 %s 2>&1',
        escapeshellarg($pdfPath),
        escapeshellarg($output)
    );

    $out = [];
    $status = 0;
    exec($cmd, $out, $status);

    if ($status !== 0) {
        \Log::error('PDF convert failed', [
            'cmd'    => $cmd,
            'status' => $status,
            'output' => $out,
        ]);
        return null;
    }

    return $output;
}

private function mapLangForTesseract(string $lang): string
{
    $lang = strtolower($lang);

    return match ($lang) {
        'en', 'eng' => 'eng',
        'ru', 'rus' => 'rus',
        'uk', 'ua', 'ukr' => 'ukr',
        default => 'eng',
    };
}

private function runTesseract(string $imagePath, string $lang): string
{
    $outputBase = $imagePath . "_ocr";

    $cmd = sprintf(
        'tesseract %s %s -l %s 2>&1',
        escapeshellarg($imagePath),
        escapeshellarg($outputBase),
        escapeshellarg($lang),
    );

    $out = [];
    $status = 0;
    exec($cmd, $out, $status);

    if ($status !== 0) {
        \Log::error('Tesseract failed', [
            'cmd'    => $cmd,
            'status' => $status,
            'output' => $out,
        ]);
        return '';
    }

    $txt = $outputBase . ".txt";
    return file_exists($txt) ? trim(file_get_contents($txt)) : "";
}
public function store(Request $request)
{
    $this->checkOwnerAdmin();

    $request->validate([
        'category_id'           => 'required|integer|exists:categories,id',
        'is_public'             => 'nullable|in:0,1,true,false,TRUE,FALSE,True,False',

        'translations'          => 'required|array',
        'translations.*.lang'   => 'required|string|in:en,ru,uk',
        'translations.*.title'  => 'required|string|max:255',
        'translations.*.file'   => 'required|file|max:20480',
    ]);

    $document = Document::create([
        'category_id' => $request->category_id,
        'is_public'   => $request->is_public ?? false,
        'user_id'     => auth()->id(),
        'slug'        => Str::slug($request->translations[0]['title'] ?? Str::random(8)),
    ]);

    foreach ($request->translations as $t) {

        // Save original
        $path = $t['file']->store('ocr', 'public');
        $fullPath = storage_path('app/public/' . $path);

        if (!file_exists($fullPath)) {
            \Log::error('OCR file missing after store', ['path' => $fullPath]);
            return $this->error("Failed to store file", 500);
        }

        // Detect extension
        $ext = strtolower($t['file']->getClientOriginalExtension());

        // Convert PDF → PNG if needed
        $imagePath = $fullPath;

        if ($ext === 'pdf') {
            $imagePath = $this->convertPdfToPng($fullPath);
            if (!$imagePath) {
                return $this->error("Unable to convert PDF to image", 500);
            }
        }

        $ocrLang = $this->mapLangForTesseract($t['lang'] ?? 'en');

        // Run OCR
        $text = $this->runTesseract($imagePath, $ocrLang);

        DocumentTranslation::create([
            'document_id' => $document->id,
            'lang'        => $t['lang'],
            'title'       => $t['title'],
            'content'     => $text,
            'file_path'   => $path,        // лучше сохранить относительный (из store)
            'file_type'   => $ext,
        ]);
    }

    return $this->success($document->load('translations'), "Created", 201);
}

     /* ======================================================
     | SHOW DOCUMENT
     ====================================================== */
    #[OA\Get(
     path: "/api/documents/{id}",
     summary: "Get document by ID",
     tags: ["Documents"],
     security: [["sanctum" => []]],
     parameters: [
        new OA\Parameter(name: "id", in: "path", schema: new OA\Schema(type: "integer")),
        new OA\Parameter(name: "lang", in: "query", schema: new OA\Schema(type: "string"))
    ],
    responses: [
        new OA\Response(response: 200, description: "Document found"),
        new OA\Response(response: 404, description: "Not found")
    ]
)]


    public function show($id, Request $request)
    {
        $lang = $request->get('lang', 'en');

        $doc = Document::with(['translations', 'category'])->find($id);

        if (!$doc) return $this->error("Not found", 404);

        $doc->translated = $doc->getTranslation($lang);

        return $this->success($doc);
    }

    private function extractDocumentText(string $path, string $ext): string
    {
        $ext = strtolower($ext);

    // TXT files
        if (in_array($ext, ['txt', 'csv', 'log'])) {
            return file_get_contents($path);
        }

    // PDF → text via pdftotext
        if ($ext === 'pdf') {
            $tmp = $path . ".txt";
            exec("pdftotext " . escapeshellarg($path) . " " . escapeshellarg($tmp));
            return file_exists($tmp) ? file_get_contents($tmp) : "";
        }

    // DOCX → text via PHP ZipArchive
        if ($ext === 'docx') {
            $zip = new \ZipArchive;
            if ($zip->open($path) === true) {
                $data = $zip->getFromName("word/document.xml");
                $zip->close();
                return $data ? strip_tags($data) : "";
            }
            return "";
        }

    // Images → OCR (same as OcrController)
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'bmp', 'tif', 'tiff'])) {
            $output = $path . "_ocr";
            exec("tesseract " . escapeshellarg($path) . " " . escapeshellarg($output) . " -l eng");
            $txt = $output . ".txt";
            return file_exists($txt) ? trim(file_get_contents($txt)) : "";
        }

        return "";
    }

    /* ======================================================
     | UPDATE DOCUMENT
     ====================================================== */

    /* ======================================================
     | SHOW DOCUMENT
     ====================================================== */
 #[OA\Put(
     path: "/api/documents/{id}",
     summary: "Update document and translations",
     tags: ["Documents"],
     security: [["sanctum" => []]],
     parameters: [
        new OA\Parameter(
            name: "id",
            in: "path",
            required: true,
            schema: new OA\Schema(type: "integer")
        )
    ],
    requestBody: new OA\RequestBody(
        required: false,
        content: new OA\MediaType(
            mediaType: "multipart/form-data",
            schema: new OA\Schema(
                type: "object",
                properties: [

                    new OA\Property(property: "category_id", type: "integer"),
                    new OA\Property(property: "is_public", type: "boolean"),
                      /* ==========================================================
                     * ARRAY: translations[0][lang], translations[0][file]
                     * ========================================================== */

                      new OA\Property(
                        property: "translations[0][lang]",
                        type: "string",
                        example: "en"
                    ),

                      new OA\Property(
                        property: "translations[0][title]",
                        type: "string",
                        example: "Contract EN"
                    ),

                      new OA\Property(
                        property: "translations[0][file]",
                        type: "string",
                        format: "binary",
                        description: "File for EN translation"
                    ),


                      new OA\Property(
                        property: "translations[1][lang]",
                        type: "string",
                        example: "ru"
                    ),

                      new OA\Property(
                        property: "translations[1][title]",
                        type: "string",
                        example: "Контракт RU"
                    ),

                      new OA\Property(
                        property: "translations[1][file]",
                        type: "string",
                        format: "binary",
                        description: "File for RU translation"
                    ),


                  ],
              )
        )
    ),
    responses: [
        new OA\Response(response: 200, description: "Updated")
    ]
)]
 public function update($id, Request $request)
 {
    $this->checkOwnerAdmin();

    $doc = Document::find($id);
    if (!$doc) return $this->error("Not found", 404);

    $request->validate([
        'category_id'     => 'nullable|integer|exists:categories,id',
        'is_public'       => 'nullable|in:0,1,true,false,TRUE,FALSE,True,False',

        'translations'              => 'nullable|array',
        'translations.*.lang'       => 'required_with:translations|string|in:en,ru,uk',
        'translations.*.title'      => 'required_with:translations|string|max:255',
        'translations.*.file'       => 'nullable|file|max:20480', // файл может быть не передан
    ]);

    // ----------------------- UPDATE MAIN FILE -----------------------
    if ($request->hasFile('file')) {

        Storage::delete($doc->file_path);

        $path = $request->file('file')->store('documents');
        $ext  = strtolower($request->file('file')->getClientOriginalExtension());

        $doc->file_path = $path;
        $doc->file_type = $ext;
        $doc->save();
    }

    // ----------------------- BASIC FIELDS -----------------------
    $doc->update([
        'category_id' => $request->category_id ?? $doc->category_id,
        'is_public'   => $request->is_public ?? $doc->is_public
    ]);

     foreach ($request->translations as $t) {

        // Save original
        $path = $t['file']->store('ocr', 'public');
        $fullPath = storage_path('app/public/' . $path);

        if (!file_exists($fullPath)) {
            \Log::error('OCR file missing after store', ['path' => $fullPath]);
            return $this->error("Failed to store file", 500);
        }

        // Detect extension
        $ext = strtolower($t['file']->getClientOriginalExtension());

        // Convert PDF → PNG if needed
        $imagePath = $fullPath;

        if ($ext === 'pdf') {
            $imagePath = $this->convertPdfToPng($fullPath);
            if (!$imagePath) {
                return $this->error("Unable to convert PDF to image", 500);
            }
        }

        $ocrLang = $this->mapLangForTesseract($t['lang'] ?? 'en');

        // Run OCR
        $text = $this->runTesseract($imagePath, $ocrLang);

        DocumentTranslation::updateOrCreate(  ['document_id' => $doc->id, 'lang' => $t['lang']],[
            'document_id' => $document->id,
            'lang'        => $t['lang'],
            'title'       => $t['title'],
            'content'     => $text,
            'file_path'   => $path,        // лучше сохранить относительный (из store)
            'file_type'   => $ext,
        ]);
    }

    return $this->success($doc->load('translations'), "Updated");
}


    /* ======================================================
     | DELETE DOCUMENT
     ====================================================== */
    #[OA\Delete(
     path: "/api/documents/{id}",
     summary: "Delete document",
     tags: ["Documents"],
     security: [["sanctum" => []]],
     responses: [
        new OA\Response(response: 200, description: "Deleted")
    ]
)]
    public function destroy($id)
    {
        $this->checkOwnerAdmin();

        $doc = Document::find($id);
        if (!$doc) return $this->error("Not found", 404);

        Storage::delete($doc->file_path);
        $doc->translations()->delete();
        $doc->delete();

        return $this->success(null, "Deleted");
    }

    /* ======================================================
     | FILE DOWNLOAD
     ====================================================== */
    #[OA\Get(
     path: "/api/documents/{id}/download",
     summary: "Download document file",
     tags: ["Documents"],
     security: [["sanctum" => []]],
     responses: [
        new OA\Response(response: 200, description: "File downloaded")
    ]
)]
    public function download($id)
    {
        $doc = Document::find($id);
        if (!$doc) return $this->error("Not found", 404);

        return Storage::download($doc->file_path);
    }

    /* ======================================================
     | OWNER / ADMIN VALIDATION
     ====================================================== */
     private function checkOwnerAdmin()
     {
        $user = auth()->user();
        if (!$user || !in_array($user->role_id, [1, 2])) {
            abort(403, "Forbidden");
        }
    }
}
