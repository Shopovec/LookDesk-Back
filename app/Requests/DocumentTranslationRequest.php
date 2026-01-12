<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "CategoryRequest",
    required: ["title"],
    properties: [
        new OA\Property(property: "title", type: "string", example: "Finance"),
        new OA\Property(property: "description", type: "string", example: "Documents related to finance"),
        new OA\Property(property: "parent_id", type: "integer", nullable: true, example: 3),
        new OA\Property(property: "lang", type: "string", example: "en"),
    ]
)]
class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'parent_id'   => 'nullable|integer|exists:categories,id',
            'lang'        => 'nullable|string|in:en,ru,uk',
        ];
    }
}