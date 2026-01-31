<?php

use App\Http\Controllers\FileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('api/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('external/{hash}', [FileController::class, 'external']);

Route::post('api/v1/uploads', [FileController::class, 'apiStore']);
Route::delete('api/v1/uploads', [FileController::class, 'apiDelete']);
