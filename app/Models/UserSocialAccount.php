<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSocialAccount extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_id',
        'email',
        'profile',
    ];

    protected $casts = [
        'profile' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
