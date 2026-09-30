<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\CandidateHomeService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PortalHomeController extends Controller
{
    public function __invoke(Request $request, CandidateHomeService $service): Response
    {
        $candidate = $request->user()->candidate()->firstOrFail();

        return Inertia::render('portal/home', $service->overview($candidate));
    }
}
