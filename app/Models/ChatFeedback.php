<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatFeedback extends Model
{
    protected $table = 'chat_feedbacks';
    protected $fillable = ['chat_message_id', 'is_useful', 'comment'];
    protected $casts = ['is_useful' => 'boolean'];

    public function message() { return $this->belongsTo(ChatMessage::class, 'chat_message_id'); }
}
