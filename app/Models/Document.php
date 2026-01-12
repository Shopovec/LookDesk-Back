<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasTranslations;

class Document extends Model
{
    use HasTranslations;

    protected $fillable = [
        'category_id',
        'is_favorite',
        'is_archived',
        'file_path',
        'type',
        'meta',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'meta' => 'array',
        'is_favorite' => 'boolean',
        'is_archived' => 'boolean',
    ];

    public function translations()
    {
        return $this->hasMany(DocumentTranslation::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
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
}