<?php
namespace App\Http\Middleware;

use Closure;

class UpdateUserLastSeen
{
    public function handle($request, Closure $next)
    {

        if (auth()->check()) {
            auth()->user()->update([
                'last_seen_at' => now()
            ]);
        }

        return $next($request);
    }
}