<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

class SearchService
{
    public function search($query, $lang = 'en')
    {
        // Search in document translations
        $docs = Document::query()
            ->select('documents.*')
            ->join('document_translations as dt', 'dt.document_id', '=', 'documents.id')
            ->where('dt.lang', $lang)
            ->where(function ($q) use ($query) {
                $q->where('dt.title', 'like', "%{$query}%")
                  ->orWhere('dt.content', 'like', "%{$query}%")
                  ->orWhere('dt.summary', 'like', "%{$query}%");
            })
            ->distinct()
            ->get();

        // Search in categories
        $cats = Category::query()
            ->select('categories.*')
            ->join('category_translations as ct', 'ct.category_id', '=', 'categories.id')
            ->where('ct.lang', $lang)
            ->where('ct.title', 'like', "%{$query}%")
            ->distinct()
            ->get();

        return [
            'documents'  => $docs,
            'categories' => $cats,
        ];
    }
}
