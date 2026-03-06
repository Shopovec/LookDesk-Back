<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPayment extends Model
{
    protected $fillable = [
        'user_id','subscription_id',
        'stripe_invoice_id','stripe_subscription_id',
        'amount_paid','currency','status','paid_at',
        'period_start','period_end',
        'hosted_invoice_url','invoice_pdf','raw',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'raw' => 'array',
    ];
}