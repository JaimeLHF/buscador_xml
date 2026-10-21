<?php

use App\Http\Controllers\CompanyCertificateController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('organizations/create', 'pages::organizations.create')->name('organizations.create');

    Route::middleware('organization')->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');

        Route::livewire('companies', 'pages::companies.index')->name('companies.index');
        Route::livewire('companies/create', 'pages::companies.form')->name('companies.create');
        Route::livewire('companies/{company}', 'pages::companies.show')->name('companies.show');
        Route::livewire('companies/{company}/edit', 'pages::companies.form')->name('companies.edit');
        Route::post('companies/{company}/certificates', [CompanyCertificateController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('companies.certificates.store');
    });
});

require __DIR__.'/settings.php';
