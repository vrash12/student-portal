<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ConfirmPasswordRequest;
use App\Http\Requests\Account\ConfirmTwoFactorRequest;
use App\Services\Auth\TwoFactorService;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A staff member's own two-step sign-in: set up with an authenticator app,
 * new recovery codes, turn off (unless it is required for them). Every
 * change asks for the password first.
 */
class AccountTwoFactorController extends Controller
{
    /** The recovery codes shown once, on the page right after they are made. */
    private const NEW_CODES_KEY = 'two_factor.new_recovery_codes';

    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $pendingSecret = $this->twoFactor->pendingSecret($user);
        $newCodes = $request->session()->get(self::NEW_CODES_KEY);

        $setup = null;
        if ($pendingSecret !== null) {
            $uri = $this->twoFactor->provisioningUri($user, $pendingSecret);
            $setup = [
                'qrCode' => 'data:image/svg+xml;base64,'.base64_encode(Totp::qrSvg($uri)),
                'secret' => Totp::formatSecret($pendingSecret),
                'issuer' => (string) config('two_factor.issuer'),
                'account' => $user->username,
            ];
        }

        return Inertia::render('staff/account/two-factor', [
            'enabled' => $user->hasTwoFactorEnabled(),
            'enabledAt' => $user->two_factor_confirmed_at?->toIso8601String(),
            'required' => $this->twoFactor->isRequired($user),
            'recoveryCodesLeft' => $this->twoFactor->recoveryCodesLeft($user),
            'setup' => $setup,
            'newRecoveryCodes' => is_array($newCodes) ? array_values($newCodes) : null,
        ]);
    }

    /** Starts setup: a new secret shown as a QR code until it is confirmed. */
    public function store(ConfirmPasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->hasTwoFactorEnabled()) {
            return $this->back('info', 'Two-step sign-in is already on.');
        }

        $this->twoFactor->beginSetup($user);

        return redirect()->route('account.two-factor.show');
    }

    public function confirm(ConfirmTwoFactorRequest $request): RedirectResponse
    {
        $codes = $this->twoFactor->confirmSetup($request->user(), $request->string('code')->value());
        if ($codes === null) {
            throw ValidationException::withMessages([
                'code' => 'The code does not match. Check that the time on your phone is right and enter the code your app shows now.',
            ]);
        }

        $request->session()->flash(self::NEW_CODES_KEY, $codes);

        return $this->back('success', 'Two-step sign-in is on. Keep your recovery codes in a safe place.');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->twoFactor->cancelSetup($request->user());

        return redirect()->route('account.two-factor.show');
    }

    public function regenerateRecoveryCodes(ConfirmPasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user->hasTwoFactorEnabled()) {
            return $this->back('info', 'Turn on two-step sign-in first.');
        }

        $request->session()->flash(self::NEW_CODES_KEY, $this->twoFactor->regenerateRecoveryCodes($user));

        return $this->back('success', 'New recovery codes made. The old ones no longer work.');
    }

    public function destroy(ConfirmPasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        if ($this->twoFactor->isRequired($user)) {
            throw ValidationException::withMessages([
                'current_password' => 'Two-step sign-in is required for your account and cannot be turned off.',
            ]);
        }

        if ($user->hasTwoFactorEnabled()) {
            $this->twoFactor->disable($user);
        }

        return $this->back('success', 'Two-step sign-in is off.');
    }

    private function back(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return redirect()->route('account.two-factor.show');
    }
}
