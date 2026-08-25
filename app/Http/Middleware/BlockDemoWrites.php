<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockDemoWrites
{
    /**
     * Handle an incoming request.
     *
     * Demo account is read-only: any write attempt (POST/PUT/PATCH/DELETE)
     * from the is_demo user is rejected before it reaches the controller.
     * GET/HEAD always pass through, so browsing the dashboard works
     * normally for the demo account.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        if ($request->user() && $request->user()->is_demo) {
            abort(403, "This is a read-only demo account — changes aren't saved here.");
        }

        return $next($request);
    }
}