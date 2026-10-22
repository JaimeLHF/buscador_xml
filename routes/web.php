<?php

use App\Http\Controllers\AcceptInvitationController;
use App\Http\Controllers\CompanyCertificateController;
use App\Http\Controllers\DocumentFileController;
use App\Http\Controllers\ExportDownloadController;
use App\Http\Controllers\SyncNowController;
use Illuminate\Support\Facades\Route;

// Ferramenta interna: a raiz leva à caixa de notas (ou ao login, via middleware auth).
Route::redirect('/', '/documents')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('organizations/create', 'pages::organizations.create')->name('organizations.create');
    Route::get('invitations/{token}', AcceptInvitationController::class)->middleware('throttle:10,1')->name('invitations.accept');

    Route::middleware('organization')->group(function () {
        // Destino padrão do login (Fortify): a caixa de entrada de notas substituiu o antigo painel.
        Route::redirect('dashboard', '/documents')->name('dashboard');

        Route::livewire('organization/members', 'pages::organizations.members')->name('organizations.members');
        Route::livewire('organization/audit', 'pages::organizations.audit')->name('organizations.audit');
        Route::livewire('organization/integrations', 'pages::organizations.integrations')->name('organizations.integrations');

        Route::livewire('companies', 'pages::companies.index')->name('companies.index');
        Route::livewire('companies/create', 'pages::companies.form')->name('companies.create');
        Route::livewire('companies/{company}', 'pages::companies.show')->name('companies.show');
        Route::livewire('companies/{company}/edit', 'pages::companies.form')->name('companies.edit');
        Route::post('companies/{company}/certificates', [CompanyCertificateController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('companies.certificates.store');
        Route::post('companies/{company}/sync', SyncNowController::class)->middleware('throttle:6,1')->name('companies.sync');

        Route::livewire('documents', 'pages::documents.index')->name('documents.index');
        Route::livewire('documents/{document}', 'pages::documents.show')->name('documents.show');
        Route::get('documents/{document}/xml', [DocumentFileController::class, 'xml'])->name('documents.xml');
        Route::get('documents/{document}/danfe', [DocumentFileController::class, 'danfe'])->middleware('throttle:30,1')->name('documents.danfe');

        Route::livewire('exports', 'pages::exports.index')->name('exports.index');
        Route::get('exports/{export}/download', ExportDownloadController::class)->name('exports.download');

        Route::livewire('sefaz-log', 'pages::sefaz-log')->name('sefaz-log');
    });
});

require __DIR__.'/settings.php';
