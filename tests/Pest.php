<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
| Run from the core checkout:
|   ISPGRID_ADDONS=on php vendor/bin/pest --test-directory=vendor/ispgrid/documents/tests vendor/ispgrid/documents/tests
| The documents database lives on its own connection, outside RefreshDatabase's transaction: it is emptied before
| each test, and the add-on disk is faked.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        DB::connection('documents')->table('documents')->delete();
        DB::connection('documents')->table('document_types')->delete();
        Storage::fake('addons');
    })
    ->in(__DIR__);
