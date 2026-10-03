<?php

namespace App\Http\Middleware;

use App\Support\AuditCampus;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff routes: every record named in the URL (a class, candidate, class
 * subject, assignment, staff account, grade record, test, session...) must
 * belong to a campus the user may see (CampusScope), or the page does not
 * exist for them (404, the same answer as for a missing record). This is the
 * one place where a campus-limited account is kept away from another
 * campus's records by URL; policies and lists apply the same CampusScope.
 *
 * Shared records (subjects, academic years, questions, settings) are not
 * checked here: who may change them is decided by their permissions.
 */
class EnsureRecordsInCampus
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = $request->route();

        if ($user === null || $route === null) {
            return $next($request);
        }

        $scope = $user->campusScope();
        if ($scope->coversEveryCampus()) {
            return $next($request);
        }

        foreach ($route->parameters() as $parameter) {
            if ($parameter instanceof Model && AuditCampus::isCampusRecord($parameter) && ! $scope->allows(AuditCampus::of($parameter))) {
                abort(404);
            }
        }

        return $next($request);
    }
}
