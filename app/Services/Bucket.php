<?php

namespace App\Services;

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
}
