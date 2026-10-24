<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureGatewayPaymentsEnabled
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('payments.azampay.enabled', false), 422, 'Online gateway payments are currently unavailable. Pay the business directly.');

        return $next($request);
    }
}
