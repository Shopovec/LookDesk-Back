<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentTranslation extends Model
{
    protected $fillable = [
        'document_id',
        'lang',
        'title',
        'content',
        'summary',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}