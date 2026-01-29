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
        'client_creator_id',
        'avatar',
        'settings',
        'stripe_customer_id',
        'verification_code',
        'is_verified'
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

    public function hasRole($role)
    {
        return $this->role->name === $role;
    }

    public function functions()
    {
        return $this->belongsToMany(FunctionZ::class, 'user_function', 'user_id', 'function_id');
    }

    public function cliwntCreator()
    {
        return $this->belongsTo(User::class, 'client_creator_id');
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

    public function subscription()
    {
        return $this->hasOne(\App\Models\Subscription::class);
    }


}