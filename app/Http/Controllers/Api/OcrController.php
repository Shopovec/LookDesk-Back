<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OcrScan;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class OcrController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /* ============================================================
     | RUN OCR
     ============================================================ */
    #[OA\Post(
        path: "/api/ocr/scan",
        summary: "Run OCR for image or PDF",
        tags: ["OCR"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: "multipart/form-data",
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: "file", type: "string", format: "binary"),
                        new OA\Property(property: "lang", type: "string", example: "eng"),
                        new OA\Property(property: "document_id", type: "integer", nullable: true),
                    ],
                    required: ["file"]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "OCR result"),
            new OA\Response(response: 400, description: "Bad request")
        ]
    )]
    public function scan(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:20480',
            'lang' => 'nullable|string|in:eng,ukr,rus',
            'document_id' => 'nullable|integer|exists:documents,id',
        ]);

        $lang = $request->lang ?? 'eng';

        // Save original
        $path = $request->file('file')->store('ocr', 'public');
        $fullPath = storage_path('app/public/' . $path);

        if (!file_exists($fullPath)) {
            return $this->error("Failed to store file", 500);
        }

        // Detect extension
        $ext = strtolower($request->file('file')->getClientOriginalExtension());

        // Convert PDF → PNG if needed
        $imagePath = $fullPath;

        if ($ext === 'pdf') {
            $imagePath = $this->convertPdfToPng($fullPath);
            if (!$imagePath) {
                return $this->error("Unable to convert PDF to image", 500);
            }
        }

        // Run OCR
        $text = $this->runTesseract($imagePath, $lang);

        // Save result in DB
        $scan = OcrScan::create([
            'user_id' => auth()->id(),
            'image_path' => $path,
            'extracted_text' => $text,
            'meta' => [
                'lang' => $lang,
                'original_ext' => $ext,
                'converted_image' => ($ext === 'pdf') ? basename($imagePath) : null,
                'document_id' => $request->document_id
            ]
        ]);

        return $this->success([
            'text' => $text,
            'scan' => $scan,
        ]);
    }

    /* ============================================================
     | LIST USER OCR RESULTS
     ============================================================ */
    #[OA\Get(
        path: "/api/ocr/history",
        summary: "Get OCR history of current user",
        tags: ["OCR"],
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(response: 200, description: "User OCR history")
        ]
    )]
    public function history()
    {
        $list = OcrScan::where('user_id', auth()->id())
            ->orderBy('id', 'desc')
            ->paginate(20);

        return $this->success($list);
    }

    /* ============================================================
     | GET SINGLE OCR RESULT
     ============================================================ */
    #[OA\Get(
        path: "/api/ocr/{id}",
        summary: "Get OCR scan by ID",
        tags: ["OCR"],
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(response: 200, description: "OCR record"),
            new OA\Response(response: 404, description: "Not found")
        ]
    )]
    public function show($id)
    {
        $scan = OcrScan::find($id);

        if (!$scan) return $this->error("Not found", 404);

        if ($scan->user_id !== auth()->id() && !in_array(auth()->user()->role_id, [1,2])) {
            return $this->error("Forbidden", 403);
        }

        return $this->success($scan);
    }

    /* ============================================================
     | DELETE OCR RESULT
     ============================================================ */
    #[OA\Delete(
        path: "/api/ocr/{id}",
        summary: "Delete OCR scan record",
        tags: ["OCR"],
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(response: 200, description: "Deleted"),
            new OA\Response(response: 404, description: "Not found")
        ]
    )]
    public function destroy($id)
    {
        $scan = OcrScan::find($id);

        if (!$scan) return $this->error("Not found", 404);

        if ($scan->user_id !== auth()->id() && !in_array(auth()->user()->role_id, [1,2])) {
            return $this->error("Forbidden", 403);
        }

        $scan->delete();
        return $this->success(null, "Deleted");
    }

    /* ============================================================
     | INTERNAL: PDF → PNG converter
     ============================================================ */
    private function convertPdfToPng(string $pdfPath): ?string
    {
        $output = $pdfPath . ".png";
        $cmd = "convert -density 300 {$pdfPath}[0] -quality 100 {$output}";
        exec($cmd, $o, $status);

        return $status === 0 ? $output : null;
    }

    /* ============================================================
     | INTERNAL: Run tesseract
     ============================================================ */
    private function runTesseract(string $imagePath, string $lang): string
    {
        $output = $imagePath . "_ocr";
        $cmd = "tesseract " . escapeshellarg($imagePath) . " " . escapeshellarg($output) . " -l {$lang}";
        exec($cmd);

        $txt = $output . ".txt";
        return file_exists($txt) ? trim(file_get_contents($txt)) : "";
    }
}
