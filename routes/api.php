<?php

use App\Http\Controllers\Api\DocumentController;
use Illuminate\Support\Facades\Route;

// API de integração (ERP): só leitura, autenticada por token da organização.
Route::prefix('v1')->middleware(['api.token', 'throttle:api'])->group(function () {
    Route::get('documents', [DocumentController::class, 'index'])->name('api.documents.index');
    Route::get('documents/{chave}', [DocumentController::class, 'show'])->name('api.documents.show');
    Route::get('documents/{chave}/xml', [DocumentController::class, 'xml'])->name('api.documents.xml');
});
