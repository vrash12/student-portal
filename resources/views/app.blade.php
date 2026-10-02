<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <meta name="theme-color" content="#1f6b4a">
        <link rel="manifest" href="/portal.webmanifest">
        <link rel="apple-touch-icon" href="/branding/logo-512.png">
        <meta name="apple-mobile-web-app-capable" content="yes">

        <title inertia>{{ config('institution.system_name') }}</title>

        <link rel="icon" href="{{ \App\Support\PublicAsset::url(config('institution.favicon_url')) }}">

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
