<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanPrice extends Model
{
    protected $fillable = [
        'plan_id',
        'period',
        'price',
        'currency',
        'per_user',
        'min_users',
        'max_users',
        'trial_days',
        'stripe_price_id',
        'stripe_product_id'
    ];

    protected $casts = [
        'per_user' => 'boolean',
        'price' => 'decimal:2',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }
}