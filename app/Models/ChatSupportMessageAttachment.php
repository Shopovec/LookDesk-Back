<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;


class ChatSupportMessageAttachment extends Model
{
    protected $fillable = [
        'chat_support_message_id',
        'file'
    ];

    protected $appends = ['file_url'];

    public function message()
    {
        return $this->belongsTo(ChatSupportMessage::class);
    }


    public function getFileUrlAttribute()
    {
        return $this->file ? url(Storage::url($this->file)) : null;

    }
}