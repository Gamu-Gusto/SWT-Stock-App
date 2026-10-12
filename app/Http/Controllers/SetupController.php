<?php

namespace App\Http\Controllers;

use App\Services\Installer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * One-time browser installer for hosting without SSH access.
 *
 * Works only when (1) SETUP_TOKEN is set in .env and (2) no owner account exists yet. After the
 * owner is created the page permanently refuses access. Remove SETUP_TOKEN from .env afterwards.
 */
class SetupController extends Controller
{
    public function __construct(protected Installer $installer) {}

    public function show(): View
    {
        abort_unless($this->available(), 404);

        return view('setup', ['migrated' => Schema::hasTable('users')]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->available(), 404);

        // File-based limit: 5 attempts per 10 minutes per IP (the database may not exist yet).
        $attemptsKey = 'setup-attempts:'.sha1((string) $request->ip());
        $attempts = (int) Cache::store('file')->get($attemptsKey, 0);
        abort_if($attempts >= 5, 429, 'Too many setup attempts. Wait 10 minutes and try again.');
        Cache::store('file')->put($attemptsKey, $attempts + 1, now()->addMinutes(10));

        $data = $request->validate([
            'setup_token' => ['required', 'string'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);
        if (! hash_equals((string) config('app.setup_token'), (string) $data['setup_token'])) {
            Log::warning('Setup attempted with an invalid token from '.$request->ip());

            return back()->withErrors(['setup_token' => 'The setup token is incorrect.'])->withInput($request->except(['password', 'password_confirmation', 'setup_token']));
        }

        Artisan::call('migrate', ['--force' => true]);
        $this->installer->createOwner($data['name'], $data['email'], $data['password']);

        return redirect('/login')->with('status', 'Setup complete. Sign in with the owner account, then remove SETUP_TOKEN from .env.');
    }

    protected function available(): bool
    {
        if (! config('app.setup_token')) {
            return false;
        }
        try {
            return ! Schema::hasTable('users') || ! $this->installer->isInstalled();
        } catch (\Throwable) {
            return true;
        }
    }
}
