<?php

namespace App\Http\Middleware;

use App\Models\BroadcastPartner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBroadcastPartner
{
    public function handle(Request $request, Closure $next): Response
    {
        $partner = BroadcastPartner::findByKey($request->header('X-API-Key'));

        if (!$partner) {
            return response()->json(['message' => 'Invalid or missing API key.'], 401);
        }

        $request->attributes->set('broadcast_partner', $partner);

        return $next($request);
    }
}
