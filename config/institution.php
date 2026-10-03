<?php

/*
|--------------------------------------------------------------------------
| Institution and Branding
|--------------------------------------------------------------------------
|
| Placeholder values until official branding is approved. Do not hardcode
| organization-specific names, logos, or insignia anywhere else in the
| application; read them from this configuration instead.
|
*/

return [

    'organization_name' => env('ORGANIZATION_NAME', 'Organization Name'),

    'system_name' => env('APP_NAME', 'Academic Monitoring System'),

    'short_name' => env('APP_SHORT_NAME', 'Academic System'),

    // Public URL of the approved logo. Null renders a neutral placeholder.
    'logo_url' => env('ORGANIZATION_LOGO_URL'),

    // Locally served sign-in photograph, used when login_compact_image_url is
    // empty. An explicit empty value for both keeps the plain navy background.
    'login_image_url' => env('LOGIN_IMAGE_URL', '/branding/login-campus.jpg') ?: null,

    // The sign-in photograph shown at every screen size (full quality; the
    // layout keeps the school's building clear of the card). Empty: the main
    // image setting instead.
    'login_compact_image_url' => env('LOGIN_COMPACT_IMAGE_URL', '/branding/login-background.png') ?: null,

    // Sign-in page texts. Empty values fall back (titles) or are hidden.
    'login' => [
        // Header title and subtitle; default to the organization and short system names.
        'header_title' => env('LOGIN_HEADER_TITLE') ?: null,
        'header_subtitle' => env('LOGIN_HEADER_SUBTITLE') ?: null,
        // Title of the sign-in card; defaults to the system name (APP_NAME).
        'card_title' => env('LOGIN_CARD_TITLE') ?: null,
        // Comma-separated, e.g. "Discipline,Integrity,Valor,Duty".
        'core_values' => array_values(array_filter(array_map('trim', explode(',', (string) env('INSTITUTION_CORE_VALUES', ''))))),
        'motto' => env('LOGIN_MOTTO') ?: null,
        'tagline' => env('LOGIN_TAGLINE', 'Secure platform for candidate records, training monitoring, evaluations, and administrative support.') ?: null,
        // Shown by "Need assistance?"; without it users are told to contact the system administrator.
        'help_desk' => env('HELP_DESK_CONTACT') ?: null,
    ],

    // Small "Powered by" credit under the sign-in form (placeholder supplied
    // by the owner). Leave POWERED_BY_NAME empty to hide it.
    'powered_by_name' => env('POWERED_BY_NAME', 'ServLife Solutions'),
    'powered_by_logo_url' => env('POWERED_BY_LOGO_URL', '/branding/powered-by-logo.png'),

    // Public URL of the browser-tab icon. Defaults to the neutral placeholder.
    'favicon_url' => env('ORGANIZATION_FAVICON_URL') ?: '/favicon.svg',

    // Timezone used when presenting dates to users. Data is stored in UTC.
    'timezone' => env('INSTITUTION_TIMEZONE', 'UTC'),

    'portal' => [
        // Owner decision 2026-10-02: military fitness is for staff only
        // (administrators and instructors). true shows candidates their own
        // fitness tests again (Physical Fitness page and Home tile).
        'show_fitness' => (bool) env('PORTAL_SHOW_FITNESS', false),
    ],

    // ISO 4217 code of the currency used on Statements of Account, e.g. PHP.
    'currency' => env('INSTITUTION_CURRENCY', 'PHP'),

];
