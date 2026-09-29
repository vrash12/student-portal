<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sends a signed-in user to the area their permissions allow.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasPermission(Permission::AccessStaffArea)) {
            return redirect()->route('dashboard');
        }

        if ($user->hasPermission(Permission::AccessExamPortal)) {
            return redirect()->route('portal.home');
        }

        abort(403);
    }
}
