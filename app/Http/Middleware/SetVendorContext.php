<?php

namespace App\Http\Middleware;

use App\Enums\User\UserType;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SetVendorContext
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $storeId = $request->header('X-Store-ID');

        switch ($user->type) {
            case UserType::OWNER;
            $request->header('X-Vendor-Id');
        }




        if ($storeId) {
            abort_unless(
                $user->storeUser()
                    ->where('business_id', business_id())
                    ->where('store_id', $storeId)
                    ->where('is_active', true)
                    ->exists(),
                Response::HTTP_FORBIDDEN,
                'unauthorized store access'
            );
        } else {
            // Fallback to first active store
            $storeId = $user->storeUser()
                ->where('business_id', business_id())
                ->where('is_active', true)
                ->orderBy('id')
                ->value('store_id');

            abort_unless(
                $storeId,
                Response::HTTP_FORBIDDEN,
                'no active store found'
            );
        }


        app()->instance('store_id', (int) $storeId);

        return $next($request);
    }
}
