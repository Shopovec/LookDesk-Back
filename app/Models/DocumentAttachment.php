<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;


class DocumentAttachment extends Model
{
    protected $fillable = [
        'document_id',
        'file'
    ];

    protected $appends = ['file_url'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }


    public function getFileUrlAttribute()
    {
        return $this->file ? url(Storage::url($this->file)) : null;

    }
}