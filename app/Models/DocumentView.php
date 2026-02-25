<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentView extends Model
{
    protected $fillable = [
        'document_id',
        'user_id'
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}