<?php

namespace App\Services;

use Auth;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use RuntimeException;

class UploadService {

    /**
     * Extensions that are always rejected, regardless of MIME.
     */
    const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'php56',
        'phtml', 'pht', 'phar',
        'cgi', 'pl', 'py', 'sh',
        'shtml', 'htaccess',
    ];

    /**
     * MIME types that will be treated as images and re-encoded.
     */
    const IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    /**
     * MIME → safe extension mapping.
     */
    const MIME_EXTENSION_MAP = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /**
     * Extensions that, when used by the client, imply the file should be an image.
     * If the actual content MIME is not an image, the file is rejected.
     */
    const IMAGE_EXTENSION_INDICATORS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'bmp', 'ico', 'tiff', 'tif', 'svg',
    ];

    /**
     * Upload a file to the public storage disk.
     *
     * @param  UploadedFile  $requestFile
     * @param  string        $folder  Relative folder name (will be prefixed by school-id or super-admin).
     * @return string  Relative path, e.g. "15/user/abc123.jpg"
     *
     * @throws RuntimeException
     */
    public static function upload($requestFile, $folder) {
        // 1. Sanitize folder path (prevent path traversal through folder)
        $folder = static::sanitizePath($folder);

        if (Auth::user() && Auth::user()->school_id) {
            $folder = Auth::user()->school_id . '/' . $folder;
        } else {
            $folder = 'super-admin/' . $folder;
        }

        // 2. Get & normalize the client-supplied extension
        $originalExt = strtolower($requestFile->getClientOriginalExtension());

        // 3. Reject empty extension
        if (empty($originalExt)) {
            throw new RuntimeException('File extension cannot be empty.');
        }

        // 4. Reject blocked extensions (blacklist)
        if (in_array($originalExt, static::BLOCKED_EXTENSIONS)) {
            throw new RuntimeException('File type is not allowed.');
        }

        // 5. Validate original filename against double-extension and path-traversal attacks
        static::validateFilename($requestFile->getClientOriginalName());

        // 6. Detect real MIME type from file content (not from extension)
        $realMime = $requestFile->getMimeType();

        // 6b. Reject MIME/extension misalignment for image-like extensions.
        // If the client claims an image extension, the content must actually
        // be an image. This prevents PHP payloads stored as .jpg etc.
        if (in_array($originalExt, static::IMAGE_EXTENSION_INDICATORS)) {
            if (!in_array($realMime, static::IMAGE_MIMES)) {
                throw new RuntimeException('File content does not match its extension.');
            }
            // For SVG specifically: block at the service layer by default.
            // SVG can carry XSS and is not safe for untrusted upload.
            if ($originalExt === 'svg' || $realMime === 'image/svg+xml') {
                throw new RuntimeException('SVG files are not allowed.');
            }
        }

        // 7. Determine the safe output extension
        $safeExt = static::resolveSafeExtension($originalExt, $realMime);

        // 8. Generate a random filename (never preserve the user's original name)
        $file_name = uniqid('', true) . time() . '.' . $safeExt;
        $fullPath  = $folder . '/' . $file_name;

        // 9. Store the file
        if (in_array($realMime, static::IMAGE_MIMES)) {
            // Images: decode and re-encode to strip any appended payloads
            try {
                $image = Image::make($requestFile)->encode($safeExt, 60);
                Storage::disk('public')->put($fullPath, (string) $image);
            } catch (\Exception $e) {
                throw new RuntimeException('Failed to process image file: ' . $e->getMessage());
            }
        } else {
            // Non-image files: store as-is (extension already validated)
            $requestFile->storeAs($folder, $file_name, 'public');
        }

        // 10. Set non-executable permissions (0644)
        static::setSecurePermissions($fullPath);

        return $fullPath;
    }

    /**
     * @param  string  $image  rawOriginalPath
     * @return bool
     */
    public static function delete($image) {
        if ($image && Storage::disk('public')->exists($image)) {
            return Storage::disk('public')->delete($image);
        }

        // Image does not exist in server so feel free to upload new image
        return true;
    }

    /**
     * Reject filenames containing blocked extensions in any position
     * (prevents double-extension attacks like "avatar.php56.jpg").
     *
     * Also rejects path-traversal sequences.
     *
     * @param  string  $filename
     * @throws RuntimeException
     */
    protected static function validateFilename(string $filename): void {
        // Path traversal detection
        if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new RuntimeException('Invalid filename.');
        }

        // Null-byte injection
        if (str_contains($filename, "\0")) {
            throw new RuntimeException('Invalid filename.');
        }

        // Check each dot-separated segment for blocked extensions
        $parts = explode('.', $filename);
        foreach ($parts as $part) {
            $lower = strtolower($part);
            if (in_array($lower, static::BLOCKED_EXTENSIONS)) {
                throw new RuntimeException('File type is not allowed.');
            }
        }
    }

    /**
     * Remove path-traversal sequences from folder names.
     *
     * @param  string  $path
     * @return string
     * @throws RuntimeException
     */
    protected static function sanitizePath(string $path): string {
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            throw new RuntimeException('Invalid folder path.');
        }
        return trim($path, '/\\');
    }

    /**
     * Determine the safe file extension based on MIME type.
     *
     * For known image MIME types, uses the canonical extension.
     * Otherwise, falls back to the original extension (which has already
     * been validated against the blocked list).
     *
     * @param  string       $originalExt
     * @param  string|null  $realMime
     * @return string
     * @throws RuntimeException
     */
    protected static function resolveSafeExtension(string $originalExt, ?string $realMime): string {
        // If we recognize the MIME, use the canonical extension
        if ($realMime && isset(static::MIME_EXTENSION_MAP[$realMime])) {
            return static::MIME_EXTENSION_MAP[$realMime];
        }

        // For non-image MIME types: the original extension passed all checks
        return $originalExt;
    }

    /**
     * Set file permissions to 0644 (owner RW, group R, others R).
     *
     * Only applies when the 'public' disk uses the 'local' driver.
     *
     * @param  string  $relativePath
     */
    protected static function setSecurePermissions(string $relativePath): void {
        $disk = Storage::disk('public');

        // Only attempt chmod on local filesystem
        $adapterConfig = config('filesystems.disks.public');
        if (($adapterConfig['driver'] ?? '') !== 'local') {
            return;
        }

        try {
            $absolutePath = $disk->path($relativePath);
            if (file_exists($absolutePath)) {
                chmod($absolutePath, 0644);
            }
        } catch (\Throwable) {
            // Non-fatal: the file is stored, we just couldn't adjust permissions
        }
    }
}
