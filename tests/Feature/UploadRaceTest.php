<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Ispgrid\Documents\CustomerDocuments;
use Ispgrid\Documents\Models\Document;

beforeEach(function () {
    $this->documents = app(CustomerDocuments::class);
    $this->staff = User::factory()->create();
    $this->account = Account::factory()->create();
});

it('serialises uploads of one type by locking the type row', function () {
    $type = documentType();
    DB::connection('documents')->enableQueryLog();

    $this->documents->upload($this->account, $type, pngUpload(), null, $this->staff);

    $locks = collect(DB::connection('documents')->getQueryLog())->pluck('query')
        ->filter(fn (string $sql): bool => str_contains($sql, 'document_types') && str_contains($sql, 'for update'));
    expect($locks)->not->toBeEmpty();
});

it('retries when another save took the type first, and still stores the file', function () {
    $type = documentType();
    $raced = false;
    Document::creating(function (Document $document) use (&$raced, $type) {
        if (! $raced) {
            $raced = true;
            DB::connection('documents')->table('documents')->insert(['account_id' => $document->account_id, 'document_type_id' => $type->id, 'path' => 'documents/other', 'original_name' => 'other.png', 'mime' => 'image/png', 'size' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    $document = $this->documents->upload($this->account, $type, pngUpload(), null, $this->staff);

    expect($document->exists)->toBeTrue()
        ->and(Storage::disk('addons')->exists($document->path))->toBeTrue()
        ->and(Document::query()->where('account_id', $this->account->id)->count())->toBe(1);
});
