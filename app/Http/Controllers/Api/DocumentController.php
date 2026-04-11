<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentTranslation;
use App\Models\DocumentAttachment;
use App\Models\Event;
use App\Traits\ApiResponse;
use OpenApi\Attributes as OA;
use App\Models\DocumentEmbedding;
use App\Services\OllamaClient;
use App\Models\DocumentView;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\DocumentsExport;
use Illuminate\Support\Facades\Cache;

class DocumentController extends Controller
{
    use ApiResponse;

    #[OA\Put(
    path: "/api/documents/{id}/favorite",
    summary: "Mark/unmark a document as favorite",
    tags: ["Documents"],
    security: [["sanctum" => []]],
    parameters: [
        new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer")),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["is_favorite"],
            properties: [
                new OA\Property(property: "is_favorite", type: "boolean", example: true),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: "Updated search query"),
        new OA\Response(response: 404, description: "Not found"),
    ]
)]

    public function favorite($id, Request $request)
    {
        $data = $request->validate([
            'is_favorite' => 'required|boolean',
        ]);

        $q = Document::find($id);
        if (!$q) return response()->json(['message' => 'Not found'], 404);

        $q->is_favorite = (bool) $data['is_favorite'];
        $q->save();

        return response()->json($q);
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
        new OA\Parameter(name: "function_id", in: "query", schema: new OA\Schema(type: "integer")),
        new OA\Parameter(name: "search", in: "query", schema: new OA\Schema(type: "string"))
    ],
    responses: [
        new OA\Response(response: 200, description: "Document list")
    ]
)]

    public function index(Request $request)
    {
        $lang = $request->get('lang', 'en');
        $user = auth()->user();
        $isPrivileged = !$user->hasRole('user') && !$user->hasRole('editor');

        $limit = max(1, min((int) $request->get('limit', 100), 200));

    // === Генерация уникального ключа кэша ===
        $cacheKey = 'documents_index:' . md5(
            $lang .
            $request->get('category_id') .
            $request->get('function_id') .
            $request->get('search') .
            $limit .
            $isPrivileged .
            $request->boolean('isExportXSL') .
            $request->boolean('isExportPDF')
        );

    // TTL в секундах (5 минут — можно изменить)
        $cacheTtl = 300;

    // Кэшируем только данные из БД + eager loading
        $items = Cache::remember($cacheKey, $cacheTtl, function () use ($request, $lang, $isPrivileged, $limit) {

            $query = Document::query()
            ->select('documents.id', 'documents.created_at', 'documents.created_by', 'documents.updated_at');

        // Фильтры через JOIN
            if ($request->filled('category_id')) {
                $categoryId = (int) $request->category_id;
                $query->join('document_category', 'documents.id', '=', 'document_category.document_id')
                ->where('document_category.category_id', $categoryId);
            }

            if ($request->filled('function_id')) {
                $functionId = (int) $request->function_id;
                $query->join('document_function', 'documents.id', '=', 'document_function.document_id')
                ->where('document_function.function_id', $functionId);
            }

        // FULLTEXT поиск
            if ($request->filled('search')) {
                $searchTerm = trim($request->search);
                if (!empty($searchTerm)) {
                    $searchTerm = str_replace(['+', '-', '(', ')', '~', '<', '>', '@', '"', "'"], ' ', $searchTerm);
                    $searchTerm = preg_replace('/\s+/', ' ', $searchTerm);

                    $query->whereExists(function ($sub) use ($searchTerm, $lang) {
                        $sub->selectRaw('1')
                        ->from('document_translations')
                        ->whereColumn('document_translations.document_id', 'documents.id')
                        ->where('document_translations.lang', $lang)
                        ->whereRaw("MATCH(title) AGAINST(? IN BOOLEAN MODE)", [
                            '+' . str_replace(' ', ' +', $searchTerm)
                        ]);
                    });
                }
            }

        // withCount только для привилегированных
            if ($isPrivileged) {
                $query->withCount([
                    'views as views_last_30_days' => fn($q) => $q->where('created_at', '>=', now()->subDays(30))
                ]);
            }

        // Eager loading
            $query->with([
                'translations' => fn($q) => $q->where('lang', $lang)
                ->select('id', 'document_id', 'lang', 'title', 'summary', 'file', 'content'),

                'categories:id',
                'categories.translations' => fn($q) => $q->where('lang', $lang)
                ->select('id', 'category_id', 'lang', 'title', 'description'),

                'functions:id',
                'creator:id,name,email',
            ]);

            return $query
            ->orderByDesc('documents.id')
            ->limit($limit)
            ->get();
        });

    // === Трансформация (делаем после кэша — она быстрая) ===
        $items->transform(function ($doc) use ($isPrivileged) {
            $translation = $doc->translations->first();
            $doc->translated = $translation ? [
                'id'      => $translation->id,
                'lang'    => $translation->lang,
                'title'   => $translation->title,
                'content' => $translation->content ?? null,
                'summary' => $translation->summary,
                'file'    => $translation->file,
            ] : null;
            unset($doc->translations);

            if ($doc->relationLoaded('categories')) {
                $doc->categories->transform(function ($cat) {
                    $t = $cat->translations->first();
                    $cat->translated = $t ? [
                        'id'          => $t->id,
                        'lang'        => $t->lang,
                        'title'       => $t->title,
                        'description' => $t->description,
                    ] : null;
                    unset($cat->translations);
                    return $cat;
                });
            }

            if (!$isPrivileged) {
                unset($doc->views_last_30_days);
            }

            $doc->ai_searches_last_30_days = 0;
            return $doc;
        });

    // === Экспорты (НЕ кэшируем — всегда свежие) ===
        if ($request->boolean('isExportXSL')) {
            $fileName = 'documents_' . now()->format('Ymd_His') . '.xlsx';
            return Excel::download(new DocumentsExport($items), $fileName);
        }

        if ($request->boolean('isExportPDF')) {
            $fileName = 'documents_' . now()->format('Ymd_His') . '.pdf';
            $pdf = Pdf::loadView('pdf.documents', [
                'documents' => $items,
                'user'      => $user,
            ])->setPaper('a4');
            return $pdf->download($fileName);
        }

        return $this->success($items);
    }

    private function convertPdfToPng(string $pdfPath): ?string
    {
        $dir = storage_path('app/public/ocr');

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $base = $dir . '/' . uniqid('pdf_');

        $cmd = sprintf(
            'pdftoppm -png -f 1 -l 1 %s %s 2>&1',
            escapeshellarg($pdfPath),
            escapeshellarg($base)
        );

        exec($cmd, $out, $status);

        $files = glob($base . '-*.png');

        if (!$files || !file_exists($files[0])) {
            \Log::error('pdftoppm failed', [
                'cmd' => $cmd,
                'output' => $out,
                'status' => $status,
            ]);
            return null;
        }

$imagePath = $files[0]; // первая страница

return $imagePath;
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
                    property: "is_public",
                    type: "boolean",
                    example: true
                ),

                new OA\Property(property: "only_view", type: "boolean"),
                new OA\Property(property: "confidential", type: "boolean"),
                      /* ==========================================================
                     * ARRAY: translations[0][lang], translations[0][file]
                     * ========================================================== */

                      new OA\Property(
                        property: "categories[0][id]",
                        type: "integer",
                        example: "1"
                    ),

                      new OA\Property(
                        property: "categories[1][id]",
                        type: "integer",
                        example: "1"
                    ),

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

                      new OA\Property(
                        property: "file",
                        type: "string",
                        format: "binary",
                        description: "Main document file (PDF/DOCX/Image)"
                    ),

                      new OA\Property(
                        property: "attachments[0][file]",
                        type: "string",
                        format: "binary",
                        description: "attachment 1 for document"
                    ), 

                      new OA\Property(
                        property: "attachments[1][file]",
                        type: "string",
                        format: "binary",
                        description: "attachment 2 for document"
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
                        property: "translations[0][description]",
                        type: "string",
                        example: "Контракт RU"
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
                        property: "translations[1][description]",
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

protected function makeUniqueDocumentSlug(string $title): string
{
    $baseSlug = Str::slug($title) ?: Str::random(8);
    $slug = $baseSlug;
    $i = 1;

    while (Document::where('slug', $slug)->exists()) {
        $slug = $baseSlug . '-' . $i;
        $i++;
    }

    return $slug;
}

protected function extractTextFromUploadedFile(string $fullPath, ?string $ext, string $lang): string
{
    if (!file_exists($fullPath)) {
        Log::error('OCR file missing', ['path' => $fullPath]);
        return '';
    }

    $ext = strtolower((string) $ext);

    try {
        if ($ext === 'pdf') {
            $imagePath = $this->convertPdfToPng($fullPath);

            if (!$imagePath) {
                Log::error('PDF convert failed', ['file' => $fullPath]);
                return '';
            }

            return $this->runTesseract($imagePath, $this->mapLangForTesseract($lang));
        }

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            return $this->runTesseract($fullPath, $this->mapLangForTesseract($lang));
        }

        if ($ext === 'docx') {
            $phpWord = IOFactory::load($fullPath);
            $text = '';

            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    if (method_exists($element, 'getText')) {
                        $text .= $element->getText() . "\n";
                    }
                }
            }

            return trim($text);
        }

        if ($ext === 'txt') {
            return trim((string) file_get_contents($fullPath));
        }

        Log::warning('Unsupported file type', ['ext' => $ext, 'file' => $fullPath]);
        return '';
    } catch (\Throwable $e) {
        Log::error('Text extraction failed', [
            'file' => $fullPath,
            'ext' => $ext,
            'lang' => $lang,
            'error' => $e->getMessage(),
        ]);

        return '';
    }
}
public function store(Request $request)
{
    $user = auth()->user();

    if (!$user || $user->hasRole('user') || $user->hasRole('accountant')) {
        abort(403, 'Forbidden');
    }

    $validated = $request->validate([
        'is_public'             => 'nullable|in:0,1,true,false,TRUE,FALSE,True,False',
        'only_view'             => 'nullable|in:0,1,true,false,TRUE,FALSE,True,False',
        'confidential'             => 'nullable|in:0,1,true,false,TRUE,FALSE,True,False',

        'file'                   => 'nullable|file',

        'categories'          => 'required|array',
        'categories.*.id'   => 'required|integer',
        'functions'          => 'required|array',
        'functions.*.id'   => 'required|integer',
        'attachments'          => 'nullable|array',
        'attachments.*.file' => 'nullable|file',
        'translations'          => 'required|array',
        'translations.*.lang'   => 'required|string|in:en,ru,uk',
        'translations.*.title'  => 'required|string|max:255',
        'translations.*.file' => 'nullable|file',
        'translations.*.description' => 'nullable|string',
    ]);

    $request->is_public = $request->is_public == 'true' ||  $request->is_public == 1 ? 1 : 0;

    $request->only_view = $request->only_view == 'true' || $request->only_view == 1 ? 1 : 0;

    $request->confidential = $request->confidential == 'true'  || $request->confidential == 1 ? 1 : 0;

    $categories = collect($validated['categories'])->pluck('id')->map(fn ($id) => (int) $id)->all();
    $functions  = collect($validated['functions'])->pluck('id')->map(fn ($id) => (int) $id)->all();

    $document = DB::transaction(function () use ($validated, $categories, $functions, $user, $request) {
        $baseTitle = $validated['translations'][0]['title'] ?? Str::random(8);

        $document = Document::create([
            'only_view'    => (bool)($validated['only_view'] ?? false),
            'confidential' => (bool)($validated['confidential'] ?? false),
            'is_public'    => (bool)($validated['is_public'] ?? false),
            'created_by'   => $user->id,
        ]);

        if ($request->hasFile('file')) {
            $document->file_path = $request->file('file')->store('documents', 'public');
            $document->save();
        }

        $document->categories()->sync($categories);
        $document->functions()->sync($functions);

        foreach (($validated['attachments'] ?? []) as $index => $attachmentRow) {
            if ($request->hasFile("attachments.$index.file")) {
                $path = $request->file("attachments.$index.file")->store('attachments', 'public');

                DocumentAttachment::create([
                    'document_id' => $document->id,
                    'file'        => $path,
                ]);
            }
        }

        foreach ($validated['translations'] as $index => $translationRow) {
            $lang = $translationRow['lang'];
            $title = $translationRow['title'];
            $summary = $translationRow['description'] ?? null;

            $path = null;
            $ext = null;
            $text = '';

            if ($request->hasFile("translations.$index.file")) {
                $uploadedFile = $request->file("translations.$index.file");
                $path = $uploadedFile->store('ocr', 'public');
                $fullPath = storage_path('app/public/' . $path);
                $ext = strtolower($uploadedFile->getClientOriginalExtension());

                $text = $this->extractTextFromUploadedFile($fullPath, $ext, $lang);
            }

            $contentForEmbedding = trim(
                collect([$title, $summary, $text])
                ->filter(fn ($value) => filled($value))
                ->implode("\n")
            );

            if ($contentForEmbedding !== '') {
                try {
                    $ollama = OllamaClient::make();
                    $vec = $ollama->embed($contentForEmbedding);

                    DocumentEmbedding::updateOrCreate(
                        [
                            'document_id' => $document->id,
                            'lang'        => $lang,
                        ],
                        [
                            'embedding'   => $vec,
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::error('Embedding generation failed', [
                        'document_id' => $document->id,
                        'lang' => $lang,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            DocumentTranslation::create([
                'document_id' => $document->id,
                'lang'        => $lang,
                'title'       => $title,
                'file'        => $path,
                'content'     => $text,
                'summary'     => $summary,
                'file_path'   => $path,
                'file_type'   => $ext,
            ]);
        }

        Event::create([
            'user_id'  => $user->id,
            'action'   => 'created',
            'model'    => 'document',
            'model_id' => $document->id,
        ]);

        return $document;
    });

    return $this->success(
        $document->load([
            'translations',
            'attachments',
            'categories.translations',
            'functions',
        ]),
        'Created',
        201
    );
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

        $doc = Document::with(['translations', 'categories','attachments', 'functions'])->find($id);

        if (!$doc) return $this->error("Not found", 404);

        $doc->translated = $doc->getTranslation($lang);

        DocumentView::create([
            'document_id' => $doc->id,
            'user_id' => auth()->id()
        ]);

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
 #[OA\Post(
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

                    new OA\Property(property: "is_public", type: "boolean"),
                    new OA\Property(property: "only_view", type: "boolean"),
                    new OA\Property(property: "confidential", type: "boolean"),
                      /* ==========================================================
                     * ARRAY: translations[0][lang], translations[0][file]
                     * ========================================================== */

                      new OA\Property(
                        property: "categories[0][id]",
                        type: "integer",
                        example: "1"
                    ),

                      new OA\Property(
                        property: "categories[1][id]",
                        type: "integer",
                        example: "1"
                    ),

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

                      new OA\Property(
                        property: "attachments[0][file]",
                        type: "string",
                        format: "binary",
                        description: "attachment 1 for document"
                    ), 

                      new OA\Property(
                        property: "attachments[1][file]",
                        type: "string",
                        format: "binary",
                        description: "attachment 2 for document"
                    ), 

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
                        property: "translations[0][description]",
                        type: "string",
                        example: "Контракт RU"
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
                        property: "translations[1][description]",
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
    $user = auth()->user();
    if (!$user || $user->hasRole('user') || $user->hasRole('accountant')) {
        abort(403, "Forbidden");
    }
    $doc = Document::find($id);
    if (!$doc) return $this->error("Not found", 404);

    $validated = $request->validate([
       'is_public'             => 'nullable|in:0,1,true,false,TRUE,FALSE,True,False',
       'only_view'             => 'nullable|in:0,1,true,false,TRUE,FALSE,True,False',
       'confidential'             => 'nullable|in:0,1,true,false,TRUE,FALSE,True,False',

       'file' => 'nullable|file',


       'categories'          => 'required|array',
       'categories.*.id'   => 'required|integer',
       'functions'          => 'required|array',
       'functions.*.id'   => 'required|integer',
       'attachments'          => 'nullable|array',
       'attachments.*.file' => 'nullable|file',
       'translations'          => 'required|array',
       'translations.*.lang'   => 'required|string|in:en,ru,uk',
       'translations.*.title'  => 'required|string|max:255',
       'translations.*.file' => 'nullable|file',
       'translations.*.description' => 'nullable|string',
   ]);


    $request->is_public = $request->is_public == 'true' ||  $request->is_public == 1 ? 1 : 0;

    $request->only_view = $request->only_view == 'true' || $request->only_view == 1 ? 1 : 0;

    $request->confidential = $request->confidential == 'true'  || $request->confidential == 1 ? 1 : 0;

    $categories = collect($validated['categories'])->pluck('id')->map(fn ($id) => (int) $id)->all();
    $functions  = collect($validated['functions'])->pluck('id')->map(fn ($id) => (int) $id)->all();

    DB::transaction(function () use ($request, $validated, $doc, $categories, $functions, $user) {
        $doc->update([
            'is_public'    => (bool)($validated['is_public'] ?? $doc->is_public),
            'only_view'    => (bool)($validated['only_view'] ?? $doc->only_view),
            'confidential' => (bool)($validated['confidential'] ?? $doc->confidential),
        ]);

        if ($request->hasFile('file')) {
            if ($doc->file_path && Storage::disk('public')->exists($doc->file_path)) {
                Storage::disk('public')->delete($doc->file_path);
            }

            $doc->file_path = $request->file('file')->store('documents', 'public');
            $doc->save();
        }

        $doc->categories()->sync($categories);
        $doc->functions()->sync($functions);

        foreach (($validated['attachments'] ?? []) as $index => $attachmentRow) {
            if ($request->hasFile("attachments.$index.file")) {
                $path = $request->file("attachments.$index.file")->store('attachments', 'public');

                DocumentAttachment::create([
                    'document_id' => $doc->id,
                    'file'        => $path,
                ]);
            }
        }

        foreach ($validated['translations'] as $index => $translationRow) {
            $lang = $translationRow['lang'];
            $title = $translationRow['title'];
            $summary = $translationRow['description'] ?? null;

            $translation = DocumentTranslation::where('document_id', $doc->id)
            ->where('lang', $lang)
            ->first();

            $text = $translation->content ?? '';
            $path = $translation->file_path ?? $translation->file ?? null;
            $ext = $translation->file_type ?? null;

            if ($request->hasFile("translations.$index.file")) {
                $uploadedFile = $request->file("translations.$index.file");

                if ($translation) {
                    $oldFile = $translation->file_path ?: $translation->file;
                    if ($oldFile && Storage::disk('public')->exists($oldFile)) {
                        Storage::disk('public')->delete($oldFile);
                    }
                }

                $path = $uploadedFile->store('ocr', 'public');
                $fullPath = storage_path('app/public/' . $path);
                $ext = strtolower($uploadedFile->getClientOriginalExtension());

                $text = $this->extractTextFromUploadedFile($fullPath, $ext, $lang);
            }

            $contentForEmbedding = trim(
                collect([$title, $summary, $text])
                ->filter(fn ($value) => filled($value))
                ->implode("\n")
            );

            if ($contentForEmbedding !== '') {
                try {
                    $ollama = OllamaClient::make();
                    $vec = $ollama->embed($contentForEmbedding);

                    DocumentEmbedding::updateOrCreate(
                        [
                            'document_id' => $doc->id,
                            'lang'        => $lang,
                        ],
                        [
                            'embedding'   => $vec,
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::error('Embedding generation failed on update', [
                        'document_id' => $doc->id,
                        'lang' => $lang,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            DocumentTranslation::updateOrCreate(
                [
                    'document_id' => $doc->id,
                    'lang'        => $lang,
                ],
                [
                    'title'      => $title,
                    'content'    => $text,
                    'summary'    => $summary,
                    'file'       => $path,
                    'file_path'  => $path,
                    'file_type'  => $ext,
                ]
            );
        }

        Event::create([
            'user_id'  => $user->id,
            'action'   => 'updated',
            'model'    => 'document',
            'model_id' => $doc->id,
        ]);
    });

return $this->success(
    $doc->fresh()->load([
        'translations',
        'attachments',
        'categories',
        'functions',
    ]),
    'Updated'
);
}


    /* ======================================================
     | DELETE DOCUMENT
     ====================================================== */
    #[OA\Delete(
     path: "/api/documents/{id}",
     summary: "Delete document",
     tags: ["Documents"],
     parameters: [
        new OA\Parameter(name: "id", in: "path", schema: new OA\Schema(type: "integer")),
        new OA\Parameter(name: "lang", in: "query", schema: new OA\Schema(type: "string"))
    ],
    security: [["sanctum" => []]],
    responses: [
        new OA\Response(response: 200, description: "Deleted")
    ]
)]
    public function destroy($id)
    {
        $doc = Document::find($id);
        $user = auth()->user();
        if (!$user || $user->hasRole('user') || $user->hasRole('accountant') || ($user->hasRole('editor') && $doc->created_by !== $user->id)) {
            abort(403, "Forbidden");
        }
        if (!$doc) return $this->error("Not found", 404);

        Storage::delete($doc->file_path);

        Event::create([
            'user_id' => auth()->user()->id,
            'action'  => 'deleted',
            'model' => 'document',
            'model_id' => $doc->id,
            'deleted_title' => $doc->getTranslation('en')['title'] 
        ]);

        $doc->translations()->delete();
        $doc->delete();

        return $this->success(null, "Deleted");
    }

      /* ======================================================
     | DELETE DOCUMENT
     ====================================================== */
    #[OA\Delete(
     path: "/api/delete_attachment/{id}",
     summary: "Delete attachment",
     tags: ["Documents"],
     parameters: [
        new OA\Parameter(name: "id", in: "path", schema: new OA\Schema(type: "integer")),
    ],
    security: [["sanctum" => []]],
    responses: [
        new OA\Response(response: 200, description: "Deleted")
    ]
)]
    public function delete_attachment($id)
    {
        $user = auth()->user(); 
        if (!$user || $user->hasRole('user')) {
            abort(403, "Forbidden");
        }

        $doc = DocumentAttachment::find($id);

        if (!$doc) return $this->error("Not found", 404);

        Storage::delete($doc->file);

        $doc->delete();

        return $this->success(null, "Deleted");
    }

    /* ======================================================
| FILE DOWNLOAD LIST XLSX BY IDS
====================================================== */
#[OA\Get(
path: "/api/documents/download/xsl",
summary: "Download documents list as XLSX by ids",
tags: ["Documents"],
security: [["sanctum" => []]],
parameters: [
    new OA\Parameter(
        name: "ids",
        in: "query",
        description: "Document IDs",
        required: true,
        style: "form",
        explode: true,
        schema: new OA\Schema(
            type: "array",
            items: new OA\Items(type: "integer")
        )
    ),
    new OA\Parameter(
        name: "lang",
        in: "query",
        schema: new OA\Schema(type: "string", default: "en")
    )
],
responses: [
    new OA\Response(response: 200, description: "Documents XLSX downloaded"),
    new OA\Response(response: 422, description: "Validation error")
]
)]
public function downloadXsl(Request $request)
{
    $request->validate([
        'ids' => ['nullable', 'array', 'min:1'],
        'ids.*' => ['integer', 'exists:documents,id'],
        'lang' => ['nullable', 'string'],
    ]);

    $lang = $request->get('lang', 'en');
    $ids = array_map('intval', $request->get('ids', []));

    $items = $ids ? Document::whereIn('id', $ids)
    ->with(['translations', 'categories', 'functions'])
    ->get()
    ->sortBy(function ($doc) use ($ids) {
        return array_search($doc->id, $ids);
    })
    ->values() : Document::with(['translations', 'categories', 'functions'])
    ->get()
    ->sortBy(function ($doc) use ($ids) {
        return array_search($doc->id, $ids);
    })
    ->values();

    $items->transform(function ($doc) use ($lang) {
        $doc->translated = $doc->getTranslation($lang);

        $doc->views_last_30_days = $doc->views()
        ->where('created_at', '>=', now()->subDays(30))
        ->count();

        $doc->ai_searches_last_30_days = isset($doc->translated['title'])
        ? ChatMessage::where('role', 'user')
        ->where('created_at', '>=', now()->subDays(30))
        ->where('content', 'like', '%' . $doc->translated['title'] . '%')
        ->count()
        : 0;

        return $doc;
    });

    $fileName = 'documents_list_' . now()->format('Ymd_His') . '.xlsx';

    return Excel::download(new DocumentsExport($items), $fileName);
}


/* ======================================================
| FILE DOWNLOAD LIST PDF BY IDS
====================================================== */
#[OA\Get(
path: "/api/documents/download/pdf",
summary: "Download documents list as PDF by ids",
tags: ["Documents"],
security: [["sanctum" => []]],
parameters: [
    new OA\Parameter(
        name: "ids",
        in: "query",
        description: "Document IDs",
        required: true,
        style: "form",
        explode: true,
        schema: new OA\Schema(
            type: "array",
            items: new OA\Items(type: "integer")
        )
    ),
    new OA\Parameter(
        name: "lang",
        in: "query",
        schema: new OA\Schema(type: "string", default: "en")
    ),
    new OA\Parameter(
        name: "isView",
        in: "query",
        schema: new OA\Schema(type: "boolean")
    )
],
responses: [
    new OA\Response(response: 200, description: "Documents PDF downloaded"),
    new OA\Response(response: 422, description: "Validation error")
]
)]
public function downloadPDF(Request $request)
{
    $request->validate([
        'ids' => ['nullable', 'array', 'min:1'],
        'ids.*' => ['integer', 'exists:documents,id'],
        'lang' => ['nullable', 'string'],
        'isView' => ['nullable'],
    ]);

    $lang = $request->get('lang', 'en');
    $ids = array_map('intval', $request->get('ids', []));

    $items = $ids ? Document::whereIn('id', $ids)
    ->with(['translations', 'categories', 'functions'])
    ->get()
    ->sortBy(function ($doc) use ($ids) {
        return array_search($doc->id, $ids);
    })
    ->values() : Document::with(['translations', 'categories', 'functions'])
    ->get()
    ->sortBy(function ($doc) use ($ids) {
        return array_search($doc->id, $ids);
    })
    ->values();

    $items->transform(function ($doc) use ($lang) {
        $doc->translated = $doc->getTranslation($lang);

        $doc->views_last_30_days = $doc->views()
        ->where('created_at', '>=', now()->subDays(30))
        ->count();

        $doc->ai_searches_last_30_days = isset($doc->translated['title'])
        ? ChatMessage::where('role', 'user')
        ->where('created_at', '>=', now()->subDays(30))
        ->where('content', 'like', '%' . $doc->translated['title'] . '%')
        ->count()
        : 0;

        return $doc;
    });

    $fileName = 'documents_list_' . now()->format('Ymd_His') . '.pdf';

    $pdf = Pdf::loadView('pdf.documents', [
        'documents' => $items,
        'user' => auth()->user(),
    ])->setPaper('a4');

    if ($request->boolean('isView')) {
        return response($pdf->output(), 200)
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'inline; filename="' . $fileName . '"');
    }

    return $pdf->download($fileName);
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
