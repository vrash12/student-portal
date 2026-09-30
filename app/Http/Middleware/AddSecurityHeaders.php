<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers for every web response.
 *
 * - Signed-in responses are never stored by the browser (grades, rosters and
 *   examinations must not stay in a shared computer's cache or back button).
 * - A Content-Security-Policy allows only this server's own scripts, with a
 *   per-request nonce for the scripts Vite adds to the page. Responses that
 *   set their own policy (media files, sandboxed) keep it.
 * - HSTS is sent on HTTPS requests only, so plain-HTTP setups keep working.
 */
class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        if ($request->user() !== null) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce));
        }
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        return $response;
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        // During frontend development (npm run dev) assets come from the Vite server.
        $dev = Vite::isRunningHot() ? trim((string) file_get_contents(Vite::hotFile())) : '';
        $devSocket = $dev === '' ? '' : ' '.preg_replace('/^http/', 'ws', $dev);
        $dev = $dev === '' ? '' : ' '.$dev;

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'{$dev}",
            // Inline style attributes are allowed (layout only; no script can run from CSS).
            "style-src 'self' 'unsafe-inline'{$dev}",
            "img-src 'self' data: blob:{$dev}",
            "media-src 'self' blob:",
            "font-src 'self'{$dev}",
            "connect-src 'self'{$dev}{$devSocket}",
            "worker-src 'self'",
            "manifest-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
