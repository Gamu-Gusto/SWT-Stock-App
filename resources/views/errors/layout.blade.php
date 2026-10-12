<!DOCTYPE html>
<html lang="en-ZA">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · Super Wheels and Tyres Stock</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f4f5f7; color: #16181d; margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; box-sizing: border-box; }
        .card { max-width: 420px; text-align: center; }
        .code { font-size: 64px; font-weight: 800; color: #7d5d1f; letter-spacing: -2px; }
        .logo { width: 96px; height: 96px; border-radius: 50%; }
        h1 { font-size: 20px; margin: 4px 0 8px; }
        p { color: #5b616e; line-height: 1.5; }
        a { display: inline-block; margin-top: 12px; color: #fff; background: #16181d; padding: 10px 18px; border-radius: 8px; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
<div class="card">
    <img class="logo" src="/images/swt-logo-160.webp" alt="Super Wheels and Tyres" width="96" height="96">
    <div class="code">@yield('code')</div>
    <h1>@yield('title')</h1>
    <p>@yield('message')</p>
    <a href="/">Back to dashboard</a>
</div>
</body>
</html>
