<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AppSettings;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class SettingsController extends Controller
{
    public function __construct(protected AppSettings $settings) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->settings->all() + [
            'logo_url' => $this->settings->logoUrl(),
            'logo_is_default' => $this->settings->get('logo_path') === '',
            'mail_configured' => ! in_array(config('mail.default'), ['log', 'array', null], true),
            'public_api_url' => url('/api/public/tyres/availability'),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_name' => ['sometimes', 'string', 'max:150'],
            'business_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'whatsapp_number' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ]+$/'],
            'business_email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'business_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'website_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'brand_color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'currency' => ['sometimes', Rule::in(['ZAR'])],
            'timezone' => ['sometimes', 'timezone'],
            'vat_registered' => ['sometimes', 'boolean'],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'vat_rate' => ['sometimes', 'numeric', 'between:0,100', 'decimal:0,2'],
            'prices_include_vat' => ['sometimes', 'boolean'],
            'reservation_hours' => ['sometimes', 'integer', 'between:1,720'],
            'quotation_valid_days' => ['sometimes', 'integer', 'between:1,90'],
            'default_min_stock' => ['sometimes', 'integer', 'between:0,1000'],
            'used_tyre_grades' => ['sometimes', 'array', 'min:1', 'max:10'],
            'used_tyre_grades.*' => ['string', 'max:20', 'distinct'],
            'slow_moving_days' => ['sometimes', 'integer', 'between:7,3650'],
            'stocktake_lock_mode' => ['sometimes', Rule::in(['block', 'track'])],
            'sku_prefix' => ['sometimes', 'string', 'max:10', 'alpha_num'],
            'notify_email_enabled' => ['sometimes', 'boolean'],
            'notify_emails' => ['sometimes', 'nullable', 'string', 'max:500'],
            'notify_low_stock' => ['sometimes', 'boolean'],
            'notify_reservation_expiry_hours' => ['sometimes', 'integer', 'between:1,72'],
            'public_api_enabled' => ['sometimes', 'boolean'],
            'public_api_visibility' => ['sometimes', Rule::in(['status', 'quantity', 'hidden'])],
            'public_api_show_prices' => ['sometimes', 'boolean'],
            'public_api_allowed_origins' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        if (! empty($data['notify_emails'])) {
            foreach (array_filter(array_map('trim', explode(',', $data['notify_emails']))) as $email) {
                abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL), 422, "Invalid notification e-mail address: {$email}");
            }
        }
        $old = array_intersect_key($this->settings->all(), $data);
        $this->settings->set($data);
        $changed = array_filter($data, fn ($v, $k) => ($old[$k] ?? null) != $v, ARRAY_FILTER_USE_BOTH);
        if ($changed) {
            Audit::log('settings.updated', 'Settings', 'Business settings changed: '.implode(', ', array_keys($changed)), array_intersect_key($old, $changed), $changed);
        }

        return $this->show();
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate(['logo' => ['required', File::image()->types(['png', 'jpg', 'jpeg', 'webp'])->max(2048)]]);
        $old = $this->settings->get('logo_path');
        $path = $request->file('logo')->storeAs('branding', 'logo-'.Str::random(8).'.'.$request->file('logo')->guessExtension(), 'local');
        $this->settings->set(['logo_path' => $path]);
        if ($old) {
            Storage::disk('local')->delete($old);
        }
        Audit::log('settings.logo_changed', 'Settings', 'Company logo updated');

        return $this->show();
    }

    public function removeLogo(): JsonResponse
    {
        if ($old = $this->settings->get('logo_path')) {
            Storage::disk('local')->delete($old);
        }
        $this->settings->set(['logo_path' => '']);
        Audit::log('settings.logo_removed', 'Settings', 'Company logo removed');

        return $this->show();
    }
}
