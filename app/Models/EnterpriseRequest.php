<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnterpriseRequest extends Model
{
    protected $fillable = [
        'name',
        'email',
        'employees_range',
        'requirements',
    ];
}