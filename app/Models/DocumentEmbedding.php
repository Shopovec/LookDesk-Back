<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentEmbedding extends Model
{
    protected $fillable = ['document_id', 'lang', 'embedding'];
    protected $casts = ['embedding' => 'array'];

    public function document() { return $this->belongsTo(Document::class); }
}
