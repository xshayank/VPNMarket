<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsReseller
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || (!$user->relationLoaded('reseller') && !$user->reseller)) {
            abort(403);
        }

        $reseller = $user->reseller;

        if (!$reseller || $reseller->status !== 'active') {
            abort(403);
        }

        return $next($request);
    }
}
