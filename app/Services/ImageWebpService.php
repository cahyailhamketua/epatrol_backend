<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

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
        $image = $this->images->read($file->getRealPath());

        // Encode to webp bytes
        $encoded = $image->toWebp($quality);

        $filename = uniqid('img_', true) . '.webp';
        $path = trim($directory, '/') . '/' . $filename;

        Storage::disk('public')->put($path, (string) $encoded);

        return $path;
    }
}

