<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "UserUpdateRequest",
    properties: [
        new OA\Property(property: "name", type: "string", example: "John Smith"),
        new OA\Property(property: "email", type: "string", example: "john@example.com"),
        new OA\Property(property: "password", type: "string", example: "newpassword123"),
        new OA\Property(property: "role_id", type: "integer", example: 3),
    ]
)]
class UserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()->role_id <= 2; // admin & owner only
    }

    public function rules(): array
    {
        return [
            'name'     => 'sometimes|string|max:255',
            'email'    => 'sometimes|email|max:255|unique:users,email,' . $this->user,
            'password' => 'sometimes|string|min:6|max:255',
            'role_id'  => 'sometimes|integer|exists:roles,id',
        ];
    }
}
