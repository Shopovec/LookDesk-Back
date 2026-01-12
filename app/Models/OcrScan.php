<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OcrScan extends Model
{
    protected $fillable = [
        'user_id',
        'image_path',
        'extracted_text',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
