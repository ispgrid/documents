<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Ispgrid\Documents\CustomerDocuments;
use Ispgrid\Documents\Models\Document;
use Ispgrid\Documents\Models\NumberMode;
use Ispgrid\Documents\StoredFile;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->documents = app(CustomerDocuments::class);
    $this->staff = User::factory()->create();
    $this->account = Account::factory()->create();
});

it('stores the file encrypted and reads it back', function () {
    $document = $this->documents->upload($this->account, documentType(), pngUpload(), null, $this->staff);

    expect($document->path)->toMatch('#^documents/'.$this->account->id.'/[A-Za-z0-9]{40}$#')
        ->and(Storage::disk('addons')->get($document->path))->not->toBe(pngBytes())
        ->and(app(StoredFile::class)->read($document))->toBe(pngBytes())
        ->and($document->mime)->toBe('image/png')
        ->and($document->original_name)->toBe('aadhaar.png')
        ->and($document->uploaded_by)->toBe($this->staff->id);
});

it('stores a full number encrypted and finds it by fingerprint', function () {
    $document = $this->documents->upload($this->account, documentType(), pngUpload(), '1234 5678 4821', $this->staff);

    $raw = Document::query()->toBase()->where('id', $document->id)->value('number');

    expect($document->fresh()->number)->toBe('1234 5678 4821')
        ->and($raw)->not->toContain('4821')
        ->and($document->fresh()->number_fingerprint)->not->toBeNull();
});

it('hides the old file on replace and carries the number over', function () {
    $type = documentType();
    $first = $this->documents->upload($this->account, $type, pngUpload(), '1234 5678 4821', $this->staff);
    $second = $this->documents->upload($this->account, $type, pngUpload('new.png'), null, $this->staff);

    expect($first->fresh()->trashed())->toBeTrue()
        ->and($first->fresh()->deleted_by)->toBe($this->staff->id)
        ->and($first->fresh()->number)->toBe('1234 5678 4821')
        ->and($second->number)->toBe('1234 5678 4821')
        ->and($second->number_fingerprint)->toBe($first->fresh()->number_fingerprint)
        ->and(Storage::disk('addons')->exists($first->path))->toBeTrue();
});

it('keeps one current file when two uploads race', function () {
    $type = documentType();
    Document::query()->create(['account_id' => $this->account->id, 'document_type_id' => $type->id, 'path' => 'documents/x', 'original_name' => 'x.png', 'mime' => 'image/png', 'size' => 1]);

    $this->documents->upload($this->account, $type, pngUpload(), null, $this->staff);

    expect(Document::query()->where('account_id', $this->account->id)->count())->toBe(1)
        ->and(Document::withTrashed()->where('account_id', $this->account->id)->count())->toBe(2);
});

it('refuses to move a document onto a taken type', function () {
    $aadhaar = documentType();
    $pan = documentType('PAN card');
    $this->documents->upload($this->account, $pan, pngUpload(), null, $this->staff);
    $document = $this->documents->upload($this->account, $aadhaar, pngUpload(), null, $this->staff);

    expect(fn () => $this->documents->edit($document, $pan, null, $this->staff))->toThrow(ValidationException::class);
    expect($document->fresh()->document_type_id)->toBe($aadhaar->id);
});

it('cuts, keeps or drops the number by the target type', function (NumberMode $mode, ?string $expected) {
    $document = $this->documents->upload($this->account, documentType(), pngUpload(), '1234 5678 4821', $this->staff);

    $this->documents->edit($document, documentType('Other', ['number_mode' => $mode]), null, $this->staff);

    expect($document->fresh()->number)->toBe($expected);
})->with([
    'full' => [NumberMode::Full, '1234 5678 4821'],
    'last 4' => [NumberMode::LastFour, '4821'],
    'none' => [NumberMode::None, null],
]);

it('changes or clears the number on edit, and keeps it when left out', function () {
    $type = documentType();
    $document = $this->documents->upload($this->account, $type, pngUpload(), '1234 5678 4821', $this->staff);

    $this->documents->edit($document, $type, null, $this->staff);
    expect($document->fresh()->number)->toBe('1234 5678 4821');

    $this->documents->edit($document, $type, '9999 8888 7777', $this->staff);
    expect($document->fresh()->number)->toBe('9999 8888 7777');

    $this->documents->edit($document, $type, '', $this->staff);
    expect($document->fresh()->number)->toBeNull()->and($document->fresh()->number_fingerprint)->toBeNull();
});

it('hides on delete and restores unless the type is taken', function () {
    $type = documentType();
    $document = $this->documents->upload($this->account, $type, pngUpload(), null, $this->staff);

    $this->documents->delete($document, $this->staff);
    expect($document->fresh()->trashed())->toBeTrue()->and($document->fresh()->deleted_by)->toBe($this->staff->id);

    $this->documents->restore($document->fresh(), $this->staff);
    expect($document->fresh()->trashed())->toBeFalse();

    $this->documents->delete($document->fresh(), $this->staff);
    $this->documents->upload($this->account, $type, pngUpload(), null, $this->staff);

    expect(fn () => $this->documents->restore($document->fresh(), $this->staff))->toThrow(ValidationException::class);
});

it('erases the file and the row', function () {
    $document = $this->documents->upload($this->account, documentType(), pngUpload(), null, $this->staff);
    $this->documents->delete($document, $this->staff);

    $this->documents->erase($document->fresh(), $this->staff);

    expect(Document::withTrashed()->find($document->id))->toBeNull()
        ->and(Storage::disk('addons')->exists($document->path))->toBeFalse();
});

it('logs each change on the customer without the number', function () {
    $type = documentType();
    $document = $this->documents->upload($this->account, $type, pngUpload(), 'ABCDE1234F', $this->staff);
    $this->documents->upload($this->account, $type, pngUpload(), null, $this->staff);
    $this->documents->delete($document->fresh(), $this->staff);
    $this->documents->erase($document->fresh(), $this->staff);

    $entries = Activity::query()->where('subject_id', $this->account->id)->where('subject_type', $this->account->getMorphClass())->where('event', 'like', 'document-%')->get();

    expect($entries->pluck('event')->all())->toBe(['document-uploaded', 'document-replaced', 'document-deleted', 'document-erased'])
        ->and($entries->pluck('causer_id')->unique()->all())->toBe([$this->staff->id])
        ->and($entries->first()->properties['type'])->toBe('Aadhaar card')
        ->and(json_encode($entries->pluck('properties')))->not->toContain('1234');
});

it('finds a duplicate full number on another customer only', function () {
    $type = documentType();
    $other = Account::factory()->create();
    $this->documents->upload($other, $type, pngUpload(), 'ABCDE1234F', $this->staff);
    $this->documents->upload($this->account, $type, pngUpload(), 'ABCDE1234F', $this->staff);

    expect($this->documents->duplicates($this->account, $type, 'abcde 1234-f')->pluck('id')->all())->toBe([$other->id])
        ->and($this->documents->duplicates($this->account, documentType('Agreement', ['number_mode' => NumberMode::LastFour]), '1234F'))->toBeEmpty();
});

it('lists missing required active types', function () {
    $aadhaar = documentType();
    documentType('Photo');
    documentType('Agreement', ['required' => false]);
    documentType('Old form', ['active' => false]);
    $this->documents->upload($this->account, $aadhaar, pngUpload(), null, $this->staff);

    expect($this->documents->missingTypes($this->account)->pluck('name')->all())->toBe(['Photo']);
});
