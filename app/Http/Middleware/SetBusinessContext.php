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

        if ($user && $user->type === 'agent') {
            abort_unless($user->is_active, 403, 'Your onboarding agent account is disabled.');
            app(\App\Services\OnboardingAgentService::class)->authorizeRequest($request);
            $response = $next($request);
            if (! $request->isMethod('GET') && $response->getStatusCode() < 400 && ! $request->is('v1/logout', 'v1/change-password')) {
                app(\App\Services\OnboardingAgentService::class)->audit('agent.request', $user->id, $request->attributes->get('onboarding_vendor_id'), ['method' => $request->method(), 'path' => $request->path()]);
            }

            return $response;
        }
        // check if user has no business then a user will be a owner which owns different vendors

        // if user has no business, skip business context
        if ($user && $user->businessUser()->count() === 0) {
            return $next($request);
        }

        // Must be authenticated
        abort_unless($user, Response::HTTP_UNAUTHORIZED, 'unauthenticated');

        // Resolve business from business_users
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
