<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('app.supported_locales', ['sw', 'en', 'fr', 'zh']);

        $locale = $this->resolveLocale($request, $supported) ?? config('app.locale');

        app()->setLocale($locale);

        return $next($request);
    }

    private function resolveLocale(Request $request, array $supported): ?string
    {
        $param = $request->query('lang');
        if (is_string($param)) {
            $param = strtolower($param);
            $param = $this->normalize($param);
            if (in_array($param, $supported, true)) {
                return $param;
            }
        }

        // Use Accept-Language negotiation against supported locales
        $preferred = $request->getPreferredLanguage($supported);
        if (is_string($preferred)) {
            $preferred = strtolower($this->normalize($preferred));
            if (in_array($preferred, $supported, true)) {
                return $preferred;
            }
        }

        return null;
    }

    private function normalize(string $locale): string
    {
        // Map common codes to our supported set
        $map = [
            'sw-tz' => 'sw',
            'sw_ke' => 'sw',
            'sw_tz' => 'sw',
            'sw' => 'sw',
            'en-us' => 'en',
            'en_gb' => 'en',
            'en' => 'en',
            'fr-fr' => 'fr',
            'fr_ca' => 'fr',
            'fr' => 'fr',
            'zh-cn' => 'zh',
            'zh_tw' => 'zh',
            'zh-hans' => 'zh',
            'zh-hant' => 'zh',
            'zh' => 'zh',
        ];

        $key = str_replace(['_', ' '], ['-', ''], strtolower($locale));
        return $map[$key] ?? explode('-', $key)[0];
    }
}
