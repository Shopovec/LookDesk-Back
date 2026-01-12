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
        return $this->hasMany(Document::class);
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
}
