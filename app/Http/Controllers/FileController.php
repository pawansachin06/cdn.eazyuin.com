<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Services\Bucket;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        $input = $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'file|max:51200', // max 50 MB
            'folder' => 'required|string',
            'meta' => 'nullable|array',
            'meta.*.name' => 'nullable|string|max:150',
        ], [
            'files.required' => 'Files not selected',
        ]);
        try {
            $stored = [];
            $bucket = Bucket::instance();
            $folder = trim($input['folder'], '/');

            $imageManager = new ImageManager(new Driver());

            foreach ($request->file('files') as $index => $file) {
                $meta = $request->input("meta.{$index}", []);

                if (!empty($meta['name'])) {
                    // explicit name: overwrite allowed
                    $filename = trim($meta['name'], '/');
                } else {
                    $filename = $bucket->generateFilename(
                        $file,
                        $meta['prefix'] ?? '',
                        $meta['suffix'] ?? ''
                    );
                }

                $type = $file->getType();
                $mime = $file->getMimeType();
                $canCrop = $bucket->canCrop($mime);
                $width = isset($meta['width']) ? (int) $meta['width'] : null;
                $height = isset($meta['height']) ? (int) $meta['height'] : null;
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?? '');

                if ($width > 0 && $height > 0 && $canCrop) {
                    // crop
                    $path = $bucket->cropAndStore(
                        $imageManager,
                        $file,
                        $folder,
                        $filename,
                        $width,
                        $height
                    );
                } else {
                    // normal store
                    $path = $file->storeAs($folder, $filename, 'uploads');
                }

                $url = $bucket->url($path);
                $cropped = $width && $height && $canCrop;
                $stored[] = [
                    'extension' => $extension,
                    'cropped' => $cropped,
                    'folder' => $folder,
                    'name' => $filename,
                    'type' => $type,
                    'mime' => $mime,
                    'url' => $url,
                ];
            }
            return response()->json([
                'item' => $stored[0] ?? null,
                'items' => $stored
            ], 201);
        } catch (Exception $e) {
            $msg = $e->getMessage();
            Log::error("UPLOAD: $msg", $e->getTrace());
            return response()->json(['message' => $msg], 500);
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
}
