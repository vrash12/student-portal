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

    // Owner-provided login photograph, served locally for intranet use.
    'login_image_url' => env('LOGIN_IMAGE_URL', '/branding/login-training.jpg'),

    // Small "Powered by" credit under the sign-in form (placeholder supplied
    // by the owner). Leave POWERED_BY_NAME empty to hide it.
    'powered_by_name' => env('POWERED_BY_NAME', 'ServLife Solutions'),
    'powered_by_logo_url' => env('POWERED_BY_LOGO_URL', '/branding/powered-by-logo.png'),

    // Public URL of the browser-tab icon. Defaults to the neutral placeholder.
    'favicon_url' => env('ORGANIZATION_FAVICON_URL') ?: '/favicon.svg',

    // Timezone used when presenting dates to users. Data is stored in UTC.
    'timezone' => env('INSTITUTION_TIMEZONE', 'UTC'),

];
