<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', 'string', 'max:200'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $user = User::query()->where('email', strtolower($credentials['email']))->first();
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            Audit::log('auth.login_failed', 'User', 'Failed sign-in for '.strtolower($credentials['email']), null, null, $user?->id);
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        if (! $user->is_active) {
            Audit::log('auth.login_blocked', $user, 'Sign-in attempt on a deactivated account', null, null, $user->id);
            throw ValidationException::withMessages(['email' => 'This account has been deactivated. Contact the owner.']);
        }

        Auth::guard('web')->login($user, (bool) ($credentials['remember'] ?? false));
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->saveQuietly();
        Audit::log('auth.login', $user, "{$user->name} signed in", null, null, $user->id);

        return response()->json(['user' => $this->userPayload($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if ($user) {
            Audit::log('auth.logout', $user, "{$user->name} signed out", null, null, $user->id);
        }

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', PasswordRule::defaults()],
        ]);
        $user->forceFill(['password' => Hash::make($data['password']), 'must_change_password' => false, 'remember_token' => Str::random(60)])->save();
        // Sign out other devices.
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        Audit::log('auth.password_changed', $user, "{$user->name} changed their password");

        return response()->json(['message' => 'Password changed.', 'user' => $this->userPayload($user)]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);
        $original = $user->getOriginal();
        $user->fill($data)->save();
        Audit::changes('user.profile_updated', $user, $original, "{$user->name} updated their profile");

        return response()->json(['user' => $this->userPayload($user)]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        $user = User::query()->where('email', strtolower($request->string('email')))->where('is_active', true)->first();
        if ($user) {
            try {
                Password::broker()->sendResetLink(['email' => $user->email]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Same response whether or not the account exists (no account enumeration).
        return response()->json(['message' => 'If an active account exists for that address, a reset link has been sent. If e-mail is not configured, ask the owner to reset your password.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);
        $status = Password::broker()->reset(
            ['email' => strtolower($request->string('email')), 'password' => $request->input('password'), 'password_confirmation' => $request->input('password_confirmation'), 'token' => $request->input('token')],
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password), 'must_change_password' => false, 'remember_token' => Str::random(60)])->save();
                Audit::log('auth.password_reset', $user, "{$user->name} reset their password via e-mail link", null, null, $user->id);
                event(new PasswordReset($user));
            },
        );
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['message' => 'Your password has been reset. You can now sign in.']);
    }

    public static function userPayload(User $user): array
    {
        $user->loadMissing('role');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => ['id' => $user->role?->id, 'name' => $user->role?->name, 'label' => $user->role?->label],
            'permissions' => $user->permissionNames(),
            'must_change_password' => $user->must_change_password,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];
    }
}
