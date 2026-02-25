<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = [
        'name',
        'description',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function features()
    {
        return $this->hasMany(RoleFeature::class)->orderBy('sort', 'ASC');
    }
}