<?php

use App\Models\Account;
use App\Models\Role;
use App\Models\User;
use Ispgrid\Documents\Abilities;
use Ispgrid\Documents\CustomerDocuments;
use Ispgrid\Documents\IdNumber;
use Ispgrid\Documents\Models\Document;
use Ispgrid\Documents\Models\DocumentType;
use Ispgrid\Documents\Models\NumberMode;
use Ispgrid\Documents\UploadRequired;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

it('shows the document types on System, then Add-ons, only with the Document types box', function () {
    $this->actingAs(User::factory()->create());
    $this->get(route('addons.index'))->assertOk()->assertSee(__('Add document type'));

    Role::create(['name' => 'Desk', 'guard_name' => 'web'])->givePermissionTo('manage-app-settings');
    $this->actingAs(User::factory()->role('Desk')->create());
    $this->get(route('addons.index'))->assertOk()->assertDontSee(__('Add document type'));
    Livewire::test('documents::settings')->assertForbidden();
});

it('adds and renames a type, keeping names unique and short', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('documents::settings')
        ->set('name', 'Aadhaar card')->set('help', 'Upload a masked Aadhaar')->set('numberMode', 'full')
        ->call('save')->assertHasNoErrors()
        ->set('name', 'Aadhaar card')->call('save')->assertHasErrors(['name'])
        ->set('name', str_repeat('a', 61))->call('save')->assertHasErrors(['name'])
        ->set('name', 'PAN')->set('help', str_repeat('a', 201))->call('save')->assertHasErrors(['help'])
        ->set('numberMode', 'everything')->set('help', '')->call('save')->assertHasErrors(['numberMode']);

    $type = DocumentType::query()->sole();
    expect($type->only(['name', 'help']))->toBe(['name' => 'Aadhaar card', 'help' => 'Upload a masked Aadhaar'])
        ->and($type->number_mode)->toBe(NumberMode::Full);

    Livewire::test('documents::settings')->call('edit', $type->id)->assertSet('name', 'Aadhaar card')
        ->set('name', 'Aadhaar')->call('save')->assertHasNoErrors();

    expect($type->fresh()->name)->toBe('Aadhaar')->and(DocumentType::query()->count())->toBe(1);
});

it('orders the types', function () {
    $this->actingAs(User::factory()->create());
    [$a, $b] = [documentType('A'), documentType('B')];

    Livewire::test('documents::settings')->call('moveUp', $b->id);

    expect(DocumentType::query()->orderBy('position')->pluck('name')->all())->toBe(['B', 'A']);

    Livewire::test('documents::settings')->call('moveDown', $b->id);

    expect(DocumentType::query()->orderBy('position')->pluck('name')->all())->toBe(['A', 'B']);
});

it('deletes an unused type but only switches off a type that has documents', function () {
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $unused = documentType('Unused');
    $used = documentType('Used');
    $document = app(CustomerDocuments::class)->upload(Account::factory()->create(), $used, pngUpload(), null, $staff);
    app(CustomerDocuments::class)->delete($document, $staff);

    Livewire::test('documents::settings')
        ->call('delete', $unused->id)->assertHasNoErrors()
        ->call('delete', $used->id)->assertHasErrors(['types'])
        ->call('toggleActive', $used->id);

    expect(DocumentType::query()->pluck('name')->all())->toBe(['Used'])
        ->and($used->fresh()->active)->toBeFalse()
        ->and(Document::withTrashed()->count())->toBe(1);
});

it('asks before cutting stored full numbers to the last 4, then cuts them all', function () {
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $type = documentType();
    $documents = app(CustomerDocuments::class);
    $old = $documents->upload(Account::factory()->create(), $type, pngUpload(), '1111 2222 3333', $staff);
    $documents->delete($old, $staff);
    $current = $documents->upload(Account::factory()->create(), $type, pngUpload(), '1234 5678 4821', $staff);

    $component = Livewire::test('documents::settings')->call('edit', $type->id)->set('numberMode', 'last4')->call('save')
        ->assertSet('cutCount', 2);

    expect($type->fresh()->number_mode)->toBe(NumberMode::Full);

    $component->call('cutNumbers');

    expect($type->fresh()->number_mode)->toBe(NumberMode::LastFour)
        ->and($current->fresh()->number)->toBe('4821')
        ->and($current->fresh()->number_fingerprint)->toBeNull()
        ->and(Document::withTrashed()->find($old->id)->number)->toBe('3333')
        ->and(Activity::query()->where('event', 'document-numbers-cut')->sole()->properties['count'])->toBe(2);
});

it('cuts stored numbers when a type comes to last 4 by way of none', function () {
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $type = documentType();
    $document = app(CustomerDocuments::class)->upload(Account::factory()->create(), $type, pngUpload(), '1234 5678 4821', $staff);
    $type->update(['number_mode' => NumberMode::None]);

    Livewire::test('documents::settings')->call('edit', $type->id)->set('numberMode', 'last4')->call('save')
        ->assertSet('cutCount', 1)->call('cutNumbers');

    expect($document->fresh()->number)->toBe('4821')->and($type->fresh()->number_mode)->toBe(NumberMode::LastFour);
});

it('does not cut when the choice was changed back after the warning', function () {
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $type = documentType();
    $document = app(CustomerDocuments::class)->upload(Account::factory()->create(), $type, pngUpload(), '1234 5678 4821', $staff);

    Livewire::test('documents::settings')->call('edit', $type->id)->set('numberMode', 'last4')->call('save')
        ->set('numberMode', 'full')->call('cutNumbers');

    expect($document->fresh()->number)->toBe('1234 5678 4821');
});

it('keeps stored numbers when a type stops taking numbers', function () {
    $staff = User::factory()->create();
    $this->actingAs($staff);
    $type = documentType();
    $document = app(CustomerDocuments::class)->upload(Account::factory()->create(), $type, pngUpload(), '1234 5678 4821', $staff);

    Livewire::test('documents::settings')->call('edit', $type->id)->set('numberMode', 'none')->call('save')->assertSet('cutCount', 0);

    expect($type->fresh()->number_mode)->toBe(NumberMode::None)
        ->and($document->fresh()->number)->toBe('1234 5678 4821')
        ->and(IdNumber::fingerprint('1234 5678 4821'))->toBe($document->fresh()->number_fingerprint);
});

it('stops an open settings section once the box is taken away', function () {
    $staff = staffWith(Abilities::TYPES);
    $this->actingAs($staff);
    $component = Livewire::test('documents::settings')->assertOk();

    revokeBox($staff, Abilities::TYPES);

    $component->set('name', 'Sneaky')->assertForbidden();
    expect(DocumentType::query()->count())->toBe(0);
});

it('switches Document upload is required on and off', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('documents::settings')->assertSet('uploadRequired', false)->set('uploadRequired', true);
    expect(UploadRequired::isOn())->toBeTrue();

    Livewire::test('documents::settings')->assertSet('uploadRequired', true)->set('uploadRequired', false);
    expect(UploadRequired::isOn())->toBeFalse();
});

it('refuses the switch once the Document types box is taken away', function () {
    $staff = staffWith(Abilities::TYPES);
    $this->actingAs($staff);
    $settings = Livewire::test('documents::settings');

    revokeBox($staff, Abilities::TYPES);

    $settings->set('uploadRequired', true)->assertForbidden();
    expect(UploadRequired::isOn())->toBeFalse();
});
