<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatSupportMessage extends Model
{
    protected $fillable = [
        'chat_thread_id',
        'user_id',
        'role',
        'content',
        'is_read'
    ];

    public function thread()
    {
        return $this->belongsTo(ChatThread::class, 'chat_thread_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}