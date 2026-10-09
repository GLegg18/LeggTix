<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ApiDocumentationController;
use App\Http\Middleware\EnsureLocalApiDocumentation;

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'status' => 'ok',
    'message' => 'LeggTix API is running.',
]));

Route::middleware(EnsureLocalApiDocumentation::class)->group(function (): void {
    Route::get('/docs/api', [ApiDocumentationController::class, 'index'])->name('api-docs');
    Route::get('/docs/api.json', [ApiDocumentationController::class, 'specification'])->name('api-docs.specification');
    Route::get('/docs/api/assets/{asset}', [ApiDocumentationController::class, 'asset'])->name('api-docs.asset');
});
