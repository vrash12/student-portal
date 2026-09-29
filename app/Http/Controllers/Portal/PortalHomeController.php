<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Candidate examination portal home. Available examinations are added in
 * Milestone 9; until then the page shows its empty state.
 */
class PortalHomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('portal/home');
    }
}
