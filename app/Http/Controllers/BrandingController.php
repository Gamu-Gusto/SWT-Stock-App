<?php

namespace App\Http\Controllers;

use App\Support\AppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public branding needed before sign-in (name, colour, logo). Contains no sensitive data.
 */
class BrandingController extends Controller
{
    public function __construct(protected AppSettings $settings) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'business_name' => $this->settings->get('business_name'),
            'brand_color' => $this->settings->brandColor(),
            'brand_ink' => $this->settings->brandInk(),
            'logo_url' => $this->settings->logoUrl(),
        ]);
    }

    public function logo(): Response
    {
        $path = $this->settings->get('logo_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'public, max-age=3600']);
    }
}
