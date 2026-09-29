<?php

/*
|--------------------------------------------------------------------------
| Demo Data
|--------------------------------------------------------------------------
|
| Settings for synthetic demo data seeded in local development only
| (see Database\Seeders\DemoAccountsSeeder). Never use in production.
|
*/

return [

    'account_password' => env('DEMO_ACCOUNT_PASSWORD') ?: 'password',

];
