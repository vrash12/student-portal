<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers for every web response. HSTS is left to the
 * web server because it depends on how HTTPS is deployed internally.
 */
class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->is('portal', 'portal/*', 'candidates', 'candidates/*', 'examinations', 'examinations/*', 'examination-attempts/*', 'question-bank', 'question-bank/*', 'reports', 'audit-history', 'dashboard')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        return $response;
    }
}
