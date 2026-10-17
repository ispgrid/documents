<?php

use Illuminate\Support\Facades\Route;
use Ispgrid\Documents\Http\DocumentFileController;
use Ispgrid\Documents\Http\DownloadAllController;

Route::middleware(['web', 'auth'])->group(function () {
    Route::livewire('documents/missing', 'documents::missing')->name('documents.missing');
    Route::livewire('documents/deleted', 'documents::deleted')->name('documents.deleted');
    Route::get('documents/{document}/file', DocumentFileController::class)->whereNumber('document')->name('documents.file');
    Route::get('documents/customers/{account}/all', DownloadAllController::class)->whereNumber('account')->name('documents.zip');
});
