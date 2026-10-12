<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;

/**
 * Safe file storage: files are kept on the private "local" disk (storage/app/private, never web
 * accessible), stored under random names, and only served through authorised API routes.
 */
class MediaService
{
    public static function imageRules(): array
    {
        return ['required', File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(5 * 1024)];
    }

    public static function documentRules(): array
    {
        return ['required', File::types(['pdf', 'jpg', 'jpeg', 'png', 'webp'])->max(10 * 1024)];
    }

    public function store(Model $owner, UploadedFile $file, string $collection, User $user): Media
    {
        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $dir = Str::snake(class_basename($owner)).'/'.$owner->getKey();
        $path = $file->storeAs($dir, Str::uuid().'.'.$ext, 'local');

        $media = new Media([
            'collection' => $collection,
            'disk' => 'local',
            'path' => $path,
            'original_name' => mb_substr(preg_replace('/[^\w.\- ]/u', '_', $file->getClientOriginalName()), 0, 200),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(),
            'uploaded_by' => $user->id,
        ]);
        $media->mediable()->associate($owner);
        $media->save();
        Audit::log('media.uploaded', $owner, "Uploaded {$collection} file {$media->original_name}");

        return $media;
    }

    public function delete(Media $media): void
    {
        Storage::disk($media->disk)->delete($media->path);
        Audit::log('media.deleted', $media->mediable_type ? class_basename($media->mediable_type) : null, "Deleted file {$media->original_name}");
        $media->delete();
    }
}
