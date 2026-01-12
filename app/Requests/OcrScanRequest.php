<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "OcrScanRequest",
    required: ["image"],
    properties: [
        new OA\Property(property: "image", type: "string", format: "binary"),
    ]
)]
class OcrScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'image' => 'required|image|max:20480', // 20 MB, JPG/PNG
        ];
    }
}
