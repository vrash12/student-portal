<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes that change settings shared by every campus (academic years,
 * training phases, subjects, passing grades, performance areas, fitness
 * events, merit/demerit types, medical fields, expense definitions), the
 * campuses themselves, and backups (the whole system). Only accounts that
 * are not limited to a campus may use them, whatever their permissions
 * (owner decision 2026-10-03). The route's permission is checked as usual.
 */
class EnsureInstitutionWide
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->campusScope()->isInstitutionWide()) {
            abort(403);
        }

        return $next($request);
    }
}
