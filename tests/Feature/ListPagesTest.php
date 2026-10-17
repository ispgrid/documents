<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Ispgrid\Documents\Abilities;
use Ispgrid\Documents\CustomerDocuments;
use Ispgrid\Documents\Models\Document;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->documents = app(CustomerDocuments::class);
    $this->aadhaar = documentType();
});

it('lists hidden documents newest first, with search', function () {
    $this->actingAs($this->admin);
    $ramesh = Account::factory()->create(['fullname' => 'Ramesh Kumar', 'username' => 'ramesh']);
    $sita = Account::factory()->create(['fullname' => 'Sita Devi', 'username' => 'sita']);
    $first = $this->documents->upload($ramesh, $this->aadhaar, pngUpload(), null, $this->admin);
    $this->documents->delete($first, $this->admin);
    $this->travel(1)->minute();
    $second = $this->documents->upload($sita, $this->aadhaar, pngUpload(), null, $this->admin);
    $this->documents->delete($second, $this->admin);

    $this->get(route('documents.deleted'))->assertOk()->assertSeeInOrder(['Sita Devi', 'Ramesh Kumar'])
        ->assertSee(__('Backups taken before an erase still hold the file for up to 14 days.'));

    Livewire::test('documents::deleted')->set('search', 'rame')->assertSee('Ramesh Kumar')->assertDontSee('Sita Devi');
});

it('restores a hidden document unless its type is taken again', function () {
    $this->actingAs(staffWith(Abilities::RESTORE));
    $account = Account::factory()->create();
    $document = $this->documents->upload($account, $this->aadhaar, pngUpload(), null, $this->admin);
    $this->documents->delete($document, $this->admin);

    Livewire::test('documents::deleted')->call('restore', $document->id)->assertHasNoErrors();
    expect($document->fresh()->trashed())->toBeFalse();

    $this->documents->upload($account, $this->aadhaar, pngUpload(), null, $this->admin);

    Livewire::test('documents::deleted')->call('restore', $document->id)->assertHasErrors(['document']);
    expect(Activity::query()->where('event', 'document-restored')->count())->toBe(1);
});

it('erases for good only with Erase deleted documents', function () {
    $document = $this->documents->upload(Account::factory()->create(), $this->aadhaar, pngUpload(), null, $this->admin);
    $this->documents->delete($document, $this->admin);

    $this->actingAs(staffWith(Abilities::RESTORE));
    Livewire::test('documents::deleted')->assertDontSee(__('Erase for good'))->call('erase', $document->id)->assertForbidden();

    $this->actingAs(staffWith(Abilities::ERASE));
    Livewire::test('documents::deleted')->assertSee(__('Erase for good'))->call('erase', $document->id)->assertHasNoErrors();

    expect(Document::withTrashed()->count())->toBe(0)
        ->and(Storage::disk('addons')->allFiles())->toBe([])
        ->and(Activity::query()->where('event', 'document-erased')->count())->toBe(1);
});

it('never restores or erases a current document from this page', function () {
    $this->actingAs($this->admin);
    $document = $this->documents->upload(Account::factory()->create(), $this->aadhaar, pngUpload(), null, $this->admin);

    Livewire::test('documents::deleted')->call('erase', $document->id)->assertNotFound();
    expect($document->fresh())->not->toBeNull();
});

it('needs Restore deleted documents for the page', function () {
    $this->actingAs(staffWith(Abilities::VIEW, Abilities::EDIT));

    $this->get(route('documents.deleted'))->assertForbidden();
});

it('lists customers missing a required active document', function () {
    $this->actingAs(staffWith(Abilities::VIEW));
    $photo = documentType('Customer photo');
    documentType('Agreement', ['required' => false]);
    documentType('Old form', ['active' => false]);
    $complete = Account::factory()->create(['fullname' => 'Complete Customer']);
    $this->documents->upload($complete, $this->aadhaar, pngUpload(), null, $this->admin);
    $this->documents->upload($complete, $photo, pngUpload(), null, $this->admin);
    $half = Account::factory()->create(['fullname' => 'Half Customer']);
    $this->documents->upload($half, $this->aadhaar, pngUpload(), null, $this->admin);
    Account::factory()->create(['fullname' => 'Empty Customer']);
    Account::factory()->create(['fullname' => 'Gone Customer'])->delete();

    $this->get(route('documents.missing'))->assertOk()
        ->assertSee('Half Customer')->assertSee('Empty Customer')
        ->assertDontSee('Complete Customer')->assertDontSee('Gone Customer')->assertDontSee('Old form');

    Livewire::test('documents::missing')->set('search', 'half')
        ->assertSee('Half Customer')->assertSee('Customer photo')->assertDontSee('Empty Customer');
});

it('needs View customer documents for the missing list', function () {
    $this->actingAs(staffWith(Abilities::UPLOAD));

    $this->get(route('documents.missing'))->assertForbidden();
});

it('shows both pages in the Add-ons menu to staff who may open them', function () {
    $this->actingAs(staffWith(Abilities::VIEW));
    $this->get(route('dashboard'))->assertSee(route('documents.missing'))->assertDontSee(route('documents.deleted'));

    $this->actingAs($this->admin);
    $this->get(route('dashboard'))->assertSee(route('documents.missing'))->assertSee(route('documents.deleted'));
});
