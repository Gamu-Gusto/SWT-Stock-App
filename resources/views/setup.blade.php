<!DOCTYPE html>
<html lang="en-ZA">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>First-time setup · Super Wheels and Tyres Stock</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f4f5f7; color: #16181d; margin: 0; padding: 32px 16px; }
        .card { max-width: 460px; margin: 0 auto; background: #fff; border: 1px solid #e3e5e9; border-radius: 12px; padding: 28px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        p { color: #5b616e; font-size: 14px; line-height: 1.5; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 14px 0 6px; }
        input { width: 100%; box-sizing: border-box; padding: 11px 12px; font-size: 15px; border: 1px solid #c9cdd4; border-radius: 8px; }
        button { margin-top: 20px; width: 100%; padding: 12px; font-size: 15px; font-weight: 600; background: #d19a3a; color: #1d2128; border: 0; border-radius: 8px; cursor: pointer; }
        .error { color: #b4171f; font-size: 13px; margin-top: 4px; }
        .note { background: #fff7e6; border: 1px solid #f3d595; padding: 10px 12px; border-radius: 8px; font-size: 13px; }
    </style>
</head>
<body>
<div class="card">
    <img src="/images/swt-logo-160.webp" alt="Super Wheels and Tyres" width="80" height="80" style="display:block;margin:0 auto 16px;border-radius:50%;">
    <h1>First-time setup</h1>
    <p>Create the owner (super admin) account for Super Wheels and Tyres Stock. This page works only once and only while <code>SETUP_TOKEN</code> is set in the server's <code>.env</code> file.</p>
    @unless($migrated)
        <p class="note">The database tables will be created automatically when you submit this form.</p>
    @endunless
    <form method="post" action="/setup" autocomplete="off">
        @csrf
        <label for="setup_token">Setup token (from .env)</label>
        <input id="setup_token" name="setup_token" type="password" required>
        @error('setup_token')<div class="error">{{ $message }}</div>@enderror

        <label for="name">Owner full name</label>
        <input id="name" name="name" value="{{ old('name') }}" required maxlength="120">
        @error('name')<div class="error">{{ $message }}</div>@enderror

        <label for="email">Owner e-mail address</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required>
        @error('email')<div class="error">{{ $message }}</div>@enderror

        <label for="password">Password (min. 10 characters, letters and numbers)</label>
        <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password">
        @error('password')<div class="error">{{ $message }}</div>@enderror

        <label for="password_confirmation">Confirm password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">

        <button type="submit">Create owner account</button>
    </form>
    <p style="margin-top:18px">After setup, remove <code>SETUP_TOKEN</code> from <code>.env</code>. The inventory starts empty: add or import your real opening stock.</p>
</div>
</body>
</html>
