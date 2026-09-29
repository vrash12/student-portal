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

    // Public URL of the browser-tab icon. Defaults to the neutral placeholder.
    'favicon_url' => env('ORGANIZATION_FAVICON_URL') ?: '/favicon.svg',

    // Timezone used when presenting dates to users. Data is stored in UTC.
    'timezone' => env('INSTITUTION_TIMEZONE', 'UTC'),

];
