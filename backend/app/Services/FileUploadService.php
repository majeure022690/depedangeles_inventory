<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FileUploadService
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const MIN_DIMENSION = 50;

    private const MAX_DIMENSION = 8000;

    /**
     * Validate, re-encode, and store an image attachment — the pipeline in
     * SECURITY.md §7: extension whitelist, real-MIME sniff (client-declared
     * MIME is never trusted), genuine-image check, re-encode through GD to
     * a fresh file (strips any embedded payload/polyglot content and all
     * EXIF, including GPS EXIF beyond the intended map pin), store under a
     * generated UUID filename on the given disk (public for avatars). Returns the metadata to
     * persist on the attachment row; throws ValidationException on any
     * rejection so it surfaces as the same 422 shape as any other Form
     * Request failure.
     *
     * @return array<string, mixed>
     */
    public function storeImage(UploadedFile $file, string $disk, string $directory, string $errorField = 'attachments'): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $this->reject($errorField, 'Only JPG, PNG, and WEBP images are accepted.');
        }

        $realMimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

        if (! in_array($realMimeType, self::ALLOWED_MIME_TYPES, true)) {
            $this->reject($errorField, 'The uploaded file is not a genuine JPG, PNG, or WEBP image.');
        }

        $dimensions = @getimagesize($file->getRealPath());

        if ($dimensions === false) {
            $this->reject($errorField, 'The uploaded file could not be read as an image.');
        }

        [$width, $height] = $dimensions;

        if ($width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION
            || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            $this->reject($errorField, 'Image dimensions are outside the accepted range.');
        }

        $image = match ($realMimeType) {
            'image/jpeg' => @imagecreatefromjpeg($file->getRealPath()),
            'image/png' => @imagecreatefrompng($file->getRealPath()),
            'image/webp' => @imagecreatefromwebp($file->getRealPath()),
        };

        if ($image === false) {
            $this->reject($errorField, 'The uploaded file could not be decoded as an image.');
        }

        // Preserve any alpha channel (PNG/WEBP) instead of flattening it to
        // black on re-encode.
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        $storedExtension = match ($realMimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        };

        $storedFilename = Str::uuid()->toString().'.'.$storedExtension;
        $path = trim($directory, '/').'/'.$storedFilename;

        Storage::disk($disk)->makeDirectory($directory);
        $absolutePath = Storage::disk($disk)->path($path);

        // Re-encoding through a fresh imagejpeg/imagepng/imagewebp call is
        // what actually strips EXIF/embedded metadata — GD never carries
        // the source file's metadata into the output.
        $encoded = match ($realMimeType) {
            'image/jpeg' => imagejpeg($image, $absolutePath, 85),
            'image/png' => imagepng($image, $absolutePath, 6),
            'image/webp' => imagewebp($image, $absolutePath, 85),
        };

        imagedestroy($image);

        if (! $encoded) {
            $this->reject($errorField, 'The image could not be processed.');
        }

        // Compute SHA-256 hash of the re-encoded file for duplicate detection
        $fileHash = hash_file('sha256', $absolutePath);

        return [
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $storedFilename,
            'disk' => $disk,
            'mime_type' => $realMimeType,
            'file_size' => Storage::disk($disk)->size($path),
            'file_hash' => $fileHash,
            'path' => $path,
            'uploaded_at' => now(),
        ];
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
