<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OwnerChangeRequest extends Model
{
    protected $fillable = [
        'requested_by',
        'new_owner_email',
        'comment',
        'status',
        'approved_by',
        'approved_at'
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}