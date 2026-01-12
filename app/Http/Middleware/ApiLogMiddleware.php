<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ApiLogMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $start = microtime(true);

        $response = $next($request);

        $time = round((microtime(true) - $start) * 1000, 2); // ms

        Log::channel('api')->info('API Request', [
            'user_id'   => auth()->id(),
            'method'    => $request->method(),
            'url'       => $request->fullUrl(),
            'body'      => $request->all(),
            'response'  => $response->getStatusCode(),
            'time_ms'   => $time
        ]);

        return $response;
    }
}
