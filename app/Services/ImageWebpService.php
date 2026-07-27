<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use RuntimeException;

class ImageWebpService
{
    private ImageManager $images;

    public function __construct()
    {
        // Intervention Image v3: driver auto-detect (GD by default)
        $this->images = ImageManager::gd();
    }

    /**
     * Convert file to WEBP and store in public disk.
     *
     * @return string relative path in "public" disk
     */
    public function storeAsWebp(UploadedFile $file, string $directory, int $quality = 80): string
    {
        return $this->storeAsWebpFromPath($file->getRealPath(), $directory, $quality);
    }

    /**
     * Convert file from a given path to WEBP.
     */
    public function storeAsWebpFromPath(string $filePath, string $directory, int $quality = 80): string
    {
        $image = $this->images->read($filePath);
        $encoded = $image->toWebp($quality);

        $filename = uniqid('img_', true) . '.webp';
        $path = trim($directory, '/') . '/' . $filename;

        $stored = Storage::disk('public')->put($path, (string) $encoded);
        if ($stored === false || ! Storage::disk('public')->exists($path)) {
            throw new RuntimeException("Failed to store image in public disk at path: {$path}");
        }

        return $path;
    }
}

