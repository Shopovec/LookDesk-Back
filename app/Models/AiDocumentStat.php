<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiDocumentStat extends Model
{
    protected $fillable = [
        'document_id',
        'positive',
        'negative',
        'bias'
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}