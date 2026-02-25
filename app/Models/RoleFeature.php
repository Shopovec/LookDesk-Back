<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoleFeature extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'role_id',
        'title',
        'sort',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}