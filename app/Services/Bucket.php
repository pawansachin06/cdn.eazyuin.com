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
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (! self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function url(string $path): string
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $fs */
        $fs = Storage::disk('uploads');
        return $fs->url(trim($path, '/'));
    }

    public function crypt($data, $encrypt = true): string
    {
        static $key = 'secreteazyuincom';
        $data = (string) $data;

        if ($encrypt) {
            $output = $data ^ str_repeat($key, (int) (strlen($data) / 16) + 1);
            return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($output));
        }

        $data = str_replace(['-', '_'], ['+', '/'], $data);
        $decoded = base64_decode($data);

        if ($decoded === false) {
            return '';
        }

        return $decoded ^ str_repeat($key, (int) (strlen($decoded) / 16) + 1);
    }

    public function authenticate(Request $request): ?string
    {
        $token = $request->header('x-token');

        try {
            $ttl = 300;
            $secret = (string) config('services.bucket.secret');

            if ($secret === '') {
                return null;
            }

            if (! $token) {
                return 'Token missing';
            }

            $decoded = base64_decode($token, true);
            if (! $decoded || ! str_contains($decoded, '.')) {
                return 'Invalid token';
            }

            [$payload, $signature] = explode('.', $decoded, 2);
            $expected = hash_hmac('sha256', $payload, $secret);

            if (! hash_equals($expected, $signature)) {
                return 'Invalid signature';
            }

            $data = json_decode($payload, true);
            if (! is_array($data) || ! isset($data['ts'])) {
                return 'Invalid payload';
            }

            if (abs(time() - (int) $data['ts']) > $ttl) {
                return 'Token expired';
            }

            if (($data['app'] ?? null) !== 'eazyuin') {
                return 'Invalid app';
            }

            return null;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    public function generateFilename(UploadedFile $file, string $prefix = '', string $suffix = '', int $maxLength = 100): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === '') {
            $extension = $this->extensionFromMime($file->getMimeType() ?? '');
        }

        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $name = Str::slug($originalName) ?: 'file';
        $prefix = Str::slug($prefix);
        $suffix = Str::slug($suffix);
        $uuid = Str::uuid7()->toString();

        $parts = array_filter([$prefix, $name, $uuid, $suffix]);
        $filename = implode('-', $parts) . ".{$extension}";

        if (strlen($filename) <= $maxLength) {
            return $filename;
        }

        $fixedLength = strlen($prefix) + strlen($uuid) + strlen($suffix) + strlen($extension) + 4;
        $allowedNameLength = max(5, $maxLength - $fixedLength);
        $name = substr($name, 0, $allowedNameLength);

        $parts = array_filter([$prefix, $name, $uuid, $suffix]);
        $filename = implode('-', $parts) . ".{$extension}";

        if (strlen($filename) <= $maxLength) {
            return $filename;
        }

        $excess = strlen($filename) - $maxLength;
        $uuid = substr($uuid, 0, max(8, strlen($uuid) - $excess));

        $parts = array_filter([$prefix, $name, $uuid, $suffix]);
        return implode('-', $parts) . ".{$extension}";
    }

    public function sanitizeFolder(string $folder): string
    {
        $folder = trim(str_replace('\\', '/', $folder), '/');
        $segments = array_filter(explode('/', $folder), fn ($part) => $part !== '' && $part !== '.' && $part !== '..');

        $segments = array_map(function ($part) {
            return Str::slug($part) ?: 'folder';
        }, $segments);

        return implode('/', $segments);
    }

    public function sanitizeFilename(string $filename, UploadedFile $file): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($extension === '') {
            $extension = $this->extensionFromMime($file->getMimeType() ?? '');
        }

        $base = pathinfo($filename, PATHINFO_FILENAME);
        $base = Str::slug($base) ?: 'file';

        return substr($base, 0, 120) . ".{$extension}";
    }

    public function canCrop(string $mime): bool
    {
        return in_array($mime, [
            'image/jpeg',
            'image/png',
            'image/webp',
        ], true);
    }

    public function cropAndStore(ImageManager $manager, UploadedFile $file, string $folder, string $filename, int $targetWidth, int $targetHeight): string
    {
        $image = $manager->read($file->getRealPath());

        $origW = $image->width();
        $origH = $image->height();

        $scale = max($targetWidth / $origW, $targetHeight / $origH);
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

        $path = trim("{$folder}/{$filename}", '/');
        Storage::disk('uploads')->put($path, (string) $encoded);

        return $path;
    }

    public function extensionFromMime(string $mime): string
    {
        return match (strtolower($mime)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            default => 'bin',
        };
    }

    public function resolveDeletePath(?string $folder, string $file): ?string
    {
        if (str_contains($file, '..')) {
            return null;
        }

        $folder = $this->sanitizeFolder((string) $folder);
        $file = trim(str_replace('\\', '/', $file), '/');

        if ($folder === '' && str_contains($file, '/')) {
            return $file;
        }

        if ($folder !== '' && ! str_contains($file, '/')) {
            return "{$folder}/{$file}";
        }

        if ($folder === '' && ! str_contains($file, '/')) {
            return $file;
        }

        return null;
    }
}
