<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWalletAccess
{
    /**
     * Handle an incoming request.
     *
     * For wallet-based resellers who are suspended due to low balance,
     * redirect them to the wallet charge page with a warning message.
     * 
     * This middleware should be applied to reseller routes but NOT to wallet routes.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check()) {
            return redirect()->route('login');
        }

        $user = auth()->user();

        // Only check for resellers
        if (!$user->isReseller()) {
            return $next($request);
        }

        $reseller = $user->reseller;

        // Check if this is a wallet-based reseller who is suspended
        if ($reseller->isWalletBased() && $reseller->isSuspendedWallet()) {
            // Redirect to wallet charge page with warning
            return redirect()
                ->route('wallet.charge.form')
                ->with('warning', 'حساب ریسلر شما به دلیل کمبود موجودی کیف پول معلق شده است. لطفاً کیف پول خود را شارژ کنید.');
        }

        return $next($request);
    }
}
