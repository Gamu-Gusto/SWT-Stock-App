<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Product;
use App\Models\StockReceipt;
use App\Models\TyreUnit;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves private uploaded files only to users allowed to see the record they belong to.
 */
class MediaController extends Controller
{
    public function show(Request $request, Media $media): StreamedResponse
    {
        abort_unless($this->canView($request, $media), 403);
        abort_unless(Storage::disk($media->disk)->exists($media->path), 404);

        return Storage::disk($media->disk)->response($media->path, $media->original_name, [
            'Content-Type' => $media->mime_type,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    public function destroy(Request $request, Media $media, MediaService $service): JsonResponse
    {
        $permission = $media->mediable_type === StockReceipt::class ? 'receiving.create' : 'inventory.manage';
        abort_unless($request->user()->hasPermission($permission), 403);
        $service->delete($media);

        return response()->json(['message' => 'File deleted.']);
    }

    protected function canView(Request $request, Media $media): bool
    {
        $user = $request->user();

        return match ($media->mediable_type) {
            Product::class, TyreUnit::class => $user->hasPermission('inventory.view') || $user->hasPermission('stocktake.count'),
            StockReceipt::class => $user->hasPermission('receiving.view'),
            default => $user->isOwner(),
        };
    }
}
