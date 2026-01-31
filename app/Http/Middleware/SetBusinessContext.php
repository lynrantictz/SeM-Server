<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetBusinessContext
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        //Must be authenticated
        abort_unless($user, Response::HTTP_UNAUTHORIZED, 'unauthenticated');

        //Resolve business from business_users
        $businessId = $user->businessUser()
            ->where('is_active', true)
            ->value('business_id');

        // User must belong to a business
        abort_unless($businessId, Response::HTTP_FORBIDDEN, 'business context not found');

        // Store business_id for this request
        app()->instance('business_id', (int) $businessId);
        return $next($request);
    }
}
