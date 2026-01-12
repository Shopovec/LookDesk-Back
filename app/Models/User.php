<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'avatar',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    // Permission helpers
    public function isOwner()
    {
        return $this->role->name === 'owner';
    }

    public function isAdmin()
    {
        return $this->role->name === 'admin' || $this->isOwner();
    }

    public function isEditor()
    {
        return in_array($this->role->name, ['editor', 'admin', 'owner']);
    }

    public function isUser()
    {
        return true;
    }
}