<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiToken = $request->header(('x-api-key'));
        if (!$apiToken) {
            $apiToken = $request->route()->parameter('xApiToken');
            if (!$apiToken) {
                return response()->json(["error" => "Please input API token in header"], 403);
            }
        }
        if ($apiToken !== env('API_KEY')) {
            return response()->json(["error" => "API token not match"], 404);
        }
        return $next($request);
    }
}
