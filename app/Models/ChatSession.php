<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatSession extends Model
{
    protected $fillable = ['user_id', 'search_query_id', 'is_closed'];
    protected $casts = ['is_closed' => 'boolean'];

    public function user() { return $this->belongsTo(User::class); }
    public function search_query() { return $this->belongsTo(SearchQuery::class, 'search_query_id'); }
    public function messages() { return $this->hasMany(ChatMessage::class); }
}
