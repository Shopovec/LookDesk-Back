<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;

class Event extends Model
{

    protected $fillable = [
        'user_id',
        'action',
        'model',
        'model_id',
        'deleted_title'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getTitle()
    {
        $title = " ";
        switch ($this->model) :
            case "document": 
            $title = 'Document Events';
            break;
            case "category": 
            $title = 'Category Events';
            break;  
            case "function": 
            $title = 'Function Events';
            break;    
            case "user": 
            $title = 'User Management ';
            break;    
        endswitch;
        return $title;
    }

    public function getLabel()
    {
        $user = $this->user;
        $role = ucfirst($user->role->name);
        $title = $this->deleted_title;
        if ($this->action !== 'removed' && $this->action !== 'deleted') {
            switch ($this->model) :
                case "document": 
                $model = Document::find($this->model_id); 
                $title = $model?->getTitle('en') ?? '';
                break;
                case "category": 
                $model = Category::find($this->model_id); 
                $title = $model?->getTitle('en') ?? '';
                break;  
                case "function": 
                $model = FunctionZ::find($this->model_id); 
                $title = $model?->getTitle('en') ?? '';
                break;    
                case "user": 
                $model = User::find($this->model_id); 
                $title = $model?->name;
                break;    
            endswitch;
        }
        $userName = $user->name;
        return "$role $userName " . $this->action . " the " . $this->model . " " . $title;
    }
}