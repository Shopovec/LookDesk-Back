<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CategoryTranslationRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'lang'       => 'required|string|max:10',
            'title'      => 'required|string|max:255',
            'description'=> 'nullable|string',
        ];
    }
}
