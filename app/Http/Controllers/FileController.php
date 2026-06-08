<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Services\Bucket;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

class FileController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    public function apiStore(Request $request)
    {
        $bucket = Bucket::instance();
        $authError = $bucket->authenticate($request);

        if ($authError) {
            return response()->json([
                'success' => false,
                'message' => $authError,
            ], 401);
        }

        $input = $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'file|max:51200',
            'folder' => 'required|string|max:250',
            'meta' => 'nullable|array',
            'meta.*.name' => 'nullable|string|max:150',
            'meta.*.prefix' => 'nullable|string|max:80',
            'meta.*.suffix' => 'nullable|string|max:80',
            'meta.*.width' => 'nullable|integer|min:1|max:5000',
            'meta.*.height' => 'nullable|integer|min:1|max:5000',
        ], [
            'files.required' => 'Files not selected',
        ]);

        try {
            $stored = [];
            $folder = $bucket->sanitizeFolder((string) $input['folder']);

            if ($folder === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid folder.',
                ], 422);
            }

            $imageManager = new ImageManager(new Driver());

            foreach ($request->file('files') as $index => $file) {
                $meta = $request->input("meta.{$index}", []);

                if (! empty($meta['name'])) {
                    $filename = $bucket->sanitizeFilename((string) $meta['name'], $file);
                } else {
                    $filename = $bucket->generateFilename(
                        $file,
                        (string) ($meta['prefix'] ?? ''),
                        (string) ($meta['suffix'] ?? '')
                    );
                }

                $mime = $file->getMimeType() ?: 'application/octet-stream';
                $type = $file->getType();
                $width = isset($meta['width']) ? (int) $meta['width'] : null;
                $height = isset($meta['height']) ? (int) $meta['height'] : null;
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: $bucket->extensionFromMime($mime));
                $canCrop = $bucket->canCrop($mime);

                if ($width > 0 && $height > 0 && $canCrop) {
                    $path = $bucket->cropAndStore(
                        $imageManager,
                        $file,
                        $folder,
                        $filename,
                        $width,
                        $height
                    );
                } else {
                    $path = $file->storeAs($folder, $filename, 'uploads');
                }

                $stored[] = [
                    'extension' => $extension,
                    'cropped' => (bool) ($width && $height && $canCrop),
                    'folder' => $folder,
                    'name' => $filename,
                    'path' => $path,
                    'type' => $type,
                    'mime' => $mime,
                    'size' => $file->getSize(),
                    'url' => $bucket->url($path),
                ];
            }

            return response()->json([
                'success' => true,
                'item' => $stored[0] ?? null,
                'items' => $stored,
            ], 201);
        } catch (Throwable $e) {
            Log::error('CDN-UPLOAD-FAILED', [
                'message' => $e->getMessage(),
                'folder' => $request->input('folder'),
                'files_count' => is_array($request->file('files')) ? count($request->file('files')) : 0,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(File $file)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(File $file)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, File $file)
    {
        //
    }

    public function apiDelete(Request $request)
    {
        try {
            $bucket = Bucket::instance();
            $msg = $bucket->authenticate($request);
            if ($msg) {
                return response()->json(['message' => $msg], 401);
            }

            $folder = $request->query('folder', $request->input('folder'));
            $files = $request->query('files', $request->input('files'));
            if (is_string($files)) {
                $files = array_filter(array_map('trim', explode(',', $files)));
            }
            $data = validator(
                ['folder' => $folder, 'files' => $files],
                [
                    'folder' => 'nullable|string',
                    'files' => 'required|array|min:1',
                    'files.*' => 'required|string',
                ]
            )->validate();
            $folder = trim($data['folder'], '/');
            $fs = Storage::disk('uploads');
            $results = [];
            foreach ($data['files'] as $file) {
                $path = $bucket->resolveDeletePath($folder, $file);
                if (!$path) {
                    $results[] = [
                        'file' => $file,
                        'deleted' => false,
                        'error' => 'Invalid path',
                    ];
                    continue;
                }

                if (!$fs->exists($path)) {
                    $results[] = [
                        'file' => $file,
                        'deleted' => false,
                        'error' => 'File not found',
                    ];
                    continue;
                }

                $folderPattern = '#^products/\d{4}/\d{2}(/.*)?$#';

                try {
                    // check if director
                    if (
                        $fs->directoryExists($path) &&
                        preg_match($folderPattern, $path)
                    ) {
                        $fs->deleteDirectory($path);
                    } else {
                        $fs->delete($path);
                    }

                    $results[] = [
                        'file' => $file,
                        'deleted' => true,
                    ];
                } catch (Throwable $e) {
                    Log::error('DEL ERROR', [
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                    $results[] = [
                        'file' => $file,
                        'deleted' => false,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return response()->json([
                'folder' => $folder,
                'item' => $results[0],
                'items' => $results,
            ]);
        } catch (Exception $e) {
            $msg = $e->getMessage();
            Log::error("DEL: $msg", $e->getTrace());
            return response()->json(['message' => $msg], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(File $file)
    {
        //
    }

    private function dummy(int $hours = 2)
    {
        $ttl = $hours * 3600;
        $dummyImage = public_path('uploads/img/placeholder/360.png');
        return response()->file($dummyImage, [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'public, max-age=' . $ttl,
        ]);
    }

    public function external(Request $request, string $hash)
    {
        $bucket = Bucket::instance();
        $url = $bucket->crypt($hash, false);

        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->dummy(2);
        }

        try {
            $response = Http::timeout(10)->get($url);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            Log::warning('ERR', ['url' => $url, 'msg' => $msg]);
            return $this->dummy(4);
        }

        if (!$response->successful()) {
            return $this->dummy($response->status() === 404 ? 2 : 4);
        }

        $body = $response->body();
        if (strlen($body) > 2_000_000) {
            return $this->dummy(4);
        }

        $mime = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $body);
        if (!str_starts_with($mime, 'image/')) {
            Log::warning('NOT-IMAGE', ['url' => $url, 'mime' => $mime]);
            return $this->dummy(4);
        }

        return response($body, 200)
            ->header('Content-Type', $mime)
            ->header('Cache-Control', 'public, max-age=604800, immutable')
            ->header('Surrogate-Control', 'max-age=604800');
    }
}
