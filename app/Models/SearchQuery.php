<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchQuery extends Model
{
    protected $fillable = ['user_id', 'lang', 'query', 'embedding'];
    protected $casts = ['embedding' => 'array'];

    public function user() { return $this->belongsTo(User::class); }
    public function session() { return $this->hasOne(ChatSession::class); }
}
