<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasTranslations;

class Document extends Model
{
    use HasTranslations;

    protected $fillable = [
        'is_favorite',
        'is_archived',
        'file_path',
        'type',
        'meta',
        'created_by',
        'updated_by',
        'confidential',
        'only_view'
    ];

    protected $casts = [
        'meta' => 'array',
        'is_favorite' => 'boolean',
        'is_archived' => 'boolean',
        'confidential' => 'boolean',
        'only_view' => 'boolean'
    ];

    public function translations()
    {
        return $this->hasMany(DocumentTranslation::class);
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'document_category', 'document_id', 'category_id');
    }
    
    public function functions()
    {
        return $this->belongsToMany(FunctionZ::class, 'document_function', 'document_id', 'function_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updator()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Helpers for API output
    public function getTitle($lang = null)
    {
        return $this->translate('title', $lang);
    }

    public function getContent($lang = null)
    {
        return $this->translate('content', $lang);
    }

    public function getSummary($lang = null)
    {
        return $this->translate('summary', $lang);
    }

    public function getTranslation($lang = null)
    {
        $tr = $this->translations
        ->firstWhere('lang', $lang)
        ?? $this->translations->first();

        if (!$tr) return null;

        return [
            'lang' => $tr->lang,
            'title' => $tr->title,
            'description' => $tr->description,
        ];
    }
}