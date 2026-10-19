<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the app is served over HTTPS (APP_URL=https://…), plain http requests are redirected to it.
 * The redirect is temporary (302) on purpose: browsers cache 301s forever, which makes switching back to http painful.
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.force_https') && ! $request->isSecure()) {
            return redirect()->secure($request->getRequestUri(), 302);
        }

        return $next($request);
    }
}
