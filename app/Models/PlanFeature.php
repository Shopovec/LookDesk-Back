<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanFeature extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'plan_id',
        'title',
        'sort',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }
}