<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveCustomerAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->hasRole('customer') && ($user->customer_status ?? 'active') !== 'active') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'This customer account is currently inactive. Contact MINEX for assistance.']);
        }

        return $next($request);
    }
}
