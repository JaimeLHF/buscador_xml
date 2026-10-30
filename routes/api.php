<?php

use App\Http\Controllers\Api\CoberturaController;
use App\Http\Controllers\Api\ConexaoController;
use App\Http\Controllers\Api\DocumentController;
use Illuminate\Support\Facades\Route;

// API de integração (Costhub, ERP): só leitura, autenticada pela chave da conexão.
Route::prefix('v1')->middleware(['api.token', 'throttle:api'])->group(function () {
    Route::get('conexao', ConexaoController::class)->name('api.conexao');
    Route::get('documents', [DocumentController::class, 'index'])->name('api.documents.index');
    Route::get('documents/{chave}', [DocumentController::class, 'show'])->name('api.documents.show');
    Route::get('documents/{chave}/xml', [DocumentController::class, 'xml'])->name('api.documents.xml');
    Route::get('cobertura', CoberturaController::class)->name('api.cobertura');
});
