<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentTranslation;
use App\Models\DocumentAttachment;
use App\Models\Event;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
use App\Models\DocumentEmbedding;
use App\Services\OllamaClient;
use App\Models\DocumentView;
use App\Models\ChatMessage;
use PhpOffice\PhpWord\IOFactory;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\DocumentsExport;

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

        $q = Document::with(['translations','attachments','categories','functions']);

        if ($request->category_id) {
            $q->whereHas('categories', fn($x) =>
                $x->where('categories.id', $request->category_id)
            );
        }

        if ($request->function_id) {
            $q->whereHas('functions', fn($x) =>
                $x->where('functions.id', $request->function_id)
            );
        }

        if ($request->search) {
            $q->whereHas('translations', fn($x)=>
                $x->where('title','like',"%{$request->search}%")
                ->orWhere('content','like',"%{$request->search}%")
            );
        }

        $items = $q->orderBy('id', 'desc')->get();

        $items->transform(function ($doc) use ($lang) {

           DocumentView::create([
            'document_id' => $doc->id,
            'user_id' => auth()->id()
        ]);

           $doc->translated = $doc->getTranslation($lang);

           $doc->views_last_30_days = $doc->views()
           ->where('created_at','>=',now()->subDays(30))
           ->count();

           $doc->ai_searches_last_30_days = isset($doc->translated['title']) ? ChatMessage::where('role','user')
           ->where('created_at','>=',now()->subDays(30))
           ->where('content','like','%'.$doc->translated['title'].'%')
           ->count() : 0;

           return $doc;
       });

        // ✅ EXPORT XLSX
        if ($request->isExportXSL) {
            $fileName = 'documents_' . now()->format('Ymd_His') . '.xlsx';
            return Excel::download(new DocumentsExport($items), $fileName);
        }

    // ✅ EXPORT PDF
        if ($request->isExportPDF) {
            $fileName = 'documents_' . now()->format('Ymd_His') . '.pdf';

            $pdf = Pdf::loadView('pdf.documents', [
                'documents' => $items,
                'user' => auth()->user(),
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
public function store(Request $request)
{
    $request->validate([
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

    $request->only_view = $request->only_view == 'true' || true || 1 ? 1 : 0;
    $request->confidential = $request->confidential == 'true' || true || 1 ? 1 : 0;


    $categories = collect($request->categories)->pluck('id')->toArray();
    $functions  = collect($request->functions)->pluck('id')->toArray();

    $document = Document::create([
        'only_view'   =>  $request->only_view ?? false,
        'confidential'   =>  $request->confidential ?? false,
        'is_public'   => $request->is_public ?? false,
        'created_by'     => auth()->id(),
        'slug'        => Str::slug($request->translations[0]['title'] ?? Str::random(8)),
    ]);

    if ($request->hasFile('file')) {

        $file = $request->file('file');

        $name = $file->getClientOriginalName();

        $path = $file->storeAs('documents', $name, 'public');

        $ext  = strtolower($request->file('file')->getClientOriginalExtension());

        $document->file_path = $path;
        $document->save();
    }


    $document->categories()->sync($categories ?? []);
    $document->functions()->sync($functions ?? []);

    foreach ($request->attachments ?? [] as $t) {


        if (!empty($t['file'])) {

         $file = $t['file'];

         $name = $file->getClientOriginalName();

         $path = $file->storeAs('attacments', $name, 'public');

         DocumentAttachment::create([
            'document_id' => $document->id,'file' =>  $path ]);

     }
 }


 foreach ($request->translations as $t) {

    // 1. Текст по умолчанию из description
    $text = '';

    $path = null;
    $ext  = null;

    // 2. Если передан файл — OCR
    if (!empty($t['file'])) {

     $file = $t['file'];

     $name = $file->getClientOriginalName();

     $path = $file->storeAs('ocr', $name, 'public');
     $fullPath = storage_path('app/public/' . $path);


     if (!file_exists($fullPath)) {
        \Log::error('OCR file missing', ['path' => $fullPath]);
            continue; // не валим весь запрос
        }

        $ext = strtolower($t['file']->getClientOriginalExtension());
        $imagePath = $fullPath;

        if ($ext === 'pdf') {

            $imagePath = $this->convertPdfToPng($fullPath);

            if (!$imagePath) {
                \Log::error('PDF convert failed', ['file' => $fullPath]);
                continue;
            }

            $ocrLang = $this->mapLangForTesseract($t['lang'] ?? 'en');
            $text = $this->runTesseract($imagePath, $ocrLang);

        }
        elseif (in_array($ext, ['jpg','jpeg','png','webp'])) {

            $ocrLang = $this->mapLangForTesseract($t['lang'] ?? 'en');
            $text = $this->runTesseract($fullPath, $ocrLang);

        }
        elseif ($ext === 'docx') {

            try {
                $phpWord = IOFactory::load($fullPath);
                $text = '';

                foreach ($phpWord->getSections() as $section) {
                    foreach ($section->getElements() as $element) {
                        if (method_exists($element, 'getText')) {
                            $text .= $element->getText() . "\n";
                        }
                    }
                }

            } catch (\Exception $e) {
                \Log::error('DOCX read error', ['error' => $e->getMessage()]);
                continue;
            }

        }
        elseif ($ext === 'txt') {

            $text = file_get_contents($fullPath);

        }
        else {

            \Log::warning('Unsupported file type', ['ext' => $ext]);
            continue;

        }
    }


    $text2 = trim(($t['title'] ?? '') . "\n" . ($text));

    $ollama = OllamaClient::make();

    $vec = $ollama->embed($text);

    DocumentEmbedding::updateOrCreate(
        ['document_id' => $document->id, 'lang' => $t['lang']],
        ['embedding' => $vec]
    );

    DocumentTranslation::create([
        'document_id' => $document->id,
        'lang'        => $t['lang'],
        'title'       => $t['title'],
        'file'        => $path,
        'content'     => $text,
        'summary' => $t['description'], 
            'file_path'   => $path,        // лучше сохранить относительный (из store)
            'file_type'   => $ext,
        ]);


}



Event::create([
    'user_id' => auth()->user()->id,
    'action'  => 'created',
    'model' => 'document',
    'model_id' => $document->id,
]);

return $this->success($document->load('translations','attachments','categories','functions'), "Created", 201);
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

    $doc = Document::find($id);
    if (!$doc) return $this->error("Not found", 404);

    $request->validate([
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

    // ----------------------- UPDATE MAIN FILE -----------------------
    if ($request->hasFile('file')) {

        Storage::delete($doc->file_path);

        $file = $request->file('file');

        $name = $file->getClientOriginalName();

        $path = $file->storeAs('documents', $name, 'public');

        $ext  = strtolower($request->file('file')->getClientOriginalExtension());

        $doc->file_path = $path;
        $doc->save();
    }

    $request->only_view = $request->only_view == 'true' || true || 1 ? 1 : 0;
    $request->confidential = $request->confidential == 'true'  || true || 1 ? 1 : 0;

    // ----------------------- BASIC FIELDS -----------------------
    $doc->update([
        'is_public'   => $request->is_public ?? $doc->is_public,
        'only_view'   => $request->only_view ?? $doc->only_view,
        'confidential'   => $request->confidential ?? $doc->confidential
    ]);

    $categories = collect($request->categories)->pluck('id')->toArray();
    $functions  = collect($request->functions)->pluck('id')->toArray();

    $doc->categories()->sync($categories ?? $doc->categories->pluck('id')->toArray());
    $doc->functions()->sync($functions ?? $doc->functions->pluck('id')->toArray());


        foreach ($request->attachments ?? [] as $t) {


        if (!empty($t['file'])) {

         $file = $t['file'];

         $name = $file->getClientOriginalName();

         $path = $file->storeAs('attacments', $name, 'public');

         DocumentAttachment::create([
            'document_id' => $doc->id,'file' =>  $path ]);

     }
 }


    foreach ($request->translations as $t) {

    // 1. Текст по умолчанию из description
        $text = $t['description'] ?? null;

        $path = null;
        $ext  = null;

    // 2. Если передан файл — OCR
        if (!empty($t['file'])) {

            $file = $t['file'];

            $name = $file->getClientOriginalName();

            $path = $file->storeAs('ocr', $name, 'public');
            $fullPath = storage_path('app/public/' . $path);

            if (!file_exists($fullPath)) {
                \Log::error('OCR file missing', ['path' => $fullPath]);
            continue; // не валим весь запрос
        }

        $ext = strtolower($t['file']->getClientOriginalExtension());
        $imagePath = $fullPath;

        if ($ext === 'pdf') {

            $imagePath = $this->convertPdfToPng($fullPath);

            if (!$imagePath) {
                \Log::error('PDF convert failed', ['file' => $fullPath]);
                continue;
            }

            $ocrLang = $this->mapLangForTesseract($t['lang'] ?? 'en');
            $text = $this->runTesseract($imagePath, $ocrLang);

        }
        elseif (in_array($ext, ['jpg','jpeg','png','webp'])) {

            $ocrLang = $this->mapLangForTesseract($t['lang'] ?? 'en');
            $text = $this->runTesseract($fullPath, $ocrLang);

        }
        elseif ($ext === 'docx') {

            try {
                $phpWord = IOFactory::load($fullPath);
                $text = '';

                foreach ($phpWord->getSections() as $section) {
                    foreach ($section->getElements() as $element) {
                        if (method_exists($element, 'getText')) {
                            $text .= $element->getText() . "\n";
                        }
                    }
                }

            } catch (\Exception $e) {
                \Log::error('DOCX read error', ['error' => $e->getMessage()]);
                continue;
            }

        }
        elseif ($ext === 'txt') {

            $text = file_get_contents($fullPath);

        }
        else {

            \Log::warning('Unsupported file type', ['ext' => $ext]);
            continue;

        }
    }



    // 5. Сохранение
    DocumentTranslation::updateOrCreate(
        [
            'document_id' => $doc->id,
            'lang'        => $t['lang']
        ],
        [
            'title'     => $t['title'],
            'content'   => $text,
            'file' => $path
        ]
    );


}

return $this->success($doc->load('translations','attachments','categories','functions'), "Updated");
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

        $doc = DocumentAttachment::find($id);

        if (!$doc) return $this->error("Not found", 404);

        Storage::delete($doc->file);

        $doc->delete();

        return $this->success(null, "Deleted");
    }

     /* ======================================================
     | FILE DOWNLOAD
     ====================================================== */
    #[OA\Get(
     path: "/api/documents/{id}/download/xsl",
     summary: "Download document file",
     tags: ["Documents"],
     parameters: [
        new OA\Parameter(name: "id", in: "path", schema: new OA\Schema(type: "integer")),
        new OA\Parameter(name: "lang", in: "query", schema: new OA\Schema(type: "string"))
    ],
    security: [["sanctum" => []]],

    responses: [
        new OA\Response(response: 200, description: "File downloaded")
    ]
)]

    public function downloadXsl($id, Request $request)
    {
        $lang = $request->get('lang', 'en');
        $q = Document::where('id', $id)->with(['translations','categories','functions']);


        $items = $q->orderBy('id', 'desc')->get();

        $items->transform(function ($doc) use ($lang) {

           DocumentView::create([
            'document_id' => $doc->id,
            'user_id' => auth()->id()
        ]);

           $doc->translated = $doc->getTranslation($lang);

           $doc->views_last_30_days = $doc->views()
           ->where('created_at','>=',now()->subDays(30))
           ->count();

           $doc->ai_searches_last_30_days = isset($doc->translated['title']) ? ChatMessage::where('role','user')
           ->where('created_at','>=',now()->subDays(30))
           ->where('content','like','%'.$doc->translated['title'].'%')
           ->count() : 0;

           return $doc;
       });

        // ✅ EXPORT XLSX

        $fileName = 'documents_' . now()->format('Ymd_His') . '.xlsx';
        return Excel::download(new DocumentsExport($items), $fileName);

    }
    /* ======================================================
     | FILE DOWNLOAD
     ====================================================== */
    #[OA\Get(
     path: "/api/documents/{id}/download/pdf",
     summary: "Download document file",
     tags: ["Documents"],
     parameters: [
        new OA\Parameter(name: "id", in: "path", schema: new OA\Schema(type: "integer")),
        new OA\Parameter(name: "lang", in: "query", schema: new OA\Schema(type: "string")),
        new OA\Parameter(name: "isView", in: "query", schema: new OA\Schema(type: "boolean"))
    ],
    security: [["sanctum" => []]],

    responses: [
        new OA\Response(response: 200, description: "File downloaded")
    ]
)]

    public function downloadPDF($id, Request $request)
    {
        $lang = $request->get('lang', 'en');
        $q = Document::where('id', $id)->with(['translations','categories','functions']);


        $items = $q->orderBy('id', 'desc')->get();

        $items->transform(function ($doc) use ($lang) {

           DocumentView::create([
            'document_id' => $doc->id,
            'user_id' => auth()->id()
        ]);

           $doc->translated = $doc->getTranslation($lang);

           $doc->views_last_30_days = $doc->views()
           ->where('created_at','>=',now()->subDays(30))
           ->count();

           $doc->ai_searches_last_30_days = isset($doc->translated['title']) ? ChatMessage::where('role','user')
           ->where('created_at','>=',now()->subDays(30))
           ->where('content','like','%'.$doc->translated['title'].'%')
           ->count() : 0;

           return $doc;
       });
        $fileName = 'documents_' . now()->format('Ymd_His') . '.pdf';

        $pdf = Pdf::loadView('pdf.documents', [
            'documents' => $items,
            'user' => auth()->user(),
        ])->setPaper('a4');

        if ($request->boolean('isView')) {
            return response($pdf->output(), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$fileName.'"');
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
