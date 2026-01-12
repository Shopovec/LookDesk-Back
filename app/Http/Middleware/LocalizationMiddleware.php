<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class LocalizationMiddleware
{
    public function handle($request, Closure $next)
    {
        $header = $request->header('Accept-Language');

        if ($header) {
        // Берём только первый язык — до запятой
            $locale = explode(',', $header)[0];

        // Валидные языки, которые реально есть у Carbon / Laravel
            $allowed = ['en', 'ru', 'uk'];

            if (in_array($locale, $allowed)) {
                app()->setLocale($locale);
                \Carbon\Carbon::setLocale($locale);
            }
        }

        return $next($request);
    }
}
