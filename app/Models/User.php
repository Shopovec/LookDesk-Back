<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, SoftDeletes;

    protected $fillable = [
        'stripe_payment_method_id',
        'payment_method_brand',
        'payment_method_last4',
        'payment_method_exp',
        'name',
        'email',
        'password',
        'role_id',
        'client_creator_id',
        'avatar',
        'settings',
        'events_on',
        'stripe_customer_id',
        'verification_code',
        'is_verified',
        'last_seen_at'
    ];

    protected $casts = [
        'settings' => 'array',
        'last_seen_at' => 'datetime',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function documents()
    {
        return $this->hasMany(Document::class, 'created_by', 'id');
    }

    public function sessions()
    {
        return $this->hasMany(ChatSession::class, 'user_id', 'id');
    }

    public function teamUsers()
    {
    // users.client_creator_id = this.id
        return $this->hasMany(User::class, 'client_creator_id', 'id');
    }

    public function documentsTeam()
    {
    // users.client_creator_id = this.id
    // documents.created_by = users.id
        return $this->hasManyThrough(
            Document::class,
            User::class,
        'client_creator_id', // FK на users (team)
        'created_by',        // FK на documents
        'id',                // this.id
        'id'                 // users.id
    );
    }

    public function sessionsTeam()
    {
    // users.client_creator_id = this.id
    // chat_sessions.user_id = users.id
        return $this->hasManyThrough(
            ChatSession::class,
            User::class,
        'client_creator_id', // FK на users (team)
        'user_id',           // FK на chat_sessions
        'id',                // this.id
        'id'                 // users.id
    );
    }

    public function clientUsers()
    {
    // users.client_creator_id -> users.id
        return $this->hasMany(User::class, 'client_creator_id', 'id');
    }

    protected static function booted()
    {
        static::created(function ($user) {
        // берем все system-функции
            $functionIds = FunctionZ::query()
            ->where('is_system', 1)
            ->pluck('id');

        // прикрепляем, не снося возможные уже добавленные (на всякий случай)
            $user->functions()->syncWithoutDetaching($functionIds->all());
        });
    }

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

    public function clientCreator()
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

    public function payments()
    {
    // вся история оплат (invoice.payment_succeeded / failed)
        return $this->hasMany(SubscriptionPayment::class, 'user_id', 'id');
    }

    public function paymentsPaid()
    {
    // только успешные
        return $this->payments()->where('status', 'paid')->orderByDesc('paid_at');
    }

    public function lastPayment()
    {
    // последний платёж (Laravel 8+)
        return $this->hasOne(SubscriptionPayment::class, 'user_id', 'id')
        ->latestOfMany('paid_at');
    }


}