<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FunctionTranslation extends Model
{
    protected $fillable = [
        'function_id',
        'lang',
        'title',
        'description',
    ];

    public function function()
    {
        return $this->belongsTo(FunctionZ::class, 'function_id');
    }
}