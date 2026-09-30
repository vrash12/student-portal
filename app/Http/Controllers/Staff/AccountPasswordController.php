<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Services\UserAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountPasswordController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('staff/account/password', ['changeRequired' => $request->user()->password_change_required]);
    }

    public function update(UpdatePasswordRequest $request, UserAccountService $accounts): RedirectResponse
    {
        $accounts->changeOwnPassword(
            $request->user(),
            $request->string('password')->value(),
            $request->session()->getId(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Password updated. Other sessions have been signed out.']);

        return redirect()->route('account.password.edit');
    }
}
