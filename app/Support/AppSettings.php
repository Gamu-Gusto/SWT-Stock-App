<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Typed business settings stored in the `settings` table, with safe defaults.
 */
class AppSettings
{
    public const CACHE_KEY = 'app_settings.v1';

    /** @var array<string, array{type:string, default:mixed}> */
    public const DEFINITIONS = [
        'business_name' => ['type' => 'string', 'default' => 'Super Wheels and Tyres'],
        'business_phone' => ['type' => 'string', 'default' => '+27 76 454 4272'],
        'whatsapp_number' => ['type' => 'string', 'default' => '+27764544272'],
        'business_email' => ['type' => 'string', 'default' => ''],
        'business_address' => ['type' => 'string', 'default' => "481 WF Nkomo Street\nPretoria West\nPretoria, 0184"],
        'website_url' => ['type' => 'string', 'default' => 'https://superwheeltyres.co.za/'],
        // Empty = use the built-in Super Wheels and Tyres logo (public/images/swt-logo.*).
        'logo_path' => ['type' => 'string', 'default' => ''],
        // Gold from the Super Wheels and Tyres logo.
        'brand_color' => ['type' => 'string', 'default' => '#d19a3a'],
        'currency' => ['type' => 'string', 'default' => 'ZAR'],
        'timezone' => ['type' => 'string', 'default' => 'Africa/Johannesburg'],

        // Tax: never assume VAT registration.
        'vat_registered' => ['type' => 'bool', 'default' => false],
        'vat_number' => ['type' => 'string', 'default' => ''],
        'vat_rate' => ['type' => 'decimal', 'default' => '15.00'],
        'prices_include_vat' => ['type' => 'bool', 'default' => true],

        'reservation_hours' => ['type' => 'int', 'default' => 24],
        'quotation_valid_days' => ['type' => 'int', 'default' => 7],
        'default_min_stock' => ['type' => 'int', 'default' => 4],
        'used_tyre_grades' => ['type' => 'array', 'default' => ['A', 'B', 'C']],
        'slow_moving_days' => ['type' => 'int', 'default' => 90],
        // block = reject stock transactions on products/locations in an in-progress count; track = allow and record.
        'stocktake_lock_mode' => ['type' => 'string', 'default' => 'block'],
        'sku_prefix' => ['type' => 'string', 'default' => 'SWT'],

        'notify_email_enabled' => ['type' => 'bool', 'default' => false],
        'notify_emails' => ['type' => 'string', 'default' => ''],
        'notify_low_stock' => ['type' => 'bool', 'default' => true],
        'notify_reservation_expiry_hours' => ['type' => 'int', 'default' => 4],

        // Public availability API (for the existing public website). Disabled until explicitly enabled.
        'public_api_enabled' => ['type' => 'bool', 'default' => false],
        'public_api_visibility' => ['type' => 'string', 'default' => 'status'], // status | quantity | hidden
        'public_api_show_prices' => ['type' => 'bool', 'default' => false],
        'public_api_allowed_origins' => ['type' => 'string', 'default' => 'https://superwheeltyres.co.za'],
    ];

    /** Settings readable by every authenticated user (no sensitive values). */
    public const PUBLIC_KEYS = [
        'business_name', 'business_phone', 'whatsapp_number', 'business_email', 'business_address', 'website_url',
        'logo_path', 'brand_color', 'currency', 'timezone', 'vat_registered', 'vat_number', 'vat_rate',
        'prices_include_vat', 'reservation_hours', 'quotation_valid_days', 'default_min_stock', 'used_tyre_grades',
        'stocktake_lock_mode', 'slow_moving_days',
    ];

    /** @var array<string, mixed>|null */
    protected ?array $loaded = null;

    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $stored = [];
        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()->pluck('value', 'key')->all());
        } catch (\Throwable) {
            // During installation the settings table may not exist yet.
            if (Schema::hasTable('settings')) {
                $stored = Setting::query()->pluck('value', 'key')->all();
            }
        }

        $values = [];
        foreach (self::DEFINITIONS as $key => $def) {
            $values[$key] = array_key_exists($key, $stored) ? $this->cast($def['type'], $stored[$key]) : $def['default'];
        }

        return $this->loaded = $values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /** @param array<string, mixed> $values */
    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! isset(self::DEFINITIONS[$key])) {
                continue;
            }
            $type = self::DEFINITIONS[$key]['type'];
            $stored = match ($type) {
                'bool' => $value ? '1' : '0',
                'array' => json_encode(array_values((array) $value)),
                'decimal' => Money::normalize((string) $value),
                default => $value === null ? '' : (string) $value,
            };
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $stored]);
        }
        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->loaded = null;
    }

    public function publicValues(): array
    {
        return array_intersect_key($this->all(), array_flip(self::PUBLIC_KEYS)) + [
            'logo_url' => $this->logoUrl(),
            'logo_is_default' => $this->get('logo_path') === '',
            'brand_ink' => $this->brandInk(),
        ];
    }

    public const DEFAULT_LOGO = 'images/swt-logo.webp';

    public const DEFAULT_LOGO_PNG = 'images/swt-logo.png';

    /** The uploaded logo, or the built-in Super Wheels and Tyres logo. */
    public function logoUrl(): string
    {
        return $this->get('logo_path') ? url('/branding/logo') : asset(self::DEFAULT_LOGO);
    }

    /** Validated brand colour (falls back to the logo gold). */
    public function brandColor(): string
    {
        $color = (string) $this->get('brand_color');

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : self::DEFINITIONS['brand_color']['default'];
    }

    /**
     * Text colour to put ON the brand colour (buttons, badges): dark ink for light brands such as
     * gold, white for dark brands – whichever has the higher WCAG contrast.
     */
    public function brandInk(): string
    {
        [$r, $g, $b] = array_map(fn ($h) => hexdec($h) / 255, str_split(substr($this->brandColor(), 1), 2));
        $lin = fn ($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        $l = 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
        $ink = 0.016; // relative luminance of #1d2128
        $contrastWhite = 1.05 / ($l + 0.05);
        $contrastInk = ($l + 0.05) / ($ink + 0.05);

        return $contrastInk >= $contrastWhite ? '#1d2128' : '#ffffff';
    }

    protected function cast(string $type, mixed $value): mixed
    {
        return match ($type) {
            'bool' => in_array($value, ['1', 1, true, 'true'], true),
            'int' => (int) $value,
            'array' => is_array($value) ? $value : (json_decode((string) $value, true) ?: []),
            'decimal' => Money::normalize((string) $value),
            default => (string) $value,
        };
    }
}
