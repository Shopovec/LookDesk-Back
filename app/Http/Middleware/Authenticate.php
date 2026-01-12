<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
    // Для API не делаем redirect на login
        if ($request->expectsJson()) {
            return null;
        }

    // Если у тебя нет веб-части — просто возвращаем null
        return null;
    }
}
