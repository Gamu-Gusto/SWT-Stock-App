@php($settings = app(\App\Support\AppSettings::class))
<!DOCTYPE html>
<html lang="en-ZA">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#1d2128">
    <title>{{ $settings->get('business_name') }} · Stock</title>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="/icons/icon-192.png">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <style>:root { --brand: {{ $settings->brandColor() }}; --brand-ink: {{ $settings->brandInk() }}; }</style>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/main.ts'])
</head>
<body class="bg-surface text-ink antialiased">
    <div id="app"></div>
    <noscript>This stock management system needs JavaScript enabled.</noscript>
</body>
</html>
