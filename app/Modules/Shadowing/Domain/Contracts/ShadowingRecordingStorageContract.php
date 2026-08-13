<?php

namespace App\Modules\Shadowing\Domain\Contracts;

interface ShadowingRecordingStorageContract
{
    /**
     * Store a file object on the logical media disk.
     */
    public function store(string $objectKey, mixed $fileContents, string $mimeType): bool;

    /**
     * Generate a temporary playback URL for a private object key on the logical media disk.
     */
    public function temporaryUrl(string $objectKey, int $ttlMinutes): string;

    /**
     * Delete an object from the logical media disk.
     */
    public function delete(string $objectKey): bool;

    /**
     * Check if an object exists on the logical media disk.
     */
    public function exists(string $objectKey): bool;
}
