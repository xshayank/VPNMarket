<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsReseller
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check()) {
            return redirect()->route('login');
        }

        if (!auth()->user()->isReseller()) {
            abort(403, 'Access denied. Only resellers can access this area.');
        }

        $reseller = auth()->user()->reseller;
        
        if ($reseller->isSuspended()) {
            abort(403, 'Your reseller account has been suspended. Please contact support.');
        }

        return $next($request);
    }
}
