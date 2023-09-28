<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

class AdminPriv
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if(!$user->admin()->first()){
            return response()->json(["error" => "You are not an admin of this system"], 403);
        }
        if($request->route()->parameter('orgId')){
            $org = Organization::where('id', $request->route()->parameter('orgId'))->first();
            if(!$org){
                return response()->json(["error" => "Organization data not found"], 404);
            }
            $req->org = $org;
        }
        if($request->route()->parameter('eventId') || $request->event_id){
            $eventId = $request->event_id ? $request->event_id : $request->route()->parameter('eventId');
            $event = Event::where('id', $eventId)->first();
            if(!$event){
                return response()->json(["error" => "Event data not found"], 404);
            }
            $req->event = $event;
        }
        return $next($request);
    }
}
