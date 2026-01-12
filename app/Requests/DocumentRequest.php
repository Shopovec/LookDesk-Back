<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "DocumentRequest",
    required: ["category_id", "translations", "file"],
    properties: [
        new OA\Property(property: "category_id", type: "integer", example: 5),
        new OA\Property(property: "is_public", type: "boolean", example: true),
        new OA\Property(
            property: "translations",
            type: "string",
            example: '[{"lang":"en","title":"Contract","description":"Contract template"}]'
        ),
        new OA\Property(
            property: "file",
            type: "string",
            format: "binary"
        ),
    ]
)]
class DocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'category_id' => 'required|integer|exists:categories,id',

            // boolean from multipart/form-data
            'is_public'   => 'nullable|in:0,1,true,false,True,False,TRUE,FALSE',

            // JSON string of translations (array of objects)
            'translations' => 'required|string',

            // required only on CREATE (POST)
            'file' => $this->isMethod('post')
                ? 'required|file|max:20480'
                : 'nullable|file|max:20480',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Normalize boolean
        if ($this->has('is_public')) {
            $this->merge([
                'is_public' => filter_var($this->is_public, FILTER_VALIDATE_BOOL),
            ]);
        }

        // Validate JSON structure BEFORE FormRequest rule passes
        if ($this->has('translations')) {
            $json = json_decode($this->translations, true);

            if (!is_array($json)) {
                $this->merge(['translations' => null]);
            }
        }
    }

    public function messages(): array
    {
        return [
            'category_id.required' => 'Category ID is required.',
            'translations.required' => 'Translations field is required.',
            'translations.string'   => 'Translations must be a JSON string.',
            'file.required'        => 'File upload is required.',
            'is_public.in'         => 'The is_public field must be true/false or 0/1.',
        ];
    }
}
