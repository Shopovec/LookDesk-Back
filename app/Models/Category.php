<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasTranslations;

class Category extends Model
{
    use HasTranslations;

    protected $fillable = [
        'is_system',
        'is_favorite',
    ];

    public function translations()
    {
        return $this->hasMany(CategoryTranslation::class);
    }

    public function documents()
    {
        return $this->belongsToMany(
            Document::class,
            'document_category',
            'category_id',
            'document_id'
        );
    }

    // Helpers for API output
    public function getTitle($lang = null)
    {
        return $this->translate('title', $lang);
    }

    public function getDescription($lang = null)
    {
        return $this->translate('description', $lang);
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
