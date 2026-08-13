<?php

namespace App\Modules\Shadowing\Infrastructure\Adapters;

use App\Modules\Shadowing\Domain\Contracts\ShadowingRecordingStorageContract;
use Illuminate\Support\Facades\Storage;

class LaravelMediaRecordingStorageAdapter implements ShadowingRecordingStorageContract
{
    // Canonical storage rule: Business code must ONLY use logical disk 'media'
    protected string $disk = 'media';

    public function store(string $objectKey, mixed $fileContents, string $mimeType): bool
    {
        return Storage::disk($this->disk)->put($objectKey, $fileContents, [
            'ContentType' => $mimeType,
            'visibility' => 'private',
        ]);
    }

    public function temporaryUrl(string $objectKey, int $ttlMinutes): string
    {
        return Storage::disk($this->disk)->temporaryUrl(
            $objectKey,
            now()->addMinutes($ttlMinutes)
        );
    }

    public function delete(string $objectKey): bool
    {
        if ($this->exists($objectKey)) {
            return Storage::disk($this->disk)->delete($objectKey);
        }
        return true;
    }

    public function exists(string $objectKey): bool
    {
        return Storage::disk($this->disk)->exists($objectKey);
    }
}
