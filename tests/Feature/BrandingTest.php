<?php

namespace Tests\Feature;

use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_built_in_logo_and_gold_brand_are_used_by_default(): void
    {
        $this->getJson('/branding')->assertOk()
            ->assertJsonPath('logo_url', asset('images/swt-logo.webp'))
            ->assertJsonPath('brand_color', '#d19a3a')
            ->assertJsonPath('brand_ink', '#1d2128'); // dark text on gold buttons
        $this->assertFileExists(public_path('images/swt-logo.webp'));
        $this->assertFileExists(public_path('images/swt-logo.png'));
    }

    public function test_text_on_brand_colour_stays_readable_for_dark_brands(): void
    {
        app(AppSettings::class)->set(['brand_color' => '#b4171f']);

        $this->getJson('/branding')->assertJsonPath('brand_ink', '#ffffff');
    }

    public function test_an_uploaded_logo_replaces_the_built_in_one(): void
    {
        app(AppSettings::class)->set(['logo_path' => 'branding/logo-test.png']);

        $this->getJson('/branding')->assertJsonPath('logo_url', url('/branding/logo'));
    }
}
