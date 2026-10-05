<?php

/*
|--------------------------------------------------------------------------
| Two-step sign-in (owner request, 2026-10-05)
|--------------------------------------------------------------------------
|
| Staff accounts may add a second step to signing in: a 6-digit code from
| an authenticator app (Microsoft Authenticator, Google Authenticator,
| Aegis, FreeOTP, 2FAS) or a hardware TOTP token. The codes follow TOTP
| (RFC 6238) and need no internet, SMS or email: only the server's clock
| must be right, so point the server at the institution's time server.
|
| required_for decides who must turn it on before using the staff area:
|   administrators  accounts that manage user accounts (users.manage)
|   staff           every staff account (administrators and instructors)
|   none            nobody; staff may still turn it on themselves
|
| Candidates never use it (shared exam tablets, no phones in the room).
|
*/

return [
    'required_for' => env('TWO_FACTOR_REQUIRED_FOR', 'administrators'),

    // The name the authenticator app shows above the code.
    'issuer' => env('TWO_FACTOR_ISSUER') ?: env('APP_NAME', 'Academic Monitoring System'),

    // Codes of the previous and next 30 seconds are also accepted, for
    // small differences between the phone's clock and the server's.
    'window' => 1,

    // Minutes between the correct password and the code before the
    // password must be entered again.
    'challenge_minutes' => 5,

    'recovery_codes' => 8,
];
