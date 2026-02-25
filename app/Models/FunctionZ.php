<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasTranslations;

class FunctionZ extends Model
{
    use HasTranslations;


    protected $table = 'functions';

    protected $fillable = [
        'is_system'
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_function', 'function_id', 'user_id');
    }

    public function translations()
    {
        return $this->hasMany(FunctionTranslation::class, 'function_id');
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
