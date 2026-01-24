<?php

namespace App\Services;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

class Bucket
{
    private static $instance;

    public static function instance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function url(string $path)
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $fs */
        $fs = Storage::disk('uploads');
        return $fs->url($path);
    }

    public function crypt($data, $encrypt = true) {
        static $key = 'secreteazyuincom'; // 16 chars
        if ($encrypt) {
            // 1. Bitwise XOR
            $output = $data ^ str_repeat($key, (strlen($data) / 16) + 1);
            // 2. Encode & make URL-safe
            return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($output));
        } else {
            // 1. Undo URL-safe characters
            $data = str_replace(['-', '_'], ['+', '/'], $data);
            // 2. Decode
            $decoded = base64_decode($data);
            if ($decoded === false) return '';
            // 3. Bitwise XOR to get original string back
            return $decoded ^ str_repeat($key, (strlen($decoded) / 16) + 1);
        }
    }

    public function authenticate(Request $request)
    {
        $token = $request->header('x-token');
        try {
            $ttl = 300; // 5 minutes
            $secret = config('services.bucket.secret');
            if (empty($secret)) {
                return null; // no secret so no check
            }

            if (!$token) {
                return 'Token missing';
            }

            $decoded = base64_decode($token, true);
            if (!$decoded || !str_contains($decoded, '.')) {
                return 'Invalid token';
            }

            [$payload, $signature] = explode('.', $decoded, 2);
            $expected = hash_hmac('sha256', $payload, $secret);
            if (!hash_equals($expected, $signature)) {
                return 'Invalid signature';
            }

            $data = json_decode($payload, true);
            if (!$data || !isset($data['ts'])) {
                return 'Invalid payload';
            }

            // prevent replay attacks
            if (abs(time() - $data['ts']) > $ttl) {
                return 'Token expired';
            }

            // app check
            if (($data['app'] ?? null) !== 'eazyuin') {
                return 'Invalid app';
            }

            return null;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    public function generateFilename(
        UploadedFile $file,
        string $prefix = '',
        string $suffix = '',
        int $maxLength = 100
    ): string {
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === '') {
            $extension = $this->extensionFromMime(
                $file->getMimeType() ?? ''
            );
        }

        $originalName = pathinfo(
            $file->getClientOriginalName(),
            PATHINFO_FILENAME
        );

        $name = Str::slug($originalName) ?: 'file';
        $prefix = Str::slug($prefix);
        $suffix = Str::slug($suffix);

        $uuid = Str::uuid7()->toString(); // 36 chars

        // Assemble fixed parts
        $parts = array_filter([$prefix, $name, $uuid, $suffix]);
        $base = implode('-', $parts);

        $filename = "{$base}.{$extension}";

        if (strlen($filename) <= $maxLength) {
            return $filename;
        }

        /**
         * Step 1: truncate real filename
         */
        $fixedLength =
            strlen($prefix) +
            strlen($uuid) +
            strlen($suffix) +
            strlen($extension) +
            4; // dashes + dot

        $allowedNameLength = max(5, $maxLength - $fixedLength);
        $name = substr($name, 0, $allowedNameLength);

        $parts = array_filter([$prefix, $name, $uuid, $suffix]);
        $filename = implode('-', $parts) . ".{$extension}";

        if (strlen($filename) <= $maxLength) {
            return $filename;
        }

        /**
         * Step 2: truncate UUID if still too long
         */
        $excess = strlen($filename) - $maxLength;
        $uuid = substr($uuid, 0, max(8, strlen($uuid) - $excess));

        $parts = array_filter([$prefix, $name, $uuid, $suffix]);
        return implode('-', $parts) . ".{$extension}";
    }

    public function canCrop(string $mime)
    {
        return in_array($mime, [
            'image/jpeg',
            'image/png',
            'image/webp',
        ], true);
    }

    public function cropAndStore(
        ImageManager $manager,
        UploadedFile $file,
        string $folder,
        string $filename,
        int $targetWidth,
        int $targetHeight
    ): string {
        $image = $manager->read($file->getRealPath());

        $origW = $image->width();
        $origH = $image->height();

        $scale = max(
            $targetWidth / $origW,
            $targetHeight / $origH
        );

        $newW = (int) ($origW * $scale);
        $newH = (int) ($origH * $scale);

        $image->resize($newW, $newH)->crop(
            $targetWidth,
            $targetHeight,
            (int) (($newW - $targetWidth) / 2),
            (int) (($newH - $targetHeight) / 2)
        );

        $mime = $file->getMimeType();

        $encoded = match ($mime) {
            'image/jpeg' => $image->toJpeg(85),
            'image/webp' => $image->toWebp(85),
            default => $image->toPng(),
        };

        $path = trim("$folder/$filename", '/');
        Storage::disk('uploads')->put($path, (string) $encoded);
        return $path;
    }

    public function extensionFromMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }

    public function resolveDeletePath(?string $folder, string $file): ?string
    {
        // security: block traversal
        if (str_contains($file, '..')) {
            return null;
        }

        $folder = trim((string) $folder, '/');
        $file = trim($file, '/');

        // case 1: full path passed in file
        if ($folder === '' && str_contains($file, '/')) {
            return $file;
        }

        // case 2: normal folder + filename
        if ($folder !== '' && !str_contains($file, '/')) {
            return "{$folder}/{$file}";
        }

        // everything else is invalid
        return null;
    }

}
