<?php

namespace App\Providers;

use App\Models\User;
use App\Models\Role;
use App\Models\Category;
use App\Models\Document;
use App\Policies\UserPolicy;
use App\Policies\RolePolicy;
use App\Policies\CategoryPolicy;
use App\Policies\DocumentPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        User::class     => UserPolicy::class,
        Role::class     => RolePolicy::class,
        Category::class => CategoryPolicy::class,
        Document::class => DocumentPolicy::class,
    ];

    public function boot()
    {
        $this->registerPolicies();
    }
}
