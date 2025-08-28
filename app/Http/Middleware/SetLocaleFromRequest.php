<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class SetLocaleFromRequest
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $supported = ['en', 'de'];
        $preferred = $request->getPreferredLanguage($supported) ?? 'en';
        App::setLocale($preferred);
        return $next($request);
    }
}
